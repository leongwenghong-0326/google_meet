<?php
/**
 * ConnectMeet - Health & Diagnostics API
 */

define('IS_API_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT 1");
    $dbOk = (bool)$stmt->fetchColumn();
} catch (Exception $e) {
    $dbOk = false;
}

sendSuccess([
    'status' => 'healthy',
    'app_name' => $appConfig['app']['name'],
    'version' => $appConfig['app']['version'],
    'php_version' => PHP_VERSION,
    'database_connected' => $dbOk,
    'server_time' => date('Y-m-d H:i:s'),
    'timezone' => date_default_timezone_get(),
    'webrtc_ice_servers' => $appConfig['webrtc']['ice_servers']
], 'ConnectMeet health status check successful');
