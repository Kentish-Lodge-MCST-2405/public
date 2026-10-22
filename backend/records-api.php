<?php
/**
 * Kentish Lodge MCST — records API
 * Backs the resident issue tracker (tools/issues.html), site visits
 * (tools/visits.html) and letter drafting (tools/letters.html) on
 * info.kentishlodge.com. One SQLite database, web-inaccessible file
 * storage, optional email notifications via Sparkpost.
 *
 * Deploy: drop next to form-submit.php on the VPS and create
 * records.config.php (see records.config.sample.php). Mail settings are
 * inherited from form-submit.config.php when present, so usually the
 * records config only needs: db_path, files_dir, admin_key,
 * allowed_origins.
 *
 * POST only. Actions (field `action` or JSON {action}):
 *   Public:   ping | report | track | register | login | me | followup
 *   Resident: token via Authorization: Bearer or field `token`
 *   Admin:    X-Admin-Key header or field `admin_key` —
 *             admin_list_issues | admin_get_issue | admin_add_event |
 *             admin_create_visit | admin_list_visits | admin_get_visit |
 *             admin_create_letter | admin_list_letters |
 *             admin_record_appeal | admin_decide_appeal | admin_stats
 *   Files:    file {id} — auth: admin key, or ref+pin of the owning issue
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/* ---------------- config ---------------- */
$base = [];
$fsCfg = __DIR__ . '/form-submit.config.php';
if (file_exists($fsCfg)) { $base = require $fsCfg; }
$rcCfg = __DIR__ . '/records.config.php';
$cfg = file_exists($rcCfg) ? array_merge($base, require $rcCfg) : $base;

function json_out($ok, $payload, $code = 200) {
    http_response_code($code);
    echo json_encode($ok ? array_merge(['ok' => true], $payload) : array_merge(['ok' => false], $payload));
    exit;
}
function json_fail($code, $msg, $extra = []) { json_out(false, array_merge(['error' => $msg], $extra), $code); }

/* ---------------- CORS ---------------- */
$origin = '';
if (!empty($cfg['allowed_origins'])) {
    $cand = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($cand === '' && isset($_SERVER['HTTP_REFERER'])) {
        $p = parse_url($_SERVER['HTTP_REFERER']);
        if ($p && isset($p['host'])) $cand = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) && $p['port'] ? ':' . $p['port'] : '');
    }
    if ($cand !== '' && in_array(rtrim($cand, '/'), array_map('rtrim', (array)$cfg['allowed_origins']), true)) {
        $origin = rtrim($cand, '/');
        header('Access-Control-Allow-Origin: ' . $origin);
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Key');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_fail(405, 'POST required.');

/* ---------------- input (multipart + json) ---------------- */
$IN = $_POST;
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $j = json_decode(file_get_contents('php://input'), true);
    if (is_array($j)) $IN = array_merge($IN, $j);
}
$action = preg_replace('/[^a-z0-9_]/', '', strtolower($IN['action'] ?? ''));
if ($action === '') json_fail(400, 'Missing action.');
if (($IN['website'] ?? '') !== '') json_out(true, ['ignored' => true]); // honeypot
$ts = (int)($IN['ts'] ?? 0);
if (in_array($action, ['report', 'register', 'followup'], true) && $ts > 0 && (time() * 1000 - $ts) < 2000)
    json_fail(400, 'Submitted too quickly.');

/* ---------------- auth context ---------------- */
$adminKey = (string)($cfg['admin_key'] ?? '');
$isAdmin = false;
$hk = $_SERVER['HTTP_X_ADMIN_KEY'] ?? ($IN['admin_key'] ?? '');
if ($adminKey !== '' && hash_equals($adminKey, (string)$hk)) $isAdmin = true;

