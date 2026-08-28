<?php
/**
 * Kentish Lodge MCST — form submission endpoint
 * Receives form fields + document uploads + signature PNGs from the
 * online form page (info.kentishlodge.com/forms/apply.html) and emails
 * them to the Management office, CC to the submitting person's email.
 *
 * Transports (first available wins):
 *   1. Sparkpost HTTP API   (config 'sparkpost_key')
 *   2. SMTP STARTTLS/AUTH   (config 'smtp_host')
 *   3. PHP mail()           (config 'fallback_mail')
 *
 * Deploy: copy this file next to form-submit.config.php (created from the
 * .sample). The config holds secrets — never commit the real one.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

/* ---------------- config ---------------- */
$configFile = __DIR__ . '/form-submit.config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Endpoint not configured (missing config).']);
    exit;
}
$cfg = require $configFile;

/* ---------------- helpers ---------------- */
function json_out($ok, $payload, $code = 200) {
    http_response_code($code);
    echo json_encode($ok ? array_merge(['ok' => true], $payload) : array_merge(['ok' => false], $payload));
    exit;
}
/** Returns the allowed origin string, or false. */
function origin_ok($cfg) {
    $allowed = isset($cfg['allowed_origins']) ? $cfg['allowed_origins'] : [];
    if (!$allowed) return '*';
    $cand = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($cand === '' && isset($_SERVER['HTTP_REFERER'])) {
        $p = parse_url($_SERVER['HTTP_REFERER']);
        $cand = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) && $p['port'] ? ':' . $p['port'] : '');
    }
    if ($cand === '') return true; // non-browser client
    return in_array($r = rtrim($cand, '/'), array_map('rtrim', $allowed, array_fill(0, count($allowed), '/')), true) ? $r : false;
}
function json_fail($code, $msg, $extra = []) { json_out(false, array_merge(['error' => $msg], $extra), $code); }

/* ---------------- CORS ---------------- */
$origin = origin_ok($cfg);
if ($origin === false) json_fail(403, 'Origin not allowed.');
if ($origin !== true && $origin !== '*') header('Access-Control-Allow-Origin: ' . $origin);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}

/* ---------------- basic checks ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_fail(405, 'POST required.');
if (($_POST['website'] ?? '') !== '') json_out(true, ['ref' => 'IGNORED']); // honeypot
$ts = (int)($_POST['ts'] ?? 0);
if ($ts > 0 && (time() * 1000 - $ts) < 2000) json_fail(400, 'Submitted too quickly.');
$formId = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['form_id'] ?? ''));
if ($formId === '') json_fail(400, 'Missing form id.');
$subjectTag = trim($_POST['subject_tag'] ?? $formId);
$maxFileMb = isset($cfg['max_file_mb']) ? (int)$cfg['max_file_mb'] : 10;
$maxFiles  = isset($cfg['max_files']) ? (int)$cfg['max_files'] : 8;
$maxBytes  = $maxFileMb * 1024 * 1024;

/* ---------------- collect fields ---------------- */
$fields = [];
foreach ($_POST as $k => $v) {
    if (strpos($k, 'f_') === 0) $fields[substr($k, 2)] = is_string($v) ? trim($v) : $v;
}
$ccEmail = '';
foreach (['email', 'auth_email'] as $k) {
    if (isset($fields[$k]) && filter_var($fields[$k], FILTER_VALIDATE_EMAIL)) { $ccEmail = $fields[$k]; break; }
}
if (!empty($_POST['cc_email']) && filter_var($_POST['cc_email'], FILTER_VALIDATE_EMAIL)) $ccEmail = $_POST['cc_email'];

$ref = 'KL-' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $formId)) . '-' . strtoupper(base_convert((string)time(), 10, 36)) . strtoupper(bin2hex(random_bytes(1)));

/* ---------------- collect + validate uploads ---------------- */
$allowedExt  = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];
$allowedMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/octet-stream'];
$attach = []; // [name, path, mime, cid]
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
$totalBytes = 0;

