<?php
/**
 * ConnectMeet - Unified Response Handler
 */

if (!defined('CONNECTMEET_ACCESS')) {
    define('CONNECTMEET_ACCESS', true);
}

function sendResponse(bool $success, mixed $data = null, string $message = '', int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendSuccess(mixed $data = null, string $message = 'Operation completed', int $statusCode = 200): void {
    sendResponse(true, $data, $message, $statusCode);
}

function sendError(string $message = 'An error occurred', int $statusCode = 400, mixed $data = null): void {
    sendResponse(false, $data, $message, $statusCode);
}
