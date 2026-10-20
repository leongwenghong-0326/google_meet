<?php
/**
 * ConnectMeet - Meeting Management API
 */

define('IS_API_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Support JSON body
$payload = getJsonInput();
if (!empty($payload['action'])) {
    $action = $payload['action'];
}

switch ($action) {
    case 'create':
        handleCreateMeeting($pdo, $appConfig, $payload);
        break;

    case 'validate':
        handleValidateMeeting($pdo, $payload);
        break;

    case 'details':
        handleGetMeetingDetails($pdo);
        break;

    case 'toggle_lock':
        handleToggleLock($pdo, $payload);
        break;

    case 'end_meeting':
        handleEndMeeting($pdo, $payload);
        break;

    default:
        sendError('Invalid action specified for meetings API', 400);
}

/**
 * Handle meeting creation (Instant or Scheduled)
 */
function handleCreateMeeting(PDO $pdo, array $appConfig, array $payload): void {
    $title = sanitizeInput($payload['title'] ?? 'ConnectMeet Session', 100);
    $participantLimit = (int)($payload['participant_limit'] ?? $appConfig['meeting']['default_participant_limit'] ?? 8);
    if ($participantLimit < 2 || $participantLimit > 20) {
        $participantLimit = 8;
    }

    $scheduledAt = null;
    if (!empty($payload['scheduled_at'])) {
        $timestamp = strtotime($payload['scheduled_at']);
        if ($timestamp !== false && $timestamp > time()) {
            $scheduledAt = date('Y-m-d H:i:s', $timestamp);
        }
    }

    // Generate unique meeting code
    $attempts = 0;
    $meetingCode = '';
    do {
        $meetingCode = generateMeetingCode();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE meeting_code = :code");
        $stmt->execute([':code' => $meetingCode]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $attempts++;
    } while ($exists && $attempts < 10);

    if ($exists) {
        sendError('Unable to generate unique meeting code. Please try again.', 500);
    }

    $hostToken = generateSecureToken();
    $hostTokenHash = hashToken($hostToken);

    $stmt = $pdo->prepare("
        INSERT INTO meetings (meeting_code, host_token_hash, title, status, scheduled_at, participant_limit, created_at, updated_at)
        VALUES (:code, :host_token_hash, :title, 'active', :scheduled_at, :limit, NOW(), NOW())
    ");

    $stmt->execute([
        ':code' => $meetingCode,
        ':host_token_hash' => $hostTokenHash,
        ':title' => $title,
        ':scheduled_at' => $scheduledAt,
        ':limit' => $participantLimit
    ]);

    $meetingId = (int)$pdo->lastInsertId();

    $baseUrl = getAppBaseUrl($appConfig['app']['base_url'] ?? '');
    $joinUrl = "{$baseUrl}/room.php?meeting={$meetingCode}";

    sendSuccess([
        'meeting_id' => $meetingId,
        'meeting_code' => $meetingCode,
        'host_token' => $hostToken,
        'title' => $title,
        'join_url' => $joinUrl,
        'scheduled_at' => $scheduledAt,
        'participant_limit' => $participantLimit
    ], 'Meeting created successfully', 201);
}

/**
 * Handle meeting code validation
 */
function handleValidateMeeting(PDO $pdo, array $payload): void {
    $codeRaw = $_GET['meeting_code'] ?? $payload['meeting_code'] ?? '';
    $meetingCode = normalizeMeetingCode($codeRaw);

    if (!isValidMeetingCode($meetingCode)) {
        sendError('Invalid meeting code format. Format must be abc-defg-hij', 400, [
            'valid' => false,
            'code' => $meetingCode
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT id, meeting_code, title, status, is_locked, participant_limit, scheduled_at, created_at
        FROM meetings
        WHERE meeting_code = :code
        LIMIT 1
    ");
    $stmt->execute([':code' => $meetingCode]);
    $meeting = $stmt->fetch();

    if (!$meeting) {
        sendError('Meeting not found. Please verify the code and try again.', 404, [
            'valid' => false,
            'exists' => false
        ]);
    }

    if ($meeting['status'] === 'ended') {
        sendError('This meeting has already ended.', 410, [
            'valid' => false,
            'status' => 'ended'
        ]);
    }

    // Check online participants count
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM meeting_participants
        WHERE meeting_id = :meeting_id AND is_online = 1
    ");
    $countStmt->execute([':meeting_id' => $meeting['id']]);
    $onlineCount = (int)$countStmt->fetchColumn();

    $isFull = $onlineCount >= (int)$meeting['participant_limit'];

    sendSuccess([
        'valid' => true,
        'meeting_id' => (int)$meeting['id'],
        'meeting_code' => $meeting['meeting_code'],
        'title' => $meeting['title'],
        'status' => $meeting['status'],
        'is_locked' => (bool)$meeting['is_locked'],
        'is_full' => $isFull,
        'online_participants' => $onlineCount,
        'participant_limit' => (int)$meeting['participant_limit']
    ], 'Meeting code is valid');
}

/**
 * Get meeting details
 */
function handleGetMeetingDetails(PDO $pdo): void {
    $codeRaw = $_GET['meeting_code'] ?? '';
    $meetingCode = normalizeMeetingCode($codeRaw);

    $stmt = $pdo->prepare("
        SELECT id, meeting_code, title, status, is_locked, participant_limit, created_at
        FROM meetings
        WHERE meeting_code = :code
        LIMIT 1
    ");
    $stmt->execute([':code' => $meetingCode]);
    $meeting = $stmt->fetch();

    if (!$meeting) {
        sendError('Meeting not found', 404);
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM meeting_participants
        WHERE meeting_id = :meeting_id AND is_online = 1
    ");
    $countStmt->execute([':meeting_id' => $meeting['id']]);
    $onlineCount = (int)$countStmt->fetchColumn();

    sendSuccess([
        'meeting_id' => (int)$meeting['id'],
        'meeting_code' => $meeting['meeting_code'],
        'title' => $meeting['title'],
        'status' => $meeting['status'],
        'is_locked' => (bool)$meeting['is_locked'],
        'online_participants' => $onlineCount,
        'participant_limit' => (int)$meeting['participant_limit']
    ]);
}

/**
 * Handle meeting lock/unlock toggle
 */
function handleToggleLock(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $hostToken = $payload['host_token'] ?? '';
    $lockState = !empty($payload['is_locked']) ? 1 : 0;

    if (!verifyHost($pdo, $meetingId, $hostToken)) {
        sendError('Unauthorized: Only the meeting host can lock or unlock the meeting.', 403);
    }

    $stmt = $pdo->prepare("UPDATE meetings SET is_locked = :lock_state WHERE id = :id");
    $stmt->execute([':lock_state' => $lockState, ':id' => $meetingId]);

    recordMeetingEvent($pdo, $meetingId, null, 'lock_changed', [
        'is_locked' => (bool)$lockState
    ]);

    sendSuccess([
        'is_locked' => (bool)$lockState
    ], $lockState ? 'Meeting has been locked' : 'Meeting has been unlocked');
}

/**
 * Handle end meeting for everyone
 */
function handleEndMeeting(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $hostToken = $payload['host_token'] ?? '';
    $participantId = (int)($payload['participant_id'] ?? 0);
    $participantToken = $payload['participant_token'] ?? '';

    $isAuthorized = false;
    if (!empty($hostToken) && verifyHost($pdo, $meetingId, $hostToken)) {
        $isAuthorized = true;
    } elseif ($participantId > 0 && !empty($participantToken)) {
        $p = verifyParticipant($pdo, $meetingId, $participantId, $participantToken);
        if ($p && $p['role'] === 'host') {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized) {
        sendError('Unauthorized: Only the meeting host can end the meeting for all participants.', 403);
    }

    $stmt = $pdo->prepare("UPDATE meetings SET status = 'ended' WHERE id = :id");
    $stmt->execute([':id' => $meetingId]);

    // Mark all participants as left
    $partStmt = $pdo->prepare("
        UPDATE meeting_participants
        SET is_online = 0, left_at = NOW()
        WHERE meeting_id = :meeting_id AND is_online = 1
    ");
    $partStmt->execute([':meeting_id' => $meetingId]);

    // Broadcast meeting_ended event
    recordMeetingEvent($pdo, $meetingId, null, 'meeting_ended', [
        'reason' => 'Meeting ended by host'
    ]);

    sendSuccess(null, 'Meeting has been successfully ended for everyone');
}
