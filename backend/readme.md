# Form submission backend (PHP)

Serves the online form at `info.kentishlodge.com/forms/apply.html`: it receives
the filled fields, document uploads and canvas signatures, then emails them to
**management@kentishlodge.com** with a **copy (CC) to the submitting person's
email** and a Reply-To of the submitter.

---

# Records API (`records-api.php`) — since 2026-10-21

Backs the estate-records tools: resident issue reporting/tracking
(`/tools/issues.html`), site visits & inspections (`/tools/visits.html`) and
letter drafting/recording (`/tools/letters.html`).

- **Storage:** one SQLite database (`db_path`), auto-created, WAL mode.
  Tables: residents, sessions, issues, issue_events, visits, findings,
  letters, appeals, files, rate.
- **Uploads:** photos/evidence stored under `files_dir` with randomised
  names. The directory must be **web-inaccessible**; files are streamed only
  by the authenticated `file` action.
- **Auth:** residents (email+password accounts, bearer tokens), reporters
  (ref+PIN), office (admin key via `X-Admin-Key` or `admin_key` field).
- **Email:** best-effort notifications via Sparkpost (new issue → office;
  resolved/closed → reporter; letters when delivery = emailed). Mail config is
  inherited from `form-submit.config.php` when present.
- **Abuse controls:** per-IP rate limits per action, honeypot + minimum fill
  time on public submissions, upload type/size validation via finfo.

## Deploy (VPS, alongside form-submit.php)

1. Create a private dir outside the web vhost, e.g.
   `/www/wwwroot/lab.sigmasapiens.com/records-private/{,files}`.
2. Copy `records-api.php` into the web root next to `form-submit.php`.
3. Copy `records.config.sample.php` → `records.config.php` in the same place
   (chmod 600): set `admin_key` (long random string), `db_path` and
   `files_dir` to the private dir, `allowed_origins` to the site origin.
   Mail settings inherit from `form-submit.config.php` automatically.
4. If Apache: ensure `records-private/` is denied; if nginx, add a
   `location` deny for it (files must never be served directly).
5. Smoke test:
   `curl -s https://lab.sigmasapiens.com/records-api.php -d action=ping`
   → `{"ok":true,"service":"kentish-records",...}`
6. Enter the admin key in the tools (`visits.html` unlock prompt;
   `letters.html` → Office settings) and run one test report end-to-end.

No schema migration steps — tables are created on first use.

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