/* Primary input since 2026-08-28: one combined PDF (form + attachments), field name "pdf" */
$pdfName = null; $pdfPath = null;
if (isset($_FILES['pdf']) && ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $p = $_FILES['pdf'];
    if (($p['size'] ?? 0) > 0 && $p['size'] <= $maxBytes) {
        $pn = basename($p['name']);
        $pm = $finfo ? (finfo_file($finfo, $p['tmp_name']) ?: 'application/pdf') : 'application/pdf';
        if (strtolower(pathinfo($pn, PATHINFO_EXTENSION)) === 'pdf' && in_array($pm, ['application/pdf', 'application/octet-stream'], true)) {
            $pdfPath = tempnam(sys_get_temp_dir(), 'klpdf_');
            if (move_uploaded_file($p['tmp_name'], $pdfPath)) {
                $pdfName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $_POST['pdf_name'] ?? $pn) ?: $pn;
                $attach[] = ['name' => $pdfName, 'path' => $pdfPath, 'mime' => 'application/pdf', 'cid' => null];
            } else { $pdfPath = null; }
        } else { json_fail(400, 'Submission must be a PDF file.'); }
    } else { json_fail(400, 'PDF too large or empty.'); }
}

foreach ($_FILES as $inputName => $f) {
    if ($inputName === 'pdf') continue; // already handled
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    if (($f['size'] ?? 0) <= 0 || $f['size'] > $maxBytes) json_fail(400, 'File too large or empty: ' . basename($f['name']));
    if (count($attach) >= $maxFiles) json_fail(400, 'Too many files.');
    $orig = basename($f['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) json_fail(400, 'File type not allowed: .' . $ext);
    $mime = $finfo ? (finfo_file($finfo, $f['tmp_name']) ?: 'application/octet-stream') : 'application/octet-stream';
    if (!in_array($mime, $allowedMime, true)) json_fail(400, 'File content not allowed: ' . $orig);
    $safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $orig);
    if ($safe === '') $safe = 'file.' . $ext;
    $tmp = tempnam(sys_get_temp_dir(), 'klm_');
    if (!move_uploaded_file($f['tmp_name'], $tmp)) json_fail(500, 'Could not process upload.');
    $cid = strpos($inputName, 'sig_') === 0 ? $inputName . '@form' : null;
    $totalBytes += (int)$f['size'];
    $attach[] = [
        'name' => $cid ? 'signature-' . substr($inputName, 4) . '.png' : $safe,
        'path' => $tmp,
        'mime' => $cid ? 'image/png' : $mime,
        'cid'  => $cid,
    ];
}
if ($finfo) finfo_close($finfo);

/* ---------------- compose content ---------------- */
$to = $cfg['to'] ?? '';
if (!$to) json_fail(500, 'Endpoint misconfigured (to).');
$from     = $cfg['from'] ?? '';
$fromName = $cfg['from_name'] ?? 'Kentish Lodge Forms';
if (!$from) json_fail(500, 'Endpoint misconfigured (from).');

$subject = '[' . $subjectTag . '] '
    . ($fields['unit_no'] ?? $fields['ap_unit'] ?? '')
    . (isset($fields['vehicle_reg_no']) && $fields['vehicle_reg_no'] !== '' ? ' — ' . $fields['vehicle_reg_no'] : '')
    . ' (ref ' . $ref . ')';

$plainLines = [];
$htmlRows = [];
$plainLines[] = ($_POST['form_no'] ?? $formId) . ' — ' . ($_POST['form_title'] ?? '');
$plainLines[] = 'Reference: ' . $ref;
$plainLines[] = 'Submitted: ' . date('r') . '  from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$plainLines[] = str_repeat('-', 60);
$htmlRows[] = '<tr><th>Reference</th><td>' . htmlspecialchars($ref) . '</td></tr>';
$htmlRows[] = '<tr><th>Submitted</th><td>' . htmlspecialchars(date('r')) . '</td></tr>';
foreach ($fields as $k => $v) {
    $plainLines[] = $k . ': ' . $v;
    $htmlRows[] = '<tr><th>' . htmlspecialchars(ucwords(str_replace('_', ' ', $k))) . '</th><td>' . htmlspecialchars($v) . '</td></tr>';
}
foreach ($attach as $a) {
    $plainLines[] = 'Attachment: ' . $a['name'];
    $htmlRows[] = '<tr><th>Attachment</th><td>' . htmlspecialchars($a['name']) . '</td></tr>';
}
$plain = implode("\r\n", $plainLines);
$htmlBody = '<html><body style="font-family:Arial,sans-serif;font-size:13px;color:#111">'
    . '<h2 style="font-size:16px">' . htmlspecialchars($_POST['form_title'] ?? $formId) . '</h2>'
    . '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;border-color:#999">'
    . implode('', $htmlRows) . '</table>'
    . ($pdfName ? '<p>The complete submission — form, signatures and supporting documents — is attached as a single PDF: <strong>' . htmlspecialchars($pdfName) . '</strong>' . (isset($_POST['pdf_pages']) && $_POST['pdf_pages'] !== '' ? ' (' . (int)$_POST['pdf_pages'] . ' pages)' : '') . '.</p>' : '')
    . '</body></html>';

/* ---------------- optional local archive ---------------- */
if (!empty($cfg['archive_dir']) && is_dir($cfg['archive_dir']) && is_writable($cfg['archive_dir'])) {
    $dir = rtrim($cfg['archive_dir'], '/\\') . DIRECTORY_SEPARATOR . $ref;
    @mkdir($dir, 0750, true);
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'submission.html', $htmlBody);
    foreach ($attach as $a) @copy($a['path'], $dir . DIRECTORY_SEPARATOR . $a['name']);
}

