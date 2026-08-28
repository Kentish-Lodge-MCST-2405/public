<?php
/**
 * Kentish Lodge MCST — form submission endpoint
 * Receives form fields + document uploads + signature PNGs from the
 * online form page (forms/apply.html) and emails them to the Management
 * office with a copy (CC) to the submitting person's email.
 *
 * Deploy: copy this file and form-submit.config.php (created from the
 * .sample) to a PHP-enabled location, e.g. /www/wwwroot/kentishlodge.com/
 * then set FORMS_ENDPOINT in forms/apply.html to its public URL.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$configFile = __DIR__ . '/form-submit.config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Endpoint not configured (missing config).']);
    exit;
}
$cfg = require $configFile;

/* ---------- helpers ---------- */
function json_fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function origin_ok($cfg) {
    $allowed = isset($cfg['allowed_origins']) ? $cfg['allowed_origins'] : [];
    if (!$allowed) return true; // not restricted
    $cand = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($cand === '' && isset($_SERVER['HTTP_REFERER'])) {
        $p = parse_url($_SERVER['HTTP_REFERER']);
        $cand = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) && $p['port'] ? ':' . $p['port'] : '');
    }
    if ($cand === '') return true; // same-origin tools without Origin header
    return in_array($cand, $allowed, true);
}

/* ---------- basic checks ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_fail(405, 'POST required.');
if (!origin_ok($cfg)) json_fail(403, 'Origin not allowed.');
if (($_POST['website'] ?? '') !== '') { echo json_encode(['ok' => true, 'ref' => 'IGNORED']); exit; } // honeypot
$ts = (int)($_POST['ts'] ?? 0);
if ($ts > 0 && (time() * 1000 - $ts) < 2000) json_fail(400, 'Submitted too quickly.');
$formId = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['form_id'] ?? ''));
if ($formId === '') json_fail(400, 'Missing form id.');
$subjectTag = trim($_POST['subject_tag'] ?? $formId);
$maxTotal = (isset($cfg['max_file_mb']) ? (int)$cfg['max_file_mb'] : 10) * (isset($cfg['max_files']) ? (int)$cfg['max_files'] : 8);
if (!empty($_FILES) && array_sum(array_map(fn($f) => (int)($f['size'] ?? 0), $_FILES)) > $maxTotal * 1024 * 1024 + 2 * 1024 * 1024) {
    json_fail(413, 'Upload too large.');
}

/* ---------- collect fields ---------- */
$fields = [];
foreach ($_POST as $k => $v) {
    if (strpos($k, 'f_') === 0) {
        $key = substr($k, 2);
        $fields[$key] = is_string($v) ? trim($v) : $v;
    }
}
$ccEmail = '';
if (isset($fields['email']) && filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) $ccEmail = $fields['email'];
if ($ccEmail === '' && isset($fields['auth_email']) && filter_var($fields['auth_email'], FILTER_VALIDATE_EMAIL)) $ccEmail = $fields['auth_email'];
if (!empty($_POST['cc_email']) && filter_var($_POST['cc_email'], FILTER_VALIDATE_EMAIL)) $ccEmail = $_POST['cc_email'];

$ref = 'KL-' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $formId)) . '-' . strtoupper(base_convert((string)time(), 10, 36)) . strtoupper(bin2hex(random_bytes(1)));

/* ---------- collect + validate uploads ---------- */
$allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];
$allowedMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/octet-stream'];
$maxBytes = (isset($cfg['max_file_mb']) ? (int)$cfg['max_file_mb'] : 10) * 1024 * 1024;
$attach = [];   // [ ['name'=>, 'path'=>, 'cid'=>null] ]
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;

foreach ($_FILES as $inputName => $f) {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    if (($f['size'] ?? 0) <= 0 || $f['size'] > $maxBytes) json_fail(400, 'File too large or empty: ' . basename($f['name']));
    if (count($attach) >= (isset($cfg['max_files']) ? (int)$cfg['max_files'] : 8)) json_fail(400, 'Too many files.');
    $orig = basename($f['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) json_fail(400, 'File type not allowed: .' . $ext);
    if ($finfo) {
        $mime = finfo_file($finfo, $f['tmp_name']);
        if ($mime && !in_array($mime, $allowedMime, true)) json_fail(400, 'File content not allowed: ' . $orig);
    }
    $safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $orig);
    if ($safe === '') $safe = 'file.' . $ext;
    $tmp = tempnam(sys_get_temp_dir(), 'klm_');
    if (!move_uploaded_file($f['tmp_name'], $tmp)) json_fail(500, 'Could not process upload.');
    $cid = null;
    if (strpos($inputName, 'sig_') === 0) $cid = $inputName . '@form';
    $attach[] = ['name' => ($cid ? 'signature-' . substr($inputName, 4) . '.png' : $safe), 'path' => $tmp, 'cid' => $cid];
}
if ($finfo) finfo_close($finfo);

/* ---------- compose email ---------- */
$to = $cfg['to'] ?? '';
$from = $cfg['from'] ?? ($cfg['username'] ?? '');
if (!$to || !$from) json_fail(500, 'Endpoint misconfigured (to/from).');

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
    . (count($attach) ? '<p>Signatures are attached as PNG images.</p>' : '')
    . '</body></html>';

/* ---------- optional local archive ---------- */
if (!empty($cfg['archive_dir']) && is_dir($cfg['archive_dir']) && is_writable($cfg['archive_dir'])) {
    $dir = rtrim($cfg['archive_dir'], '/\\') . DIRECTORY_SEPARATOR . $ref;
    @mkdir($dir, 0750, true);
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'submission.html', $htmlBody);
    foreach ($attach as $a) @copy($a['path'], $dir . DIRECTORY_SEPARATOR . $a['name']);
}

