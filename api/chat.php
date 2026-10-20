<?php
/**
 * ConnectMeet - In-Meeting Chat API
 */

define('IS_API_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = Database::getConnection();
$payload = getJsonInput();
$action = $_GET['action'] ?? $payload['action'] ?? '';

switch ($action) {
    case 'send':
        handleSendMessage($pdo, $appConfig, $payload);
        break;

    case 'poll':
        handlePollMessages($pdo, $payload);
        break;

    default:
        sendError('Invalid action specified for chat API', 400);
}

/**
 * Handle sending chat message
 */
function handleSendMessage(PDO $pdo, array $appConfig, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $participantId = (int)($payload['participant_id'] ?? 0);
    $token = $payload['participant_token'] ?? '';
    $rawMessage = trim($payload['message'] ?? '');

    if ($rawMessage === '') {
        sendError('Message content cannot be empty', 400);
    }

    $maxLen = (int)($appConfig['meeting']['max_chat_length'] ?? 1000);
    if (mb_strlen($rawMessage, 'UTF-8') > $maxLen) {
        sendError("Message exceeds maximum length of {$maxLen} characters", 400);
    }

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session', 401);
    }

    // Rate limiting check: max messages per minute
    $rateLimit = (int)($appConfig['meeting']['chat_rate_limit_per_minute'] ?? 30);
    $rateStmt = $pdo->prepare("
        SELECT COUNT(*) FROM meeting_messages
        WHERE participant_id = :part_id AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $rateStmt->execute([':part_id' => $participantId]);
    if ((int)$rateStmt->fetchColumn() >= $rateLimit) {
        sendError('Rate limit exceeded. Please wait a moment before sending more messages.', 429);
    }

    // Clean and escape message
    $message = sanitizeInput($rawMessage, $maxLen);

    $stmt = $pdo->prepare("
        INSERT INTO meeting_messages (meeting_id, participant_id, message_type, message_content, created_at)
        VALUES (:meeting_id, :participant_id, 'text', :content, NOW())
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':participant_id' => $participantId,
        ':content' => $message
    ]);

    $messageId = (int)$pdo->lastInsertId();

    sendSuccess([
        'message_id' => $messageId,
        'participant_id' => $participantId,
        'display_name' => $participant['display_name'],
        'role' => $participant['role'],
        'content' => $message,
        'created_at' => date('H:i')
    ], 'Message sent successfully');
}

/**
 * Handle polling messages incrementally
 */
function handlePollMessages(PDO $pdo, array $payload): void {
    $meetingId = (int)($_GET['meeting_id'] ?? $payload['meeting_id'] ?? 0);
    $participantId = (int)($_GET['participant_id'] ?? $payload['participant_id'] ?? 0);
    $token = $_GET['participant_token'] ?? $payload['participant_token'] ?? '';
    $lastMsgId = (int)($_GET['last_message_id'] ?? $payload['last_message_id'] ?? 0);

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session', 401);
    }

    $stmt = $pdo->prepare("
        SELECT m.id, m.participant_id, m.message_content, m.created_at,
               p.display_name, p.role
        FROM meeting_messages m
        JOIN meeting_participants p ON m.participant_id = p.id
        WHERE m.meeting_id = :meeting_id
          AND m.id > :last_id
        ORDER BY m.id ASC
        LIMIT 50
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':last_id' => $lastMsgId
    ]);
    $messages = $stmt->fetchAll();

    $newLastId = $lastMsgId;
    foreach ($messages as &$msg) {
        $msg['id'] = (int)$msg['id'];
        $msg['participant_id'] = (int)$msg['participant_id'];
        $msg['time_formatted'] = date('H:i', strtotime($msg['created_at']));
        if ($msg['id'] > $newLastId) {
            $newLastId = $msg['id'];
        }
    }

    sendSuccess([
        'last_message_id' => $newLastId,
        'count' => count($messages),
        'messages' => $messages
    ]);
}
