<?php
/**
 * ConnectMeet - Automated Maintenance & Stale Participant Cleanup Script
 * Can be run via CLI or scheduled Cron job.
 */

if (php_sapi_name() !== 'cli' && !defined('CONNECTMEET_ACCESS')) {
    define('CONNECTMEET_ACCESS', true);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = Database::getConnection();
$timeoutSec = (int)($appConfig['meeting']['heartbeat_timeout_sec'] ?? 15);

echo "[ConnectMeet Cleanup] Running maintenance routine at " . date('Y-m-d H:i:s') . "\n";

// 1. Detect stale participants who missed heartbeats
$staleStmt = $pdo->prepare("
    SELECT id, meeting_id, display_name
    FROM meeting_participants
    WHERE is_online = 1
      AND last_seen_at < DATE_SUB(NOW(), INTERVAL :timeout SECOND)
");
$staleStmt->execute([':timeout' => $timeoutSec]);
$staleParticipants = $staleStmt->fetchAll();

$cleanedCount = 0;
foreach ($staleParticipants as $stale) {
    $pId = (int)$stale['id'];
    $mId = (int)$stale['meeting_id'];

    $update = $pdo->prepare("UPDATE meeting_participants SET is_online = 0, left_at = NOW() WHERE id = :id");
    $update->execute([':id' => $pId]);

    recordMeetingEvent($pdo, $mId, $pId, 'participant_left', [
        'id' => $pId,
        'display_name' => $stale['display_name'],
        'reason' => 'Heartbeat timeout'
    ]);
    $cleanedCount++;
}

echo "[ConnectMeet Cleanup] Disconnected {$cleanedCount} stale participants.\n";

// 2. Prune old delivered WebRTC signaling messages (> 5 minutes)
$sigStmt = $pdo->prepare("DELETE FROM meeting_signals WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
$sigStmt->execute();
$prunedSignals = $sigStmt->rowCount();
echo "[ConnectMeet Cleanup] Pruned {$prunedSignals} obsolete WebRTC signaling records.\n";

// 3. Auto-close empty meetings that have been inactive for > 6 hours
$meetStmt = $pdo->prepare("
    UPDATE meetings m
    SET m.status = 'ended'
    WHERE m.status = 'active'
      AND m.created_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)
      AND NOT EXISTS (
          SELECT 1 FROM meeting_participants p
          WHERE p.meeting_id = m.id AND p.is_online = 1
      )
");
$meetStmt->execute();
$closedMeetings = $meetStmt->rowCount();
echo "[ConnectMeet Cleanup] Closed {$closedMeetings} abandoned meetings.\n";

echo "[ConnectMeet Cleanup] Maintenance completed successfully.\n";