/* ---------- MIME message ---------- */
function build_mime($from, $to, $subject, $plain, $html, $attach) {
    $eol = "\r\n";
    $mixed = 'mix_' . md5(uniqid('', true));
    $alt = 'alt_' . md5(uniqid('', true));
    $headers = 'From: ' . $from . $eol
        . 'To: ' . $to . $eol
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
            . 'Content-Type: application/octet-stream; name="' . $a['name'] . '"' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol
            . 'Content-Disposition: ' . $dispo . $eol
            . ($a['cid'] ? 'Content-ID: <' . $a['cid'] . '>' . $eol : '')
            . $eol . $data . $eol;
    }
    $msg .= '--' . $mixed . '--' . $eol;
    $headers .= $eol; // blank line separating headers from MIME body
    return [$headers, $msg];
}

/* ---------- minimal SMTP client (STARTTLS + AUTH LOGIN) ---------- */
function smtp_send($cfg, $from, $rcpts, $headers, $msg, &$log) {
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
    $cmd = function($c, $expect, $quiet = false) use ($sock, $read, &$log) {
        fwrite($sock, $c . "\r\n");
        $log[] = $quiet ? '> ***' : '> ' . $c;
        return $read();
    };
    $greet = $read();
    if (strpos($greet, '220') !== 0) { fclose($sock); return false; }
    $ehloHost = parse_url($cfg['helo_name'] ?? 'kentishlodge.com', PHP_URL_HOST) ?: 'kentishlodge.com';
    $r = $cmd('EHLO ' . $ehloHost, '250');
    if (strpos($cfg['smtp_host'], 'ssl://') !== 0 && (strpos($r, 'STARTTLS') !== false)) {
        $cmd('STARTTLS', '220');
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($sock); $log[] = 'TLS failed'; return false; }
        $cmd('EHLO ' . $ehloHost, '250');
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN', '334');
        $cmd(base64_encode($user), '334', true);
        $r = $cmd(base64_encode($pass), '235', true);
        if (strpos($r, '235') !== 0) { fclose($sock); return false; }
    }
    $r = $cmd('MAIL FROM:<' . $from . '>', '250');
    if (strpos($r, '250') !== 0) { fclose($sock); return false; }
    foreach ($rcpts as $rc) {
        $r = $cmd('RCPT TO:<' . $rc . '>', '25');
        if (strpos($r, '25') !== 0) { fclose($sock); $log[] = 'rcpt failed: ' . $rc; return false; }
    }
    $cmd('DATA', '354');
    // dot-stuffing
    $body = preg_replace('/^\./m', '..', $headers . $msg);
    fwrite($sock, $body . "\r\n.\r\n");
    $r = $read();
    $ok = strpos($r, '250') === 0;
    $cmd('QUIT', '221');
    fclose($sock);
    return $ok;
}

$rcpts = [$to];
if ($ccEmail !== '') $rcpts[] = $ccEmail;
list($headers, $msgBody) = build_mime($from, implode(', ', $rcpts), $subject, $plain, $htmlBody, $attach);
$headers .= 'Cc: ' . $ccEmail . $eol;
if ($ccEmail !== '') $headers .= 'Reply-To: ' . $ccEmail . $eol;

$sent = false; $log = [];
if (!empty($cfg['smtp_host'])) {
    $sent = smtp_send($cfg, preg_replace('/^.*<(.*)>$/', '$1', $from), $rcpts, $headers, $msgBody, $log);
}
if (!$sent && !empty($cfg['fallback_mail'])) {
    $extra = 'MIME-Version: 1.0' . "\r\n" . 'From: ' . $from . "\r\n" . ($ccEmail ? 'Cc: ' . $ccEmail . "\r\n" : '');
    $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $plain, $extra);
}

foreach ($attach as $a) @unlink($a['path']);

if ($sent) {
    echo json_encode(['ok' => true, 'ref' => $ref]);
} else {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Mail server unavailable. Please use the download/Email option instead.', 'detail' => array_slice($log, -3)]);
}
