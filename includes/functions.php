<?php
/**
 * ConnectMeet - Core Helper Functions
 */

if (!defined('CONNECTMEET_ACCESS')) {
    define('CONNECTMEET_ACCESS', true);
}

/**
 * Generate a cryptographically secure human-readable meeting code (format: abc-defg-hij)
 */
function generateMeetingCode(): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $len = strlen($chars);
    
    $p1 = '';
    for ($i = 0; $i < 3; $i++) {
        $p1 .= $chars[random_int(0, $len - 1)];
    }
    
    $p2 = '';
    for ($i = 0; $i < 4; $i++) {
        $p2 .= $chars[random_int(0, $len - 1)];
    }
    
    $p3 = '';
    for ($i = 0; $i < 3; $i++) {
        $p3 .= $chars[random_int(0, $len - 1)];
    }
    
    return "{$p1}-{$p2}-{$p3}";
}

/**
 * Sanitize and standardize a user-supplied meeting code or full URL
 */
function normalizeMeetingCode(string $input): string {
    $input = trim(strtolower($input));
    
    // If user pasted a full URL like http://.../room.php?meeting=abc-defg-hij
    if (str_contains($input, 'meeting=')) {
        $parts = parse_url($input);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['meeting'])) {
                $input = $query['meeting'];
            }
        }
    }
    
    // Remove non-alphabetic characters
    $clean = preg_replace('/[^a-z]/', '', $input);
    if (strlen($clean) === 10) {
        return substr($clean, 0, 3) . '-' . substr($clean, 3, 4) . '-' . substr($clean, 7, 3);
    }
    
    return $input;
}

/**
 * Validate meeting code format
 */
function isValidMeetingCode(string $code): bool {
    return (bool)preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $code);
}

/**
 * Generate a secure hex token
 */
function generateSecureToken(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Hash token using SHA-256 for secure database storage
 */
function hashToken(string $token): string {
    return hash('sha256', $token);
}

/**
 * Sanitize untrusted text input
 */
function sanitizeInput(?string $data, int $maxLength = 255): string {
    if ($data === null) {
        return '';
    }
    $trimmed = trim($data);
    $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $trimmed);
    return mb_substr($stripped, 0, $maxLength, 'UTF-8');
}

/**
 * Escape HTML output to prevent XSS
 */
function escapeHtml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Safely parse incoming JSON HTTP request payload
 */
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    return is_array($data) ? $data : [];
}

/**
 * Record a meeting event in the events stream
 */
function recordMeetingEvent(PDO $pdo, int $meetingId, ?int $participantId, string $eventType, array $payload = []): int {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO meeting_events (meeting_id, participant_id, event_type, event_payload, created_at)
            VALUES (:meeting_id, :participant_id, :event_type, :event_payload, NOW())
        ");
        $stmt->execute([
            ':meeting_id' => $meetingId,
            ':participant_id' => $participantId,
            ':event_type' => $eventType,
            ':event_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);
        return (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        if ($participantId === null) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO meeting_events (meeting_id, participant_id, event_type, event_payload, created_at)
                    VALUES (:meeting_id, 0, :event_type, :event_payload, NOW())
                ");
                $stmt->execute([
                    ':meeting_id' => $meetingId,
                    ':event_type' => $eventType,
                    ':event_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)
                ]);
                return (int)$pdo->lastInsertId();
            } catch (\PDOException $e2) {}
        }
        throw $e;
    }
}

/**
 * Detect or resolve the current application base URL dynamically
 */
function getAppBaseUrl(?string $configuredBaseUrl = null): string {
    // If running in CLI, return configured or default
    if (php_sapi_name() === 'cli') {
        return !empty($configuredBaseUrl) ? rtrim($configuredBaseUrl, '/') : 'http://localhost';
    }

    // If an explicit non-empty URL without localhost is given, respect it
    if (!empty($configuredBaseUrl) && !str_contains($configuredBaseUrl, 'localhost') && !str_contains($configuredBaseUrl, '127.0.0.1')) {
        return rtrim($configuredBaseUrl, '/');
    }

    // Auto-detect protocol (including reverse proxy / HTTPS termination)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');

    $protocol = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Calculate directory path relative to web root
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = dirname($scriptName);
    // Remove subdirectories if called from api/ or scripts/
    $dir = preg_replace('#/(api|scripts|includes)$#i', '', $dir);
    $dir = str_replace('\\', '/', $dir);
    $dir = trim($dir, '/');

    return $protocol . $host . ($dir !== '' ? '/' . $dir : '');
}

