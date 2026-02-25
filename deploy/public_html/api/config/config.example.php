<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => 'production', // APP_ENV
        'url' => 'https://onledge.gops.app', // APP_URL
        'api_base_url' => 'https://onledge.gops.app/api', // API_BASE_URL
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 5432,
        'dbname' => 'onledge',
        'user' => 'onledge_user',
        'password' => 'replace-with-strong-password',
        'sslmode' => 'prefer', // disable|allow|prefer|require|verify-ca|verify-full
    ],
    'uploads' => [
        // Prefer a path outside web root when possible. If inside web root, lock it down with .htaccess.
        'dir' => '/home/gopsapp1/onledge_uploads', // UPLOAD_DIR
        'max_upload_mb' => 10, // MAX_UPLOAD_MB
        'allowed_mime_types' => [ // ALLOWED_MIME_TYPES
            'image/jpeg',
            'image/png',
            'application/pdf',
        ],
    ],
    'session_cookie' => [
        'name' => 'onledge_session',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'smtp' => [
        'enabled' => false,
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'smtp-user',
        'password' => 'smtp-password',
        'from_email' => 'noreply@example.com',
        'from_name' => 'OnLedge',
    ],
];
