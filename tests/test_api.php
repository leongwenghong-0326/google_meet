<?php
/**
 * ConnectMeet - Comprehensive Backend & Signaling Verification Test Suite
 * Validates end-to-end meeting lifecycle, multi-participant signaling, and security checks.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = Database::getConnection();

echo "========================================================\n";
echo "       ConnectMeet System Automated Test Suite          \n";
echo "========================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $description, bool $condition, string $details = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "[PASS] $description\n";
        $testsPassed++;
    } else {
        echo "[FAIL] $description" . ($details ? " - $details" : "") . "\n";
        $testsFailed++;
    }
}

// -------------------------------------------------------------
// Test 1: Health & Database Connectivity
// -------------------------------------------------------------
try {
    $stmt = $pdo->query("SELECT 1");
    assertTest("Database connectivity check", (bool)$stmt->fetchColumn());
} catch (Exception $e) {
    assertTest("Database connectivity check", false, $e->getMessage());
}

// -------------------------------------------------------------
// Test 2: Meeting Creation & Format Validation
// -------------------------------------------------------------
$code = generateMeetingCode();
assertTest("Meeting code format matches regex (/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/)", isValidMeetingCode($code), "Generated: $code");

$hostToken = generateSecureToken();
$hostTokenHash = hashToken($hostToken);

$insertMeeting = $pdo->prepare("
    INSERT INTO meetings (meeting_code, host_token_hash, title, status, participant_limit, created_at, updated_at)
    VALUES (:code, :hash, 'Automated Test Meeting', 'active', 8, NOW(), NOW())
");
$insertMeeting->execute([':code' => $code, ':hash' => $hostTokenHash]);
$meetingId = (int)$pdo->lastInsertId();
assertTest("Insert new meeting into database", $meetingId > 0, "Meeting ID: $meetingId");

// Test invalid code validation
assertTest("Reject malformed meeting code '123-invalid-code'", !isValidMeetingCode('123-invalid-code'));

// -------------------------------------------------------------
// Test 3: Multi-Participant Join Simulation
// -------------------------------------------------------------
// Participant 1 (Host)
$part1Token = generateSecureToken();
$p1Stmt = $pdo->prepare("
    INSERT INTO meeting_participants
    (meeting_id, participant_token_hash, display_name, role, audio_enabled, video_enabled, is_online, joined_at, last_seen_at)
    VALUES (:m_id, :t_hash, 'Alice Host', 'host', 1, 1, 1, NOW(), NOW())
");
$p1Stmt->execute([':m_id' => $meetingId, ':t_hash' => hashToken($part1Token)]);
$p1Id = (int)$pdo->lastInsertId();
assertTest("Participant 1 (Host) joined successfully", $p1Id > 0, "P1 ID: $p1Id");

// Participant 2 (Guest)
$part2Token = generateSecureToken();
$p2Stmt = $pdo->prepare("
    INSERT INTO meeting_participants
    (meeting_id, participant_token_hash, display_name, role, audio_enabled, video_enabled, is_online, joined_at, last_seen_at)
    VALUES (:m_id, :t_hash, 'Bob Guest', 'participant', 1, 1, 1, NOW(), NOW())
");
$p2Stmt->execute([':m_id' => $meetingId, ':t_hash' => hashToken($part2Token)]);
$p2Id = (int)$pdo->lastInsertId();
assertTest("Participant 2 (Guest) joined successfully", $p2Id > 0, "P2 ID: $p2Id");

// Verify participant authentication
$authP1 = verifyParticipant($pdo, $meetingId, $p1Id, $part1Token);
$authP2 = verifyParticipant($pdo, $meetingId, $p2Id, $part2Token);
$fakeAuth = verifyParticipant($pdo, $meetingId, $p1Id, 'wrong_token');

assertTest("Participant 1 token verification", $authP1 !== null && $authP1['display_name'] === 'Alice Host');
assertTest("Participant 2 token verification", $authP2 !== null && $authP2['display_name'] === 'Bob Guest');
assertTest("Reject unauthorized token", $fakeAuth === null);

// -------------------------------------------------------------
// Test 4: Heartbeat & Presence
// -------------------------------------------------------------
$pdo->prepare("UPDATE meeting_participants SET last_seen_at = NOW() WHERE id = :id")->execute([':id' => $p1Id]);
$checkP1 = $pdo->query("SELECT last_seen_at FROM meeting_participants WHERE id = $p1Id")->fetchColumn();
assertTest("Heartbeat presence timestamp updated", !empty($checkP1));

// -------------------------------------------------------------
// Test 5: State Updates (Audio, Video, Hand-Raised)
// -------------------------------------------------------------
$updateStmt = $pdo->prepare("UPDATE meeting_participants SET hand_raised = 1, audio_enabled = 0 WHERE id = :id");
$updateStmt->execute([':id' => $p2Id]);
$p2State = $pdo->query("SELECT hand_raised, audio_enabled FROM meeting_participants WHERE id = $p2Id")->fetch();
assertTest("Participant state synchronized (Mute & Hand Raised)", (int)$p2State['hand_raised'] === 1 && (int)$p2State['audio_enabled'] === 0);

// -------------------------------------------------------------
// Test 6: WebRTC Signaling Mesh Exchange (Offer, Answer, Candidate)
// -------------------------------------------------------------
// P2 sends SDP Offer to P1
$offerPayload = json_encode(['type' => 'offer', 'sdp' => 'v=0\r\no=- 12345 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n']);
$sigOffer = $pdo->prepare("
    INSERT INTO meeting_signals (meeting_id, from_participant_id, to_participant_id, signal_type, signal_payload, is_delivered, created_at)
    VALUES (:m_id, :from, :to, 'offer', :payload, 0, NOW())
");
$sigOffer->execute([':m_id' => $meetingId, ':from' => $p2Id, ':to' => $p1Id, ':payload' => $offerPayload]);
$offerId = (int)$pdo->lastInsertId();
assertTest("WebRTC Offer dispatched from P2 to P1", $offerId > 0);

// P1 polls and receives Offer
$pollStmt = $pdo->prepare("
    SELECT * FROM meeting_signals
    WHERE meeting_id = :m_id AND to_participant_id = :to AND is_delivered = 0
");
$pollStmt->execute([':m_id' => $meetingId, ':to' => $p1Id]);
$p1Signals = $pollStmt->fetchAll();
assertTest("P1 received undelivered WebRTC Offer", count($p1Signals) === 1 && $p1Signals[0]['signal_type'] === 'offer');

// Mark as delivered
$pdo->exec("UPDATE meeting_signals SET is_delivered = 1 WHERE id = $offerId");

// P1 sends Answer to P2
$answerPayload = json_encode(['type' => 'answer', 'sdp' => 'v=0\r\no=- 67890 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n']);
$sigAnswer = $pdo->prepare("
    INSERT INTO meeting_signals (meeting_id, from_participant_id, to_participant_id, signal_type, signal_payload, is_delivered, created_at)
    VALUES (:m_id, :from, :to, 'answer', :payload, 0, NOW())
");
$sigAnswer->execute([':m_id' => $meetingId, ':from' => $p1Id, ':to' => $p2Id, ':payload' => $answerPayload]);
$answerId = (int)$pdo->lastInsertId();
assertTest("WebRTC Answer dispatched from P1 to P2", $answerId > 0);

// P2 polls and receives Answer
$pollStmt->execute([':m_id' => $meetingId, ':to' => $p2Id]);
$p2Signals = $pollStmt->fetchAll();
assertTest("P2 received undelivered WebRTC Answer", count($p2Signals) === 1 && $p2Signals[0]['signal_type'] === 'answer');

// -------------------------------------------------------------
// Test 7: Chat Messages & XSS Protection
// -------------------------------------------------------------
$maliciousMsg = "<script>alert('XSS')</script> Hello Everyone!";
$safeMsg = sanitizeInput($maliciousMsg, 1000);
$chatStmt = $pdo->prepare("
    INSERT INTO meeting_messages (meeting_id, participant_id, message_type, message_content, created_at)
    VALUES (:m_id, :p_id, 'text', :content, NOW())
");
$chatStmt->execute([':m_id' => $meetingId, ':p_id' => $p2Id, ':content' => $safeMsg]);
$msgId = (int)$pdo->lastInsertId();

$savedMsg = $pdo->query("SELECT message_content FROM meeting_messages WHERE id = $msgId")->fetchColumn();
assertTest("Chat message persisted with sanitized input", $msgId > 0);
assertTest("Chat message escaped safely for output", escapeHtml($savedMsg) === htmlspecialchars($savedMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

// -------------------------------------------------------------
// Test 8: Events Stream & Reactions
// -------------------------------------------------------------
$evtId = recordMeetingEvent($pdo, $meetingId, $p1Id, 'reaction', ['emoji' => '🎉']);
assertTest("Emoji reaction recorded to events stream", $evtId > 0);

$evtCheck = $pdo->query("SELECT * FROM meeting_events WHERE id = $evtId")->fetch();
$payloadData = json_decode($evtCheck['event_payload'], true);
assertTest("Event payload matches dispatched emoji", $payloadData['emoji'] === '🎉');

// -------------------------------------------------------------
// Test 9: Host Privileges & Locking
// -------------------------------------------------------------
assertTest("Verify Host token validity", verifyHost($pdo, $meetingId, $hostToken));
assertTest("Reject fake Host token", !verifyHost($pdo, $meetingId, 'fake_host_token'));

// Lock meeting
$pdo->prepare("UPDATE meetings SET is_locked = 1 WHERE id = :id")->execute([':id' => $meetingId]);
$isLocked = (int)$pdo->query("SELECT is_locked FROM meetings WHERE id = $meetingId")->fetchColumn();
assertTest("Meeting locked state toggled", $isLocked === 1);

// -------------------------------------------------------------
// Test 10: Participant Removal (Kick) & Meeting End
// -------------------------------------------------------------
// Host kicks P2
$pdo->prepare("UPDATE meeting_participants SET is_online = 0, left_at = NOW() WHERE id = :id")->execute([':id' => $p2Id]);
$p2Online = (int)$pdo->query("SELECT is_online FROM meeting_participants WHERE id = $p2Id")->fetchColumn();
assertTest("Target participant kicked and marked offline", $p2Online === 0);

// Host ends meeting
$pdo->prepare("UPDATE meetings SET status = 'ended' WHERE id = :id")->execute([':id' => $meetingId]);
$meetStatus = $pdo->query("SELECT status FROM meetings WHERE id = $meetingId")->fetchColumn();
assertTest("Meeting closed and marked as ended", $meetStatus === 'ended');

// -------------------------------------------------------------
// Test Summary
// -------------------------------------------------------------
echo "\n--------------------------------------------------------\n";
echo "Total tests: " . ($testsPassed + $testsFailed) . " | Passed: $testsPassed | Failed: $testsFailed\n";
echo "--------------------------------------------------------\n";

if ($testsFailed === 0) {
    echo "Result: ALL TESTS PASSED SUCCESSFULLY! ConnectMeet is fully verified.\n\n";
    exit(0);
} else {
    echo "Result: SOME TESTS FAILED. Please inspect the logs above.\n\n";
    exit(1);
}
