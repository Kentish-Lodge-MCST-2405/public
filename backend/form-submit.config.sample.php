<?php
/**
 * Copy this file to form-submit.config.php on the server and fill in the
 * real values. form-submit.config.php is machine-specific — do NOT commit
 * real credentials to the repository.
 */
return [
    // Where the completed forms go:
    'to' => 'management@kentishlodge.com',

    // Envelope sender / From: use a mailbox on your domain, e.g. forms@kentishlodge.com
    'from' => 'Kentish Lodge Forms <forms@kentishlodge.com>',

    // SMTP account used to send. For Gmail/Google Workspace use an app password.
    'smtp_host' => 'smtp.gmail.com',        // or ssl://smtp.gmail.com with port 465
    'smtp_port' => 587,
    'username'  => 'forms@kentishlodge.com',
    'password'  => 'CHANGE-ME',

    // If SMTP fails, try PHP mail() as a last resort (deliverability is worse):
    'fallback_mail' => true,

    // Only these origins may POST to this endpoint:
    'allowed_origins' => [
        'https://info.kentishlodge.com',
        'https://kentishlodge.com',
        'http://localhost:4000', // local Jekyll testing
    ],

    // Upload limits (per file / number of files):
    'max_file_mb' => 10,
    'max_files'   => 8,

    // Optional: keep a copy of every submission (folder must exist, web-inaccessible).
    // 'archive_dir' => '/www/wwwroot/kentishlodge.com-private/submissions',
];