$pdo = null; $resident = null;
function db($cfg) {
    global $pdo;
    if ($pdo) return $pdo;
    $path = $cfg['db_path'] ?? __DIR__ . '/records.sqlite';
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    init_db($pdo);
    return $pdo;
}
function init_db($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS residents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, unit_no TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
        pw_hash TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active",
        created_at TEXT NOT NULL, last_login_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        token TEXT PRIMARY KEY, resident_id INTEGER NOT NULL,
        created_at TEXT NOT NULL, expires_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS issues (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT NOT NULL UNIQUE, pin TEXT NOT NULL,
        resident_id INTEGER, source TEXT NOT NULL DEFAULT "resident",
        category TEXT NOT NULL, location TEXT, unit_no TEXT,
        title TEXT NOT NULL, description TEXT NOT NULL, occurred_on TEXT,
        reporter_name TEXT, reporter_email TEXT, reporter_phone TEXT,
        severity TEXT NOT NULL DEFAULT "minor",
        status TEXT NOT NULL DEFAULT "new",
        assigned_to TEXT, due_on TEXT,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL, closed_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS issue_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        issue_id INTEGER NOT NULL, kind TEXT NOT NULL,
        actor TEXT NOT NULL, note TEXT, internal INTEGER NOT NULL DEFAULT 0,
        data TEXT, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT NOT NULL UNIQUE, visit_date TEXT NOT NULL,
        inspector TEXT NOT NULL, areas TEXT, summary TEXT,
        created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS findings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visit_id INTEGER NOT NULL, issue_id INTEGER,
        category TEXT, severity TEXT NOT NULL DEFAULT "observation",
        location TEXT, description TEXT NOT NULL, action TEXT,
        created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS letters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT NOT NULL UNIQUE, template_id TEXT NOT NULL,
        unit_no TEXT, addressee TEXT, subject TEXT,
        fields TEXT NOT NULL, issue_ref TEXT, appeal_deadline TEXT,
        issued_by TEXT, delivery TEXT NOT NULL DEFAULT "printed",
        status TEXT NOT NULL DEFAULT "issued",
        created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS appeals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT NOT NULL UNIQUE, letter_ref TEXT, issue_ref TEXT,
        received_on TEXT NOT NULL, grounds TEXT,
        status TEXT NOT NULL DEFAULT "received",
        outcome TEXT, decided_on TEXT, decided_by TEXT, notes TEXT,
        created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        issue_id INTEGER, visit_id INTEGER, letter_id INTEGER,
        kind TEXT NOT NULL DEFAULT "photo", name TEXT, path TEXT NOT NULL,
        mime TEXT, bytes INTEGER, uploaded_by TEXT, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate (
        bucket TEXT PRIMARY KEY, window_start INTEGER NOT NULL, count INTEGER NOT NULL)');
}
function now() { return gmdate('Y-m-d\TH:i:s\Z'); }
function today() { return gmdate('Y-m-d'); }
function gen_ref($prefix) {
    return 'KL-' . $prefix . '-' . strtoupper(base_convert((string)time(), 10, 36)) . strtoupper(bin2hex(random_bytes(2)));
}
function rate_ok($pdo, $name, $max, $windowSec = 3600) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $bucket = $name . ':' . $ip;
    $now = time();
    $st = $pdo->prepare('SELECT window_start, count FROM rate WHERE bucket = ?');
    $st->execute([$bucket]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $now - (int)$row['window_start'] > $windowSec) {
        $pdo->prepare('INSERT INTO rate (bucket, window_start, count) VALUES (?, ?, 1)
                       ON CONFLICT(bucket) DO UPDATE SET window_start = excluded.window_start, count = 1')->execute([$bucket, $now]);
        return true;
    }
    if ((int)$row['count'] >= $max) return false;
    $pdo->prepare('UPDATE rate SET count = count + 1 WHERE bucket = ?')->execute([$bucket]);
    return true;
}
function field($IN, $k, $max = 2000) {
    $v = trim((string)($IN[$k] ?? ''));
    if (strlen($v) > $max) $v = substr($v, 0, $max);
    return $v;
}
function clean_multiline($s) { return preg_replace('/\r\n/', "\n", $s); }

/* ---------------- resident session ---------------- */
function resident_from_token($pdo, $IN) {
    $tok = '';
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'bearer ') === 0) $tok = trim(substr($h, 7));
    if ($tok === '') $tok = trim((string)($IN['token'] ?? ''));
    if ($tok === '' || !preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
    $st = $pdo->prepare('SELECT r.* FROM sessions s JOIN residents r ON r.id = s.resident_id
                         WHERE s.token = ? AND s.expires_at > ? AND r.status = "active"');
    $st->execute([$tok, now()]);
    $res = $st->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/* ---------------- file storage ---------------- */
function store_upload($pdo, $cfg, $file, $cols, $kind, $by) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $maxBytes = (isset($cfg['max_file_mb']) ? (int)$cfg['max_file_mb'] : 8) * 1024 * 1024;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) json_fail(400, 'File too large or empty: ' . basename($file['name']));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf', 'application/octet-stream'];
    $ext = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) json_fail(400, 'File type not allowed: .' . $ext);
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (finfo_file($finfo, $file['tmp_name']) ?: 'application/octet-stream') : 'application/octet-stream';
    if ($finfo) finfo_close($finfo);
    if (!in_array($mime, $allowedMime, true)) json_fail(400, 'File content not allowed: ' . basename($file['name']));
    $dir = rtrim($cfg['files_dir'] ?? (__DIR__ . '/records-files'), '/\\');
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $stored)) json_fail(500, 'Could not store upload.');
    $st = $pdo->prepare('INSERT INTO files (issue_id, visit_id, letter_id, kind, name, path, mime, bytes, uploaded_by, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$cols['issue_id'] ?? null, $cols['visit_id'] ?? null, $cols['letter_id'] ?? null,
                  $kind, substr(basename($file['name']), 0, 120), $stored, $mime, (int)$file['size'], $by, now()]);
    return (int)$pdo->lastInsertId();
}
function accept_photos($pdo, $cfg, $inputNames, $cols, $by, $max = 6) {
    $ids = [];
    foreach ($_FILES as $k => $f) {
        if (!in_array($k, $inputNames, true) && !preg_match('/^photo\d*$/', $k)) continue;
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (count($ids) >= $max) json_fail(400, 'Too many files (max ' . $max . ').');
        $id = store_upload($pdo, $cfg, $f, $cols, 'photo', $by);
        if ($id) $ids[] = $id;
    }
    return $ids;
}

