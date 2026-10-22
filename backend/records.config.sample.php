<?php
/**
 * Kentish Lodge MCST — records API configuration (SAMPLE).
 * Copy to records.config.php next to records-api.php and fill in.
 * Mail settings (from / from_name / to / sparkpost_key) are inherited from
 * form-submit.config.php when that file exists — this file only needs the
 * records-specific keys plus any overrides.
 */
return [
    // Origins allowed to call the API from the browser.
    'allowed_origins' => ['https://info.kentishlodge.com', 'http://localhost:4000'],

    // Management key — long random string. Held by the managing agent's
    // office; required for all admin_* actions and shown in visits/letters tools.
    'admin_key' => 'CHANGE-ME-long-random-string',

    // SQLite database (auto-created). Keep outside any web-served directory,
    // or at minimum deny web access to it.
    'db_path' => '/www/wwwroot/lab.sigmasapiens.com/records-private/records.sqlite',

    // Uploaded photos/evidence. MUST be web-inaccessible (the API streams
    // files itself with auth checks) — deny via .htaccess / nginx rule.
    'files_dir' => '/www/wwwroot/lab.sigmasapiens.com/records-private/files',

    // Base URL of the public site (used in notification emails).
    'site_base' => 'https://info.kentishlodge.com',

    // Optional overrides for mail (else inherited from form-submit.config.php):
    // 'to' => 'management@kentishlodge.com',
    // 'from' => 'forms@jdmis.edu.sg',
    // 'from_name' => 'Kentish Lodge MCST',
    // 'sparkpost_key' => '...',
];