/* ---------------- transport 1: Sparkpost API ---------------- */
function sparkpost_send($cfg, $to, $cc, $subject, $plain, $htmlBody, $attach, &$err) {
    $key = $cfg['sparkpost_key'];
    $from = $cfg['from'];
    $fromName = $cfg['from_name'] ?? 'Kentish Lodge Forms';
    $host = $cfg['sparkpost_host'] ?? 'api.sparkpost.com';
    $recips = [];
    $recips[] = ['address' => ['email' => $to, 'header_to' => $to]];
    if ($cc && $cc !== $to) $recips[] = ['address' => ['email' => $cc, 'header_to' => $to]];
    $atts = []; $inlines = []; $b64total = 0;
    foreach ($attach as $a) {
        $data = base64_encode(file_get_contents($a['path']));
        $b64total += strlen($data);
        if ($b64total > 9 * 1024 * 1024) { $err = 'Attachments too large for email transport.'; return false; }
        if ($a['cid']) $inlines[] = ['name' => $a['cid'], 'type' => $a['mime'], 'data' => $data];
        else $atts[] = ['name' => $a['name'], 'type' => $a['mime'], 'data' => $data];
    }
    $body = [
        'options' => ['open_tracking' => false, 'click_tracking' => false, 'transactional' => true],
        'recipients' => $recips,
        'content' => [
            'from' => ['name' => $fromName, 'email' => $from],
            'subject' => $subject,
            'text' => $plain,
            'html' => $htmlBody,
            'reply_to' => $cc ?: null,
            'headers' => array_filter([
                'Cc' => ($cc && $cc !== $to) ? $cc : null,
            ], function($v){ return $v !== null; }),
            'attachments' => $atts ?: null,
            'inline_images' => $inlines ?: null,
        ],
    ];
    $ch = curl_init('https://' . $host . '/api/v1/transmissions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) { $err = 'sparkpost curl: ' . $cerr; return false; }
    $j = json_decode($resp, true);
    if ($code >= 200 && $code < 300) return true;
    $err = 'sparkpost ' . $code . ': ';
    if (isset($j['errors'][0]['message'])) $err .= $j['errors'][0]['message'] . (isset($j['errors'][0]['description']) ? ' — ' . $j['errors'][0]['description'] : '');
    else $err .= substr($resp, 0, 300);
    return false;
}

/* ---------------- transport 2: SMTP (STARTTLS/AUTH) ---------------- */
function smtp_send($cfg, $fromEmail, $rcpts, $headers, $msg, &$log) {
    $host = $cfg['smtp_host']; $port = (int)($cfg['smtp_port'] ?? 587);
    $user = $cfg['username'] ?? ''; $pass = $cfg['password'] ?? '';
    $timeout = 20;
    $log = [];
    $sock = @fsockopen($host, $port, $eno, $estr, $timeout);
    if (!$sock) { $log[] = "connect failed: $estr ($eno)"; return false; }
    stream_set_timeout($sock, $timeout);
    $read = function() use ($sock, &$log) {
        $data = '';
        while ($line = fgets($sock, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $log[] = trim($data);
        return $data;
    };
    $cmd = function($c, $quiet = false) use ($sock, $read, &$log) {
        fwrite($sock, $c . "\r\n");
        $log[] = $quiet ? '> ***' : '> ' . $c;
        return $read();
    };
    if (strpos($read(), '220') !== 0) { fclose($sock); return false; }
    $ehloHost = $cfg['helo_name'] ?? 'kentishlodge.com';
    $r = $cmd('EHLO ' . $ehloHost);
    if (strpos($host, 'ssl://') !== 0 && strpos($r, 'STARTTLS') !== false) {
        $cmd('STARTTLS');
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($sock); $log[] = 'TLS failed'; return false; }
        $cmd('EHLO ' . $ehloHost);
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user), true);
        $r = $cmd(base64_encode($pass), true);
        if (strpos($r, '235') !== 0) { fclose($sock); return false; }
    }
    if (strpos($cmd('MAIL FROM:<' . $fromEmail . '>'), '250') !== 0) { fclose($sock); return false; }
    foreach ($rcpts as $rc) {
        if (strpos($cmd('RCPT TO:<' . $rc . '>'), '25') !== 0) { fclose($sock); $log[] = 'rcpt failed: ' . $rc; return false; }
    }
    $cmd('DATA');
    $body = preg_replace('/^\./m', '..', $headers . $msg);
    fwrite($sock, $body . "\r\n.\r\n");
    $r = $read();
    $ok = strpos($r, '250') === 0;
    $cmd('QUIT');
    fclose($sock);
    return $ok;
}

