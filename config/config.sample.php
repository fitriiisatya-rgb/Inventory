<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored — never commit real credentials.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'stok_opname',
        'user'    => 'so_app',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'timezone'       => 'Asia/Jakarta',
        'session_name'   => 'so_session',
        'upload_dir'     => __DIR__ . '/../uploads/opname',
        'upload_max_kb'  => 8192,
        'lock_ttl_seconds' => 300, // SO_LOCK_TTL_SECONDS — per-team item lock lifetime
    ],
];
