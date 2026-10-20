<?php
/**
 * ConnectMeet - Meeting Events API (Presence, Reactions, Captions, Room Status)
 */

define('IS_API_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = Database::getConnection();
$payload = getJsonInput();
$action = $_GET['action'] ?? $payload['action'] ?? '';

switch ($action) {
    case 'poll':
        handlePollEvents($pdo, $payload);
        break;

    case 'send_reaction':
        handleSendReaction($pdo, $payload);
        break;

    case 'send_caption':
        handleSendCaption($pdo, $payload);
        break;

    default:
        sendError('Invalid action specified for events API', 400);
}

/**
 * Poll meeting events incrementally
 */
function handlePollEvents(PDO $pdo, array $payload): void {
    $meetingId = (int)($_GET['meeting_id'] ?? $payload['meeting_id'] ?? 0);
    $participantId = (int)($_GET['participant_id'] ?? $payload['participant_id'] ?? 0);
    $token = $_GET['participant_token'] ?? $payload['participant_token'] ?? '';
    $lastEventId = (int)($_GET['last_event_id'] ?? $payload['last_event_id'] ?? 0);

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session', 401);
    }

    $stmt = $pdo->prepare("
        SELECT id, meeting_id, participant_id, event_type, event_payload, created_at
        FROM meeting_events
        WHERE meeting_id = :meeting_id
          AND id > :last_event_id
        ORDER BY id ASC
        LIMIT 50
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':last_event_id' => $lastEventId
    ]);
    $events = $stmt->fetchAll();

    $newLastId = $lastEventId;
    foreach ($events as &$evt) {
        $evt['id'] = (int)$evt['id'];
        $evt['meeting_id'] = (int)$evt['meeting_id'];
        $evt['participant_id'] = $evt['participant_id'] ? (int)$evt['participant_id'] : null;
        $evt['event_payload'] = json_decode($evt['event_payload'], true);
        if ($evt['id'] > $newLastId) {
            $newLastId = $evt['id'];
        }
    }

    sendSuccess([
        'last_event_id' => $newLastId,
        'count' => count($events),
        'events' => $events
    ]);
}

/**
 * Broadcast emoji reaction
 */
function handleSendReaction(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $participantId = (int)($payload['participant_id'] ?? 0);
    $token = $payload['participant_token'] ?? '';
    $emoji = sanitizeInput($payload['emoji'] ?? '👍', 10);

    $allowedEmojis = ['👍', '👏', '❤️', '🎉', '😂', '😮', '🔥', '✋'];
    if (!in_array($emoji, $allowedEmojis, true)) {
        $emoji = '👍';
    }

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session', 401);
    }

    $eventId = recordMeetingEvent($pdo, $meetingId, $participantId, 'reaction', [
        'participant_id' => $participantId,
        'display_name' => $participant['display_name'],
        'emoji' => $emoji
    ]);

    sendSuccess([
        'event_id' => $eventId,
        'emoji' => $emoji
    ], 'Reaction sent');
}

/**
 * Broadcast live speech-to-text caption
 */
function handleSendCaption(PDO $pdo, array $payload): void {
    $meetingId = (int)($payload['meeting_id'] ?? 0);
    $participantId = (int)($payload['participant_id'] ?? 0);
    $token = $payload['participant_token'] ?? '';
    $text = sanitizeInput($payload['caption_text'] ?? '', 300);

    if (empty($text)) {
        sendError('Caption text cannot be empty', 400);
    }

    $participant = verifyParticipant($pdo, $meetingId, $participantId, $token);
    if (!$participant) {
        sendError('Unauthorized participant session', 401);
    }

    $eventId = recordMeetingEvent($pdo, $meetingId, $participantId, 'caption', [
        'participant_id' => $participantId,
        'display_name' => $participant['display_name'],
        'text' => $text,
        'time' => date('H:i:s')
    ]);

    sendSuccess(['event_id' => $eventId], 'Caption broadcasted');
}
