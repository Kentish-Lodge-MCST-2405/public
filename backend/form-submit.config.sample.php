<?php
/**
 * Copy this file to form-submit.config.php on the server and fill in the
 * real values. form-submit.config.php is machine-specific — do NOT commit
 * real credentials to the repository.
 *
 * Live deployment (2026-08): lab.sigmasapiens.com/form-submit.php using the
 * Sparkpost transport (key sourced server-side from /www/wwwroot/.oms/jdmis_keys.json).
 */
return [
    // Where the completed forms go:
    'to' => 'management@kentishlodge.com',

    // Envelope sender / From: must be on a Sparkpost-verified sending domain.
    // forms@jdmis.edu.sg works with the OMS Sparkpost account; to send from
    // kentishlodge.com instead, verify that domain in the Sparkpost console.
    'from' => 'forms@jdmis.edu.sg',
    'from_name' => 'Kentish Lodge Forms',

    // Transport 1 — Sparkpost HTTP API (preferred; supports attachments):
    'sparkpost_key' => 'CHANGE-ME',        // no "Bearer" prefix
    'sparkpost_host' => 'api.sparkpost.com',

    // Transport 2 — SMTP (used if sparkpost_key is empty):
    'smtp_host' => 'smtp.gmail.com',        // or ssl://smtp.gmail.com with port 465
    'smtp_port' => 587,
    'username'  => 'forms@kentishlodge.com',
    'password'  => 'CHANGE-ME',

    // Last-resort PHP mail() if both transports fail (deliverability is worse):
    'fallback_mail' => false,

    // Only these origins may POST to this endpoint (CORS is enforced):
    'allowed_origins' => [
        'https://info.kentishlodge.com',
        'https://kentishlodge.com',
        'http://localhost:4000', // local Jekyll testing
    ],

    // Upload limits (per file / number of files):
    'max_file_mb' => 10,
    'max_files'   => 8,

    // Keep a copy of every submission (folder must be web-denied — it holds
    // personal data; on lab.sigmasapiens.com use form-archive/ with .htaccess deny):
    'archive_dir' => '/www/wwwroot/lab.sigmasapiens.com/form-archive',
];
