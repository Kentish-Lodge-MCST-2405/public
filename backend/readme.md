# Form submission backend (PHP)

Serves the online form at `info.kentishlodge.com/forms/apply.html`: it receives
the filled fields, document uploads and canvas signatures, then emails them to
**management@kentishlodge.com** with a **copy to the submitting person's email**.

## Deploy (VPS, kentishlodge.com)

1. Enable PHP for the site in the BT panel vhost if it is currently static-only.
2. Copy the two PHP files to the webroot:

   ```
   scp backend/form-submit.php backend/form-submit.config.sample.php JDMIS:/www/wwwroot/kentishlodge.com/
   ssh JDMIS "cp /www/wwwroot/kentishlodge.com/form-submit.config.sample.php /www/wwwroot/kentishlodge.com/form-submit.config.php"
   ```

3. Edit `form-submit.config.php` on the server: SMTP host/user/password
   (Google Workspace → use an app password), `to` and `from`.
4. Point the form page at it — in `forms/apply.html` set:

   ```js
   var FORMS_ENDPOINT = "https://kentishlodge.com/form-submit.php";
   ```

5. Smoke test: submit a form with a scan and a signature; check the management
   inbox and the CC copy. If SMTP fails the page automatically falls back to
   "Download + email manually" so submissions are never lost.

## How it works

- `POST multipart/form-data` from the form page → `form-submit.php`.
- Checks: POST only, origin allowlist, honeypot field, minimum fill time,
  per-file size/type validation (PDF/JPG/PNG/WEBP/HEIC, ≤10 MB), total cap.
- Builds a MIME message: plain-text + HTML summary of every field, document
  uploads as attachments, signature canvases attached as PNGs.
- Sends over SMTP with STARTTLS + AUTH LOGIN (no libraries needed); optional
  PHP `mail()` fallback.
- Optional `archive_dir` keeps a copy of each submission (keep that folder
  outside the webroot or web-denied — it contains personal data).

## Security notes

- Real `form-submit.config.php` must never be committed (only the `.sample`).
- Personal data passes through the server only in transit; nothing is stored
  unless you configure `archive_dir`. If you enable archiving, decide a
  retention period (e.g. delete after 12 months) — PDPA.
- The endpoint responds only to allowed origins; abuse can be further reduced
  with Cloudflare Turnstile if spam ever becomes a problem.
