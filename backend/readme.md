# Form submission backend (PHP)

Serves the online form at `info.kentishlodge.com/forms/apply.html`: it receives
the filled fields, document uploads and canvas signatures, then emails them to
**management@kentishlodge.com** with a **copy (CC) to the submitting person's
email** and a Reply-To of the submitter.

## LIVE deployment (since 2026-08-28)

- Endpoint: `https://lab.sigmasapiens.com/form-submit.php` (PHP 8.4, VPS panel.sigmasapiens.com)
- Config: `/www/wwwroot/lab.sigmasapiens.com/form-submit.config.php` (chmod 600, not in git)
- Transport: **Sparkpost API**, key `external.oms_sparkpost_api` sourced from
  `/www/wwwroot/.oms/jdmis_keys.json` on the VPS; From `forms@jdmis.edu.sg`
  (kentishlodge.com is not a verified Sparkpost sending domain — verify it there
  if you want a kentishlodge.com From).
- Archive: `/www/wwwroot/lab.sigmasapiens.com/form-archive/` (web-denied via
  `.htaccess`; holds submissions — decide a retention period, PDPA).
- Frontend `FORMS_ENDPOINT` in `forms/apply.html` points at the endpoint.

Note: kentishlodge.com / uplift.kentishlodge.com are NOT served by this VPS
(the panel vhost is stopped → "under maintenance" page), so the endpoint lives
on lab. Moving it requires root/panel access.

## Deploy elsewhere

1. Copy `form-submit.php` + a filled copy of `form-submit.config.sample.php`
   (as `form-submit.config.php`) to any PHP 7.4+ location.
2. Point `FORMS_ENDPOINT` in `forms/apply.html` at it and push (Pages rebuilds).
3. Smoke test: `curl -F ... -H "Origin: https://info.kentishlodge.com"` or just
   submit a form online; a test email is sent to the configured `to`.

## How it works

- `POST multipart/form-data` from the form page → `form-submit.php`.
- Checks: POST only, origin allowlist (+ CORS echo), honeypot field, minimum
  fill time, per-file size/type validation (PDF/JPG/PNG/WEBP/HEIC, ≤10 MB).
- Transports in order: Sparkpost API → SMTP (STARTTLS/AUTH) → PHP `mail()`.
  Attachments ride along on all three; signature canvases are attached as PNGs.
- On any transport failure the endpoint returns JSON with the reason and the
  frontend automatically falls back to "Download completed form + email draft",
  so submissions are never lost.

## Security notes

- Real `form-submit.config.php` must never be committed (only the `.sample`).
- Submissions pass through the server in transit; nothing is stored unless
  `archive_dir` is set. If archiving, set a retention policy (PDPA).
- Abuse can be further reduced with Cloudflare Turnstile if spam appears.
