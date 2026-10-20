<?php
/**
 * ConnectMeet - Authentication & Authorization Checks
 */

if (!defined('CONNECTMEET_ACCESS')) {
    define('CONNECTMEET_ACCESS', true);
}

/**
 * Verify participant credentials for a meeting
 * Returns participant record on success, or null on failure
 */
function verifyParticipant(PDO $pdo, int $meetingId, int $participantId, ?string $token): ?array {
    if (empty($token) || $participantId <= 0 || $meetingId <= 0) {
        return null;
    }

    $tokenHash = hashToken($token);

    $stmt = $pdo->prepare("
        SELECT p.*, m.status AS meeting_status, m.is_locked, m.meeting_code
        FROM meeting_participants p
        JOIN meetings m ON p.meeting_id = m.id
        WHERE p.id = :participant_id
          AND p.meeting_id = :meeting_id
          AND p.participant_token_hash = :token_hash
        LIMIT 1
    ");

    $stmt->execute([
        ':participant_id' => $participantId,
        ':meeting_id' => $meetingId,
        ':token_hash' => $tokenHash
    ]);

    $participant = $stmt->fetch();
    return $participant ?: null;
}

/**
 * Verify if the caller is the authorized host of the meeting
 */
function verifyHost(PDO $pdo, int $meetingId, ?string $hostToken): bool {
    if (empty($hostToken) || $meetingId <= 0) {
        return false;
    }

    $hostTokenHash = hashToken($hostToken);

    $stmt = $pdo->prepare("
        SELECT id FROM meetings
        WHERE id = :meeting_id
          AND host_token_hash = :host_token_hash
        LIMIT 1
    ");

    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':host_token_hash' => $hostTokenHash
    ]);

    return (bool)$stmt->fetchColumn();
}
