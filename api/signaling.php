<?php
/**
 * ConnectMeet - WebRTC Signaling API (Offer/Answer/ICE Candidates)
 */

define('IS_API_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = Database::getConnection();
$payload = getJsonInput();
$action = $_GET['action'] ?? $payload['action'] ?? '';

ensureSignalsTableExists($pdo);

switch ($action) {
    case 'send':
        handleSendSignal($pdo, $payload);
        break;

    case 'poll':
        handlePollSignals($pdo, $payload);
        break;

    default:
        sendError('Invalid action specified for signaling API', 400);
}

/**
 * Send WebRTC signal (SDP offer, answer, or ICE candidate)
 */
function handleSendSignal(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $fromId = (int)($payload['from_participant_id'] ?? 0);
    $toId = (int)($payload['to_participant_id'] ?? 0);
    $token = $payload['participant_token'] ?? '';
    $signalType = $payload['signal_type'] ?? '';
    $signalPayload = $payload['signal_payload'] ?? null;

    if (!in_array($signalType, ['offer', 'answer', 'candidate'], true)) {
        sendError('Invalid signal type. Must be offer, answer, or candidate.', 400);
    }

    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
        sendError('Invalid sender or recipient participant ID.', 400);
    }

    $participant = verifyParticipant($pdo, $meetingId, $fromId, $token);
    if (!$participant) {
        sendError('Unauthorized sender participant session', 401);
    }

    // Verify recipient belongs to the same meeting
    $toStmt = $pdo->prepare("
        SELECT id FROM meeting_participants
        WHERE id = :to_id AND meeting_id = :meeting_id
        LIMIT 1
    ");
    $toStmt->execute([':to_id' => $toId, ':meeting_id' => $meetingId]);
    if (!$toStmt->fetch()) {
        sendError('Recipient participant is not in this meeting.', 404);
    }

    $payloadStr = is_string($signalPayload) ? $signalPayload : json_encode($signalPayload);

    $stmt = $pdo->prepare("
        INSERT INTO meeting_signals
        (meeting_id, from_participant_id, to_participant_id, signal_type, signal_payload, is_delivered, created_at)
        VALUES
        (:meeting_id, :from_id, :to_id, :type, :payload, 0, NOW())
    ");

    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':from_id' => $fromId,
        ':to_id' => $toId,
        ':type' => $signalType,
        ':payload' => $payloadStr
    ]);

    sendSuccess([
        'signal_id' => (int)$pdo->lastInsertId(),
        'signal_type' => $signalType,
        'to_participant_id' => $toId
    ], 'Signal dispatched successfully');
}

/**
 * Poll incoming signals destined for this participant
 */
function handlePollSignals(PDO $pdo, array $payload): void {
    $meetingId = (int)($_GET['meeting_id'] ?? $payload['meeting_id'] ?? 0);
    $participantId = (int)($_GET['participant_id'] ?? $payload['participant_id'] ?? 0);
    $token = $_GET['participant_token'] ?? $payload['participant_token'] ?? '';

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session', 401);
    }

    // Fetch undelivered signals targeted to this participant
    $stmt = $pdo->prepare("
        SELECT id, from_participant_id, signal_type, signal_payload, created_at
        FROM meeting_signals
        WHERE meeting_id = :meeting_id
          AND to_participant_id = :to_id
          AND is_delivered = 0
        ORDER BY id ASC
        LIMIT 30
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':to_id' => $participantId
    ]);
    $signals = $stmt->fetchAll();

    if (!empty($signals)) {
        $signalIds = array_column($signals, 'id');
        $inQuery = implode(',', array_map('intval', $signalIds));
        
        // Mark as delivered atomically
        $pdo->exec("UPDATE meeting_signals SET is_delivered = 1 WHERE id IN ($inQuery)");

        foreach ($signals as &$sig) {
            $sig['id'] = (int)$sig['id'];
            $sig['from_participant_id'] = (int)$sig['from_participant_id'];
            $sig['signal_payload'] = json_decode($sig['signal_payload'], true);
        }
    }

    sendSuccess([
        'count' => count($signals),
        'signals' => $signals
    ]);
}

/**
 * Self-healing check: guarantee meeting_signals table exists even if user imported webrtc_signals
 */
function ensureSignalsTableExists(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo->query("SELECT 1 FROM meeting_signals LIMIT 1");
    } catch (\PDOException $e) {
        try {
            // Check if webrtc_signals was imported
            $pdo->query("SELECT 1 FROM webrtc_signals LIMIT 1");
            $pdo->exec("CREATE TABLE IF NOT EXISTS meeting_signals LIKE webrtc_signals");
        } catch (\PDOException $e2) {
            // Neither exists, create meeting_signals with standard structure
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `meeting_signals` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `meeting_id` INT UNSIGNED NOT NULL,
                    `from_participant_id` INT UNSIGNED NOT NULL,
                    `to_participant_id` INT UNSIGNED NOT NULL,
                    `signal_type` ENUM('offer', 'answer', 'candidate') NOT NULL,
                    `signal_payload` LONGTEXT NOT NULL,
                    `is_delivered` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_signals_target` (`meeting_id`, `to_participant_id`, `is_delivered`, `id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    }
}
