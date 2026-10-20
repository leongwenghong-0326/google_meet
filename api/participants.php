<?php
/**
 * ConnectMeet - Participants Management API
 */

define('IS_API_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = Database::getConnection();
$payload = getJsonInput();
$action = $_GET['action'] ?? $payload['action'] ?? '';

switch ($action) {
    case 'join':
        handleJoinMeeting($pdo, $appConfig, $payload);
        break;

    case 'heartbeat':
        handleHeartbeat($pdo, $payload);
        break;

    case 'update_state':
        handleUpdateState($pdo, $payload);
        break;

    case 'leave':
        handleLeaveMeeting($pdo, $payload);
        break;

    case 'kick':
        handleKickParticipant($pdo, $payload);
        break;

    default:
        sendError('Invalid action specified for participants API', 400);
}

/**
 * Handle participant joining a meeting
 */
function handleJoinMeeting(PDO $pdo, array $appConfig, array $payload): void {
    $codeRaw = $payload['meeting_code'] ?? '';
    $meetingCode = normalizeMeetingCode($codeRaw);
    $displayName = sanitizeInput($payload['display_name'] ?? 'Guest', 50);
    $audioEnabled = !empty($payload['audio_enabled']) ? 1 : 0;
    $videoEnabled = !empty($payload['video_enabled']) ? 1 : 0;
    $hostToken = $payload['host_token'] ?? null;

    if (empty($displayName)) {
        $displayName = 'Guest-' . random_int(100, 999);
    }

    if (!isValidMeetingCode($meetingCode)) {
        sendError('Invalid meeting code format', 400);
    }

    // Fetch meeting details
    $stmt = $pdo->prepare("
        SELECT id, meeting_code, title, status, host_token_hash, is_locked, participant_limit
        FROM meetings
        WHERE meeting_code = :code
        LIMIT 1
    ");
    $stmt->execute([':code' => $meetingCode]);
    $meeting = $stmt->fetch();

    if (!$meeting) {
        sendError('Meeting not found', 404);
    }

    if ($meeting['status'] === 'ended') {
        sendError('This meeting has already ended', 410);
    }

    // Determine role (host or participant)
    $isHost = false;
    if (!empty($hostToken) && hash_equals($meeting['host_token_hash'], hashToken($hostToken))) {
        $isHost = true;
    }

    // If meeting is locked, non-hosts cannot enter
    if ($meeting['is_locked'] && !$isHost) {
        sendError('This meeting has been locked by the host. New participants cannot join.', 403);
    }

    // Check participant limit
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM meeting_participants
        WHERE meeting_id = :meeting_id AND is_online = 1
    ");
    $countStmt->execute([':meeting_id' => $meeting['id']]);
    $onlineCount = (int)$countStmt->fetchColumn();

    if ($onlineCount >= (int)$meeting['participant_limit'] && !$isHost) {
        sendError('This meeting has reached its participant capacity limit.', 403);
    }

    // Create participant record
    $participantToken = generateSecureToken();
    $tokenHash = hashToken($participantToken);
    $role = $isHost ? 'host' : 'participant';

    $insertStmt = $pdo->prepare("
        INSERT INTO meeting_participants
        (meeting_id, participant_token_hash, display_name, role, audio_enabled, video_enabled, is_online, joined_at, last_seen_at)
        VALUES
        (:meeting_id, :token_hash, :display_name, :role, :audio, :video, 1, NOW(), NOW())
    ");

    $insertStmt->execute([
        ':meeting_id' => $meeting['id'],
        ':token_hash' => $tokenHash,
        ':display_name' => $displayName,
        ':role' => $role,
        ':audio' => $audioEnabled,
        ':video' => $videoEnabled
    ]);

    $participantId = (int)$pdo->lastInsertId();

    // Broadcast participant_joined event
    recordMeetingEvent($pdo, (int)$meeting['id'], $participantId, 'participant_joined', [
        'id' => $participantId,
        'display_name' => $displayName,
        'role' => $role,
        'audio_enabled' => (bool)$audioEnabled,
        'video_enabled' => (bool)$videoEnabled
    ]);

    // Fetch list of current active peers in the room (excluding this new participant)
    $peersStmt = $pdo->prepare("
        SELECT id, display_name, role, audio_enabled, video_enabled, screen_sharing, hand_raised, joined_at
        FROM meeting_participants
        WHERE meeting_id = :meeting_id
          AND is_online = 1
          AND id != :me
        ORDER BY id ASC
    ");
    $peersStmt->execute([
        ':meeting_id' => $meeting['id'],
        ':me' => $participantId
    ]);
    $existingPeers = $peersStmt->fetchAll();

    // Cast booleans
    foreach ($existingPeers as &$peer) {
        $peer['id'] = (int)$peer['id'];
        $peer['audio_enabled'] = (bool)$peer['audio_enabled'];
        $peer['video_enabled'] = (bool)$peer['video_enabled'];
        $peer['screen_sharing'] = (bool)$peer['screen_sharing'];
        $peer['hand_raised'] = (bool)$peer['hand_raised'];
    }

    sendSuccess([
        'meeting_id' => (int)$meeting['id'],
        'meeting_code' => $meeting['meeting_code'],
        'title' => $meeting['title'],
        'participant_id' => $participantId,
        'participant_token' => $participantToken,
        'display_name' => $displayName,
        'role' => $role,
        'is_host' => $isHost,
        'is_locked' => (bool)$meeting['is_locked'],
        'existing_peers' => $existingPeers,
        'ice_servers' => $appConfig['webrtc']['ice_servers']
    ], 'Joined meeting successfully');
}

/**
 * Handle heartbeat and participant list synchronization
 */
function handleHeartbeat(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $participantId = (int)($payload['participant_id'] ?? 0);
    $token = $payload['participant_token'] ?? '';

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session or expired', 401);
    }

    // If meeting is already ended, notify participant immediately
    if (($participant['meeting_status'] ?? '') === 'ended') {
        sendSuccess([
            'meeting_id' => $meetingId,
            'meeting_status' => 'ended',
            'participants' => []
        ], 'The meeting has been ended by the host');
        return;
    }

    // Update last_seen_at
    $updateStmt = $pdo->prepare("
        UPDATE meeting_participants
        SET last_seen_at = NOW(), is_online = 1
        WHERE id = :id
    ");
    $updateStmt->execute([':id' => $participantId]);

    // Fetch all active participants
    $activeStmt = $pdo->prepare("
        SELECT id, display_name, role, audio_enabled, video_enabled, screen_sharing, hand_raised
        FROM meeting_participants
        WHERE meeting_id = :meeting_id
          AND is_online = 1
        ORDER BY id ASC
    ");
    $activeStmt->execute([':meeting_id' => $meetingId]);
    $participants = $activeStmt->fetchAll();

    foreach ($participants as &$p) {
        $p['id'] = (int)$p['id'];
        $p['audio_enabled'] = (bool)$p['audio_enabled'];
        $p['video_enabled'] = (bool)$p['video_enabled'];
        $p['screen_sharing'] = (bool)$p['screen_sharing'];
        $p['hand_raised'] = (bool)$p['hand_raised'];
    }

    sendSuccess([
        'meeting_id' => $meetingId,
        'participants' => $participants,
        'server_timestamp' => time()
    ]);
}

/**
 * Update media, screen share, or hand-raised state
 */
function handleUpdateState(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $participantId = (int)($payload['participant_id'] ?? 0);
    $token = $payload['participant_token'] ?? '';

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session', 401);
    }

    $updates = [];
    $params = [':id' => $participantId];
    $eventData = ['participant_id' => $participantId];

    if (isset($payload['audio_enabled'])) {
        $audio = !empty($payload['audio_enabled']) ? 1 : 0;
        $updates[] = "audio_enabled = :audio";
        $params[':audio'] = $audio;
        $eventData['audio_enabled'] = (bool)$audio;
    }

    if (isset($payload['video_enabled'])) {
        $video = !empty($payload['video_enabled']) ? 1 : 0;
        $updates[] = "video_enabled = :video";
        $params[':video'] = $video;
        $eventData['video_enabled'] = (bool)$video;
    }

    if (isset($payload['screen_sharing'])) {
        $screen = !empty($payload['screen_sharing']) ? 1 : 0;
        $updates[] = "screen_sharing = :screen";
        $params[':screen'] = $screen;
        $eventData['screen_sharing'] = (bool)$screen;
    }

    if (isset($payload['hand_raised'])) {
        $hand = !empty($payload['hand_raised']) ? 1 : 0;
        $updates[] = "hand_raised = :hand";
        $params[':hand'] = $hand;
        $eventData['hand_raised'] = (bool)$hand;
    }

    if (!empty($updates)) {
        $sql = "UPDATE meeting_participants SET " . implode(', ', $updates) . ", last_seen_at = NOW() WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Broadcast participant_state_changed event
        recordMeetingEvent($pdo, $meetingId, $participantId, 'participant_state_changed', $eventData);
    }

    sendSuccess($eventData, 'Participant state updated successfully');
}

/**
 * Handle voluntary participant departure
 */
function handleLeaveMeeting(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $participantId = (int)($payload['participant_id'] ?? 0);
    $token = $payload['participant_token'] ?? '';

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session', 401);
    }

    $stmt = $pdo->prepare("
        UPDATE meeting_participants
        SET is_online = 0, left_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $participantId]);

    // Broadcast participant_left event
    recordMeetingEvent($pdo, $meetingId, $participantId, 'participant_left', [
        'id' => $participantId,
        'display_name' => $participant['display_name']
    ]);

    sendSuccess(null, 'Left meeting successfully');
}

/**
 * Handle host kicking a participant
 */
function handleKickParticipant(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $targetId = (int)($payload['target_participant_id'] ?? 0);
    $hostToken = $payload['host_token'] ?? '';

    if (!verifyHost($pdo, $meetingId, $hostToken)) {
        sendError('Unauthorized: Only meeting hosts can remove participants.', 403);
    }

    if ($targetId <= 0) {
        sendError('Invalid target participant ID', 400);
    }

    // Remove target
    $stmt = $pdo->prepare("
        UPDATE meeting_participants
        SET is_online = 0, left_at = NOW()
        WHERE id = :id AND meeting_id = :meeting_id
    ");
    $stmt->execute([':id' => $targetId, ':meeting_id' => $meetingId]);

    // Broadcast participant_kicked event
    recordMeetingEvent($pdo, $meetingId, $targetId, 'participant_kicked', [
        'target_participant_id' => $targetId
    ]);

    sendSuccess(null, 'Participant removed from meeting');
}
