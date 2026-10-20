<?php
/**
 * ConnectMeet - Application Bootstrap
 */

// Define access constant
if (!defined('CONNECTMEET_ACCESS')) {
    define('CONNECTMEET_ACCESS', true);
}

// Load configurations
$appConfig = require dirname(__DIR__) . '/config/config.php';

// Set timezone
if (!empty($appConfig['app']['timezone'])) {
    date_default_timezone_set($appConfig['app']['timezone']);
}

// Error reporting based on debug flag
if (!empty($appConfig['app']['debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
}

// Configure secure session cookies
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Require supporting files
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