/* ---------------- email (best-effort) ---------------- */
function notify($cfg, $to, $subject, $plain) {
    if (!$to || empty($cfg['sparkpost_key'])) return;
    $from = $cfg['from'] ?? '';
    if (!$from) return;
    $body = [
        'options' => ['transactional' => true],
        'recipients' => [['address' => ['email' => $to]]],
        'content' => [
            'from' => ['name' => $cfg['from_name'] ?? 'Kentish Lodge MCST', 'email' => $from],
            'subject' => $subject,
            'text' => $plain,
        ],
    ];
    $ch = curl_init('https://' . ($cfg['sparkpost_host'] ?? 'api.sparkpost.com') . '/api/v1/transmissions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $cfg['sparkpost_key'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
function notify_office($cfg, $subject, $plain) { notify($cfg, $cfg['to'] ?? '', $subject, $plain); }

/* ================================================================
 *  PUBLIC ACTIONS
 * ================================================================ */
if ($action === 'ping') {
    json_out(true, ['service' => 'kentish-records', 'version' => '1.0', 'time' => now()]);
}

if ($action === 'report') {
    if (!rate_ok(db($cfg), 'report', 10)) json_fail(429, 'Too many submissions. Please try again later.');
    $pdo = db($cfg);
    $resident = resident_from_token($pdo, $IN);
    $category = field($IN, 'category', 60);
    $title = field($IN, 'title', 140);
    $description = clean_multiline(field($IN, 'description', 6000));
    $location = field($IN, 'location', 140);
    $unit = field($IN, 'unit_no', 20);
    $name = field($IN, 'reporter_name', 120);
    $email = field($IN, 'reporter_email', 160);
    $phone = field($IN, 'reporter_phone', 40);
    $occurred = field($IN, 'occurred_on', 10);
    $cats = ['building','water','electrical','lift','cleanliness','parking','security','garden','bylaw','other'];
    if (!in_array($category, $cats, true)) json_fail(400, 'Please choose a category.');
    if ($title === '' || $description === '') json_fail(400, 'Title and description are required.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_fail(400, 'Invalid email address.');
    if (($IN['consent'] ?? '') !== 'yes') json_fail(400, 'Consent is required to submit a report.');
    $ref = gen_ref('IR');
    $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $st = $pdo->prepare('INSERT INTO issues (ref, pin, resident_id, source, category, location, unit_no, title, description,
                         occurred_on, reporter_name, reporter_email, reporter_phone, severity, status, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "minor", "new", ?, ?)');
    $st->execute([$ref, $pin, $resident['id'] ?? null, $resident ? 'resident' : 'public',
                  $category, $location, $unit, $title, $description,
                  $occurred !== '' ? $occurred : null,
                  $name !== '' ? $name : null, $email !== '' ? $email : null, $phone !== '' ? $phone : null,
                  now(), now()]);
    $issueId = (int)$pdo->lastInsertId();
    $ev = $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, created_at) VALUES (?, "created", ?, ?, ?)');
    $ev->execute([$issueId, ($resident ? $resident['name'] : ($name ?: 'Resident')), 'Report submitted', now()]);
    accept_photos($pdo, $cfg, ['photos', 'photo'], ['issue_id' => $issueId], $resident ? 'resident:' . $resident['id'] : 'reporter');
    notify_office($cfg, '[IR] New issue ' . $ref . ' — ' . $title,
        "New issue report " . $ref . "\nCategory: " . $category . "\nLocation: " . ($location ?: '-') . "  Unit: " . ($unit ?: '-') .
        "\nReported by: " . ($name ?: 'resident account') . ($email ? " <$email>" : '') .
        "\n\n" . $description . "\n\nManage: " . ($cfg['site_base'] ?? 'https://info.kentishlodge.com') . "/tools/visits.html");
    if ($email) notify($cfg, $email, 'Kentish Lodge — issue received (' . $ref . ')',
        "Thank you. Your report has been recorded.\n\nReference: " . $ref . "\nTracking PIN: " . $pin .
        "\nTrack it at " . ($cfg['site_base'] ?? 'https://info.kentishlodge.com') . "/tools/issues.html" .
        "\n\nKeep this PIN to follow up. You may also add information later with the reference and PIN.");
    json_out(true, ['ref' => $ref, 'pin' => $pin]);
}

if ($action === 'track') {
    $pdo = db($cfg);
    if (!rate_ok($pdo, 'track', 60)) json_fail(429, 'Too many lookups.');
    $ref = strtoupper(field($IN, 'ref', 30));
    $pin = field($IN, 'pin', 6);
    $st = $pdo->prepare('SELECT * FROM issues WHERE ref = ?');
    $st->execute([$ref]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    if (!$issue || !hash_equals((string)$issue['pin'], $pin)) json_fail(404, 'Reference or PIN not recognised.');
    json_out(true, ['issue' => public_issue($pdo, $issue, true)]);
}

if ($action === 'register') {
    $pdo = db($cfg);
    if (!rate_ok($pdo, 'register', 5)) json_fail(429, 'Too many registrations.');
    $name = field($IN, 'name', 120);
    $unit = field($IN, 'unit_no', 20);
    $email = strtolower(field($IN, 'email', 160));
    $pw = (string)($IN['password'] ?? '');
    if ($name === '' || $unit === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8)
        json_fail(400, 'Name, unit, valid email and a password of at least 8 characters are required.');
    if (($IN['consent'] ?? '') !== 'yes') json_fail(400, 'Consent is required.');
    $st = $pdo->prepare('SELECT id FROM residents WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) json_fail(409, 'An account with this email already exists. Please sign in.');
    $pdo->prepare('INSERT INTO residents (name, unit_no, email, pw_hash, status, created_at) VALUES (?, ?, ?, ?, "active", ?)')
        ->execute([$name, $unit, $email, password_hash($pw, PASSWORD_DEFAULT), now()]);
    notify($cfg, $email, 'Welcome to the Kentish Lodge resident portal',
        "Hello " . $name . ",\n\nYour resident account for unit " . $unit . " is active.\nSign in at " .
        ($cfg['site_base'] ?? 'https://info.kentishlodge.com') . "/tools/issues.html to report and follow issues.\n\nIf you did not create this account, please contact the management office.");
    json_out(true, ['created' => true]);
}

if ($action === 'login') {
    $pdo = db($cfg);
    if (!rate_ok($pdo, 'login', 20)) json_fail(429, 'Too many sign-in attempts. Try again later.');
    $email = strtolower(field($IN, 'email', 160));
    $pw = (string)($IN['password'] ?? '');
    $st = $pdo->prepare('SELECT * FROM residents WHERE email = ? AND status = "active"');
    $st->execute([$email]);
    $res = $st->fetch(PDO::FETCH_ASSOC);
    if (!$res || !password_verify($pw, $res['pw_hash'])) json_fail(401, 'Email or password not recognised.');
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO sessions (token, resident_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$token, $res['id'], now(), gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400)]);
    $pdo->prepare('UPDATE residents SET last_login_at = ? WHERE id = ?')->execute([now(), $res['id']]);
    json_out(true, ['token' => $token, 'name' => $res['name'], 'unit_no' => $res['unit_no']]);
}

if ($action === 'me') {
    $pdo = db($cfg);
    $resident = resident_from_token($pdo, $IN);
    if (!$resident) json_fail(401, 'Please sign in.');
    $st = $pdo->prepare('SELECT id, ref, category, title, location, status, severity, created_at, updated_at, closed_at
                         FROM issues WHERE resident_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$resident['id']]);
    json_out(true, ['name' => $resident['name'], 'unit_no' => $resident['unit_no'], 'email' => $resident['email'],
                    'issues' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'followup') {
    $pdo = db($cfg);
    if (!rate_ok($pdo, 'followup', 20)) json_fail(429, 'Too many follow-ups.');
    $resident = resident_from_token($pdo, $IN);
    $ref = strtoupper(field($IN, 'ref', 30));
    $msg = clean_multiline(field($IN, 'message', 4000));
    if ($msg === '') json_fail(400, 'Message is required.');
    $st = $pdo->prepare('SELECT * FROM issues WHERE ref = ?');
    $st->execute([$ref]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    if (!$issue) json_fail(404, 'Reference not recognised.');
    if ($resident) {
        if ((int)$issue['resident_id'] !== (int)$resident['id']) json_fail(403, 'This report belongs to another account.');
        $actor = 'resident:' . $resident['name'];
    } else {
        if (!hash_equals((string)$issue['pin'], field($IN, 'pin', 6))) json_fail(403, 'Reference or PIN not recognised.');
        $actor = $issue['reporter_name'] ?: 'Reporter';
    }
    $ev = $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, created_at) VALUES (?, "followup", ?, ?, ?)');
    $ev->execute([(int)$issue['id'], $actor, $msg, now()]);
    accept_photos($pdo, $cfg, ['photos', 'photo'], ['issue_id' => (int)$issue['id']], $actor);
    $pdo->prepare('UPDATE issues SET updated_at = ? WHERE id = ?')->execute([now(), $issue['id']]);
    notify_office($cfg, '[IR] Follow-up on ' . $ref, $msg . "\n\n— " . $actor . " (ref " . $ref . ")");
    json_out(true, ['added' => true]);
}

if ($action === 'file') {
    $pdo = db($cfg);
    $id = (int)field($IN, 'id', 12);
    $st = $pdo->prepare('SELECT f.*, i.ref AS iref, i.pin AS ipin FROM files f
                         LEFT JOIN issues i ON i.id = f.issue_id WHERE f.id = ?');
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) json_fail(404, 'File not found.');
    $allowed = $isAdmin;
    if (!$allowed && $f['issue_id']) {
        $ref = strtoupper(field($IN, 'ref', 30));
        $pin = field($IN, 'pin', 6);
        if ($ref === $f['iref'] && $pin !== '' && hash_equals((string)$f['ipin'], $pin)) $allowed = true;
    }
    if (!$allowed) json_fail(403, 'Not authorised for this file.');
    $dir = rtrim($cfg['files_dir'] ?? (__DIR__ . '/records-files'), '/\\');
    $path = $dir . DIRECTORY_SEPARATOR . $f['path'];
    if (!is_file($path)) json_fail(404, 'File missing.');
    header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $f['name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ================================================================
 *  ADMIN ACTIONS
 * ================================================================ */
if (!$isAdmin) json_fail(403, 'Admin key required.');

function public_issue($pdo, $issue, $withEvents) {
    unset($issue['pin'], $issue['resident_id'], $issue['reporter_email'], $issue['reporter_phone']);
    if ($withEvents) {
        $st = $pdo->prepare('SELECT kind, actor, note, internal, created_at FROM issue_events
                             WHERE issue_id = ? AND internal = 0 ORDER BY id');
        $st->execute([(int)$issue['id']]);
        $issue['events'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $st = $pdo->prepare('SELECT id, name, kind FROM files WHERE issue_id = ?');
    $st->execute([(int)$issue['id']]);
    $issue['photos'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $issue;
}
function set_status($pdo, $cfg, $issueId, $newStatus, $actor) {
    $statuses = ['new','acknowledged','scheduled','in_progress','awaiting_confirmation','resolved','closed'];
    if (!in_array($newStatus, $statuses, true)) json_fail(400, 'Invalid status.');
    $st = $pdo->prepare('SELECT * FROM issues WHERE id = ?');
    $st->execute([$issueId]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    if (!$issue) json_fail(404, 'Issue not found.');
    $closed = in_array($newStatus, ['resolved', 'closed'], true) ? now() : null;
    $pdo->prepare('UPDATE issues SET status = ?, closed_at = COALESCE(?, closed_at), updated_at = ? WHERE id = ?')
        ->execute([$newStatus, $closed, now(), $issueId]);
    $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, created_at) VALUES (?, "status", ?, ?, ?)')
        ->execute([$issueId, $actor, 'Status → ' . $newStatus, now()]);
    if (in_array($newStatus, ['resolved', 'closed'], true) && $issue['reporter_email']) {
        notify($cfg, $issue['reporter_email'], 'Kentish Lodge — issue ' . $issue['ref'] . ' ' . $newStatus,
            "Your report " . $issue['ref'] . " (" . $issue['title'] . ") has been marked " . $newStatus . ".\n" .
            "View the record and confirm at " . ($cfg['site_base'] ?? 'https://info.kentishlodge.com') . "/tools/issues.html with your reference and PIN.\n" .
            "If the issue persists, you may reopen it by adding a follow-up.");
    }
    return $issue;
}

if ($action === 'admin_list_issues') {
    $pdo = db($cfg);
    $status = field($IN, 'status', 30);
    $q = field($IN, 'q', 60);
    $limit = min(200, max(1, (int)field($IN, 'limit', 6, 50)));
    $sql = 'SELECT id, ref, category, location, unit_no, title, status, severity, source, assigned_to, due_on,
                   reporter_name, created_at, updated_at, closed_at FROM issues';
    $where = []; $args = [];
    if ($status !== '' && $status !== 'all') { $where[] = 'status = ?'; $args[] = $status; }
    if ($q !== '') { $where[] = '(ref LIKE ? OR title LIKE ? OR unit_no LIKE ? OR location LIKE ?)'; $qq = "%$q%"; array_push($args, $qq, $qq, $qq, $qq); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql); $st->execute($args);
    json_out(true, ['issues' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'admin_get_issue') {
    $pdo = db($cfg);
    $st = $pdo->prepare('SELECT * FROM issues WHERE ref = ?');
    $st->execute([strtoupper(field($IN, 'ref', 30))]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    if (!$issue) json_fail(404, 'Issue not found.');
    $ev = $pdo->prepare('SELECT kind, actor, note, internal, created_at FROM issue_events WHERE issue_id = ? ORDER BY id');
    $ev->execute([(int)$issue['id']]);
    $issue['events'] = $ev->fetchAll(PDO::FETCH_ASSOC);
    $ph = $pdo->prepare('SELECT id, name, kind FROM files WHERE issue_id = ?');
    $ph->execute([(int)$issue['id']]);
    $issue['photos'] = $ph->fetchAll(PDO::FETCH_ASSOC);
    $lt = $pdo->prepare('SELECT ref, template_id, subject, status, created_at FROM letters WHERE issue_ref = ? ORDER BY id DESC');
    $lt->execute([$issue['ref']]);
    $issue['letters'] = $lt->fetchAll(PDO::FETCH_ASSOC);
    json_out(true, ['issue' => $issue]);
}

if ($action === 'admin_add_event') {
    $pdo = db($cfg);
    $ref = strtoupper(field($IN, 'ref', 30));
    $st = $pdo->prepare('SELECT * FROM issues WHERE ref = ?');
    $st->execute([$ref]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    if (!$issue) json_fail(404, 'Issue not found.');
    $kind = field($IN, 'kind', 20);
    $actor = 'management:' . field($IN, 'officer', 80);
    $note = clean_multiline(field($IN, 'note', 4000));
    $internal = ($IN['internal'] ?? '') === 'yes' ? 1 : 0;
    $allowedKinds = ['ack', 'note', 'action', 'status', 'close', 'reopen', 'letter'];
    if (!in_array($kind, $allowedKinds, true)) json_fail(400, 'Invalid event kind.');
    if ($kind === 'status' || $kind === 'close') {
        set_status($pdo, $cfg, (int)$issue['id'], $kind === 'close' ? 'closed' : field($IN, 'status', 30), $actor);
        if ($note !== '') {
            $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, internal, created_at) VALUES (?, "note", ?, ?, ?, ?)')
                ->execute([(int)$issue['id'], $actor, $note, $internal, now()]);
        }
    }
    if ($kind === 'reopen') {
        $pdo->prepare('UPDATE issues SET status = "in_progress", closed_at = NULL, updated_at = ? WHERE id = ?')->execute([now(), $issue['id']]);
        $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, created_at) VALUES (?, "reopen", ?, ?, ?)')->execute([(int)$issue['id'], $actor, $note ?: 'Reopened', now()]);
    }
    if ($kind === 'ack') {
        $pdo->prepare('UPDATE issues SET status = CASE WHEN status = "new" THEN "acknowledged" ELSE status END, updated_at = ? WHERE id = ?')->execute([now(), $issue['id']]);
    }
    if (in_array($kind, ['ack', 'note', 'action'], true)) {
        if ($note === '') json_fail(400, 'Note is required.');
        $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, internal, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int)$issue['id'], $kind, $actor, $note, $internal, now()]);
    }
    if ($kind === 'action') {
        $due = field($IN, 'due_on', 10);
        $sev = field($IN, 'severity', 20);
        $asn = field($IN, 'assigned_to', 80);
        $pdo->prepare('UPDATE issues SET due_on = COALESCE(?, due_on),
                        severity = CASE WHEN ? IN ("observation","minor","major","bylaw") THEN ? ELSE severity END,
                        assigned_to = COALESCE(NULLIF(?, ""), assigned_to), updated_at = ? WHERE id = ?')
            ->execute([$due ?: null, $sev, $sev, $asn, now(), $issue['id']]);
    }
    accept_photos($pdo, $cfg, ['photos', 'photo', 'evidence'], ['issue_id' => (int)$issue['id']], $actor, 8);
    json_out(true, ['updated' => true]);
}

if ($action === 'admin_create_visit') {
    $pdo = db($cfg);
    $visitDate = field($IN, 'visit_date', 10);
    $inspector = field($IN, 'inspector', 120);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate) || $inspector === '') json_fail(400, 'Visit date and inspector are required.');
    $areas = $IN['areas'] ?? [];
    if (is_string($areas)) $areas = json_decode($areas, true) ?: [];
    $areas = array_values(array_filter(array_map('strval', (array)$areas), function ($a) { return trim($a) !== ''; }));
    $ref = gen_ref('SV');
    $pdo->prepare('INSERT INTO visits (ref, visit_date, inspector, areas, summary, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$ref, $visitDate, $inspector, json_encode(array_slice($areas, 0, 30), JSON_UNESCAPED_UNICODE),
                   clean_multiline(field($IN, 'summary', 4000)), now()]);
    $visitId = (int)$pdo->lastInsertId();
    accept_photos($pdo, $cfg, ['photos', 'photo'], ['visit_id' => $visitId], 'management:' . $inspector, 8);
    $createdIssues = [];
    $findings = (array)($IN['findings'] ?? []);
    if (is_string($IN['findings'] ?? null)) { $findings = json_decode($IN['findings'], true) ?: []; }
    foreach (array_slice($findings, 0, 30) as $idx => $f) {
        $f = (array)$f;
        $iid = null;
        $desc = clean_multiline(substr(trim((string)($f['description'] ?? '')), 0, 4000));
        if ($desc === '') continue;
        $sev = in_array($f['severity'] ?? '', ['observation', 'minor', 'major', 'bylaw'], true) ? $f['severity'] : 'observation';
        $issueRef = null;
        if (!empty($f['create_issue'])) {
            $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $iref = gen_ref('IR');
            $title = substr('Site visit ' . $visitDate . ': ' . trim((string)($f['location'] ?? '')), 0, 140);
            $pdo->prepare('INSERT INTO issues (ref, pin, source, category, location, title, description, severity, status, reporter_name, created_at, updated_at)
                           VALUES (?, ?, "visit", ?, ?, ?, ?, ?, "new", ?, ?, ?)')
                ->execute([$iref, $pin, (string)($f['category'] ?? 'other'), (string)($f['location'] ?? ''), $title, $desc,
                           $sev === 'bylaw' ? 'bylaw' : $sev, 'Site visit ' . $ref . ' (' . $inspector . ')', now(), now()]);
            $iid = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, created_at) VALUES (?, "created", ?, ?, ?)')
                ->execute([$iid, 'management:' . $inspector, 'Raised from site visit ' . $ref . ' — ' . $desc, now()]);
            $issueRef = $iref;
            $createdIssues[] = $iref;
        }
        $pdo->prepare('INSERT INTO findings (visit_id, issue_id, category, severity, location, description, action, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$visitId, $iid ?? null, (string)($f['category'] ?? ''), $sev, (string)($f['location'] ?? ''),
                       $desc, clean_multiline(substr(trim((string)($f['action'] ?? '')), 0, 2000)), now()]);
        foreach ($_FILES as $k => $ff) {
            if (!preg_match('/^finding' . (int)$idx . '_photo\d*$/', $k)) continue;
            if (($ff['error'] ?? 1) === UPLOAD_ERR_OK) {
                store_upload($pdo, $cfg, $ff, ['issue_id' => $iid ?? null, 'visit_id' => $visitId], 'photo', 'management:' . $inspector);
            }
        }
    }
    json_out(true, ['ref' => $ref, 'created_issues' => $createdIssues]);
}

if ($action === 'admin_list_visits') {
    $pdo = db($cfg);
    $limit = min(100, max(1, (int)field($IN, 'limit', 6, 50)));
    $st = $pdo->prepare('SELECT v.*, (SELECT COUNT(*) FROM findings f WHERE f.visit_id = v.id) AS finding_count
                         FROM visits v ORDER BY v.id DESC LIMIT ' . $limit);
    $st->execute();
    $visits = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($visits as &$v) { $v['areas'] = json_decode($v['areas'] ?: '[]', true) ?: []; }
    json_out(true, ['visits' => $visits]);
}

if ($action === 'admin_create_letter') {
    $pdo = db($cfg);
    $templateId = field($IN, 'template_id', 40);
    $fieldsIn = $IN['fields'] ?? [];
    if (is_string($fieldsIn)) $fieldsIn = json_decode($fieldsIn, true) ?: [];
    $fields = [];
    foreach ((array)$fieldsIn as $k => $v) { $k = preg_replace('/[^a-z0-9_]/i', '', $k); if ($k !== '') $fields[$k] = substr(trim((string)$v), 0, 4000); }
    if ($templateId === '') json_fail(400, 'Template is required.');
    $ref = gen_ref('LTR');
    $issueRef = strtoupper(field($IN, 'issue_ref', 30));
    if ($issueRef !== '') {
        $st = $pdo->prepare('SELECT id FROM issues WHERE ref = ?'); $st->execute([$issueRef]);
        if (!$st->fetch()) json_fail(404, 'Linked issue not found: ' . $issueRef);
    } else { $issueRef = null; }
    $deadline = field($IN, 'appeal_deadline', 10);
    $pdo->prepare('INSERT INTO letters (ref, template_id, unit_no, addressee, subject, fields, issue_ref, appeal_deadline, issued_by, delivery, status, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "issued", ?)')
        ->execute([$ref, $templateId, field($IN, 'unit_no', 20), field($IN, 'addressee', 120), field($IN, 'subject', 200),
                   json_encode($fields, JSON_UNESCAPED_UNICODE), $issueRef, $deadline ?: null,
                   field($IN, 'issued_by', 120), in_array($IN['delivery'] ?? '', ['printed', 'emailed', 'both'], true) ? $IN['delivery'] : 'printed', now()]);
    if ($issueRef) {
        $st = $pdo->prepare('SELECT id FROM issues WHERE ref = ?'); $st->execute([$issueRef]);
        $iid = (int)$st->fetchColumn();
        $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, created_at) VALUES (?, "letter", ?, ?, ?)')
            ->execute([$iid, 'management:' . field($IN, 'issued_by', 120), 'Letter issued (' . $ref . ', ' . $templateId . ')', now()]);
        $pdo->prepare('UPDATE issues SET updated_at = ? WHERE id = ?')->execute([now(), $iid]);
    }
    $delivery = $IN['delivery'] ?? 'printed';
    if (in_array($delivery, ['emailed', 'both'], true) && !empty($fields['addressee_email'])) {
        notify($cfg, $fields['addressee_email'], 'Kentish Lodge MCST — ' . ($fields['subject'] ?: 'official notice') . ' (' . $ref . ')',
            ($IN['body_text'] ?? 'Please see the attached official letter, reference ' . $ref . '.') . "\n\n— Kentish Lodge MCST Management Office");
    }
    json_out(true, ['ref' => $ref]);
}

if ($action === 'admin_list_letters') {
    $pdo = db($cfg);
    $limit = min(200, max(1, (int)field($IN, 'limit', 6, 50)));
    $st = $pdo->prepare('SELECT ref, template_id, unit_no, addressee, subject, issue_ref, appeal_deadline, issued_by, delivery, status, created_at
                         FROM letters ORDER BY id DESC LIMIT ' . $limit);
    $st->execute();
    json_out(true, ['letters' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'admin_record_appeal') {
    $pdo = db($cfg);
    $letterRef = strtoupper(field($IN, 'letter_ref', 30));
    $issueRef = strtoupper(field($IN, 'issue_ref', 30));
    if ($letterRef === '' && $issueRef === '') json_fail(400, 'Letter or issue reference is required.');
    $ref = gen_ref('AP');
    $pdo->prepare('INSERT INTO appeals (ref, letter_ref, issue_ref, received_on, grounds, status, created_at)
                   VALUES (?, ?, ?, ?, ?, "received", ?)')
        ->execute([$ref, $letterRef ?: null, $issueRef ?: null, field($IN, 'received_on', 10) ?: today(),
                   clean_multiline(field($IN, 'grounds', 4000)), now()]);
    if ($letterRef) $pdo->prepare('UPDATE letters SET status = "appealed" WHERE ref = ?')->execute([$letterRef]);
    json_out(true, ['ref' => $ref]);
}

if ($action === 'admin_list_appeals') {
    $pdo = db($cfg);
    $limit = min(200, max(1, (int)field($IN, 'limit', 6, 50)));
    $st = $pdo->prepare('SELECT ref, letter_ref, issue_ref, received_on, grounds, status, outcome, decided_on, decided_by
                         FROM appeals ORDER BY id DESC LIMIT ' . $limit);
    $st->execute();
    json_out(true, ['appeals' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'admin_decide_appeal') {
    $pdo = db($cfg);
    $ref = strtoupper(field($IN, 'ref', 30));
    $outcome = field($IN, 'outcome', 20);
    if (!in_array($outcome, ['upheld', 'varied', 'dismissed', 'withdrawn'], true)) json_fail(400, 'Invalid outcome.');
    $st = $pdo->prepare('SELECT * FROM appeals WHERE ref = ?'); $st->execute([$ref]);
    $ap = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ap) json_fail(404, 'Appeal not found.');
    $pdo->prepare('UPDATE appeals SET status = "decided", outcome = ?, decided_on = ?, decided_by = ?, notes = ? WHERE ref = ?')
        ->execute([$outcome, field($IN, 'decided_on', 10) ?: today(), field($IN, 'decided_by', 120), clean_multiline(field($IN, 'notes', 4000)), $ref]);
    if ($ap['letter_ref']) $pdo->prepare('UPDATE letters SET status = ? WHERE ref = ?')->execute([$outcome === 'dismissed' ? 'upheld' : $outcome, $ap['letter_ref']]);
    if ($ap['issue_ref']) {
        $st = $pdo->prepare('SELECT id FROM issues WHERE ref = ?'); $st->execute([$ap['issue_ref']]);
        if ($iid = $st->fetchColumn()) {
            $pdo->prepare('INSERT INTO issue_events (issue_id, kind, actor, note, created_at) VALUES (?, "note", ?, ?, ?)')
                ->execute([(int)$iid, 'council', 'Appeal ' . $ref . ' decided: ' . $outcome . (field($IN, 'notes', 4000) ? ' — ' . field($IN, 'notes', 4000) : ''), now()]);
        }
    }
    json_out(true, ['decided' => $outcome]);
}

if ($action === 'admin_stats') {
    $pdo = db($cfg);
    $by = [];
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM issues GROUP BY status') as $r) $by[$r['status']] = (int)$r['c'];
    $overdue = 0;
    foreach ($pdo->query("SELECT COUNT(*) c FROM issues WHERE due_on IS NOT NULL AND due_on < '" . today() . "'
                          AND status NOT IN ('resolved','closed')") as $r) $overdue = (int)$r['c'];
    $letters = 0; foreach ($pdo->query('SELECT COUNT(*) c FROM letters') as $r) $letters = (int)$r['c'];
    $appealsOpen = 0; foreach ($pdo->query("SELECT COUNT(*) c FROM appeals WHERE status = 'received'") as $r) $appealsOpen = (int)$r['c'];
    $visits = 0; foreach ($pdo->query('SELECT COUNT(*) c FROM visits') as $r) $visits = (int)$r['c'];
    json_out(true, ['issues_by_status' => $by, 'overdue' => $overdue, 'letters' => $letters,
                    'appeals_open' => $appealsOpen, 'visits' => $visits]);
}

json_fail(400, 'Unknown action: ' . $action);
