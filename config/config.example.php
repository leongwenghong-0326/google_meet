<?php
/**
 * ConnectMeet - Core Application Configuration Template
 * Copy this file to config.php and update with your environment parameters.
 */

if (!defined('CONNECTMEET_ACCESS')) {
    define('CONNECTMEET_ACCESS', true);
}

return [
    'app' => [
        'name' => 'ConnectMeet',
        'version' => '1.0.0',
        'base_url' => 'http://localhost/connectmeet',
        'debug' => false,
        'timezone' => 'UTC',
    ],

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'connectmeet_db',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
        'charset' => 'utf8mb4',
    ],

    'meeting' => [
        'default_participant_limit' => 8,
        'max_display_name_length' => 50,
        'code_format_regex' => '/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/',
        'heartbeat_interval_sec' => 3,
        'heartbeat_timeout_sec' => 15,
        'signaling_poll_interval_ms' => 1000,
        'events_poll_interval_ms' => 1200,
        'chat_rate_limit_per_minute' => 30,
        'max_chat_length' => 1000,
    ],

    'webrtc' => [
        'ice_servers' => [
            [
                'urls' => [
                    'stun:stun.l.google.com:19302',
                    'stun:stun1.l.google.com:19302'
                ]
            ]
        ]
    ]
];