function build_mime($from, $rcptLine, $subject, $plain, $html, $attach) {
    $eol = "\r\n";
    $mixed = 'mix_' . md5(uniqid('', true));
    $alt = 'alt_' . md5(uniqid('', true));
    $headers = 'From: ' . $from . $eol
        . 'To: ' . $rcptLine . $eol
        . 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . $eol
        . 'MIME-Version: 1.0' . $eol;
    $msg = '--' . $mixed . $eol
        . 'Content-Type: multipart/alternative; boundary="' . $alt . '"' . $eol . $eol
        . '--' . $alt . $eol . 'Content-Type: text/plain; charset=UTF-8' . $eol
        . 'Content-Transfer-Encoding: base64' . $eol . $eol
        . chunk_split(base64_encode($plain)) . $eol
        . '--' . $alt . $eol . 'Content-Type: text/html; charset=UTF-8' . $eol
        . 'Content-Transfer-Encoding: base64' . $eol . $eol
        . chunk_split(base64_encode($html)) . $eol
        . '--' . $alt . '--' . $eol;
    foreach ($attach as $a) {
        $data = chunk_split(base64_encode(file_get_contents($a['path'])));
        $dispo = $a['cid'] ? ('inline; filename="' . $a['name'] . '"') : ('attachment; filename="' . $a['name'] . '"');
        $msg .= '--' . $mixed . $eol
            . 'Content-Type: ' . $a['mime'] . '; name="' . $a['name'] . '"' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol
            . 'Content-Disposition: ' . $dispo . $eol
            . ($a['cid'] ? 'Content-ID: <' . $a['cid'] . '>' . $eol : '')
            . $eol . $data . $eol;
    }
    $msg .= '--' . $mixed . '--' . $eol;
    $headers .= $eol; // blank line between headers and MIME body
    return [$headers, $msg];
}

/* ---------------- send ---------------- */
$sent = false; $err = ''; $log = [];
if (!empty($cfg['sparkpost_key'])) {
    $sent = sparkpost_send($cfg, $to, $ccEmail, $subject, $plain, $htmlBody, $attach, $err);
}
if (!$sent && !empty($cfg['smtp_host'])) {
    $fromEmail = preg_replace('/^.*<(.*)>$/', '$1', $from);
    $rcpts = [$to];
    if ($ccEmail !== '' && $ccEmail !== $to) $rcpts[] = $ccEmail;
    $rcptLine = $to . ($ccEmail !== '' && $ccEmail !== $to ? ', ' . $ccEmail : '');
    list($headers, $msgBody) = build_mime($from, $rcptLine, $subject, $plain, $htmlBody, $attach);
    $headers .= 'Cc: ' . ($ccEmail !== '' ? $ccEmail . $eol : '');
    if ($ccEmail !== '') $headers .= 'Reply-To: ' . $ccEmail . $eol;
    $sent = smtp_send($cfg, $fromEmail, $rcpts, $headers, $msgBody, $log);
    if (!$sent) $err .= ' smtp: ' . implode(' | ', array_slice($log, -3));
}
if (!$sent && !empty($cfg['fallback_mail'])) {
    $extra = 'MIME-Version: 1.0' . "\r\n" . 'From: ' . $from . "\r\n" . ($ccEmail !== '' ? 'Cc: ' . $ccEmail . "\r\n" : '');
    if ($ccEmail !== '') $extra .= 'Reply-To: ' . $ccEmail . "\r\n";
    $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $plain, $extra);
    if (!$sent) $err .= ' mail(): failed';
}

foreach ($attach as $a) @unlink($a['path']);

if ($sent) {
    json_out(true, ['ref' => $ref]);
} else {
    json_fail(502, 'Mail transport unavailable. Please use the download/email option instead.', ['detail' => substr($err, 0, 400)]);
}
