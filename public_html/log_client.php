<?php
// log_client.php - in root directory
declare(strict_types=1);

/**
 * LegalStudyBot – lightweight log sink (CSV + NDJSON)
 * Accepts JSON via POST (works with navigator.sendBeacon).
 * Writes to LOG_DIR/usage.csv and LOG_DIR/usage.ndjson.
 */

require __DIR__ . '/config.php';

// ---------- CORS / method guard (safe even for same-origin) ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allow  = 'https://lawschoolchatbot.com';
if ($origin && stripos($origin, 'lawschoolchatbot.com') !== false) {
    header("Access-Control-Allow-Origin: $allow");
    header("Vary: Origin");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'Method Not Allowed']);
    exit;
}

// ---------- Read body (sendBeacon is text/plain) ----------
// Cap request size so a hostile client can't fill the disk.
$MAX_BODY_BYTES = 64 * 1024;
$raw = file_get_contents('php://input', false, null, 0, $MAX_BODY_BYTES + 1) ?: '';
if (strlen($raw) > $MAX_BODY_BYTES) {
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'Payload too large']);
    exit;
}
$body = null;
if ($raw !== '') {
    $body = json_decode($raw, true);
}
if (!is_array($body)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'Bad JSON']);
    exit;
}

// ---------- Ensure log dir exists ----------
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0755, true);
}
if (!is_dir(LOG_DIR) || !is_writable(LOG_DIR)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'Log directory not writable', 'dir'=>LOG_DIR]);
    exit;
}

// ---------- Anonymous ID (cookie) ----------
$cookie_name = 'lsb_anon';
$anon_id = $_COOKIE[$cookie_name] ?? '';
if (!preg_match('/^u_[a-z0-9]{8,}$/i', $anon_id)) {
    try {
        $anon_id = 'u_' . bin2hex(random_bytes(6));
    } catch (\Throwable $e) {
        $anon_id = 'u_' . substr(sha1(uniqid('', true)), 0, 12);
    }
    // 1-year cookie
    @setcookie($cookie_name, $anon_id, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

// ---------- Normalize payload ----------
$kind   = mb_substr((string)($body['kind']   ?? 'event'), 0, 16);   // 'q' or 'a' preferred
$lang   = mb_substr((string)($body['lang']   ?? 'en'), 0, 8);
$thread = mb_substr((string)($body['thread'] ?? 'student'), 0, 64);
$text   = mb_substr((string)($body['text']   ?? ''), 0, 20000, 'UTF-8');
$ts_cli = (int)($body['ts'] ?? 0); // client ms

$now = time();
$ts   = $ts_cli > 0 ? (int)floor($ts_cli/1000) : $now;

$tz_hk = new DateTimeZone('Asia/Hong_Kong');
$dt_iso = (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('UTC'));
$dt_hkt = (new DateTimeImmutable('@'.$ts))->setTimezone($tz_hk);
$ts_iso = $dt_iso->format('c'); // ISO-8601 in UTC
$ts_hkt = $dt_hkt->format('d M Y, H:i:s').' HKT';

// ---------- CSV write (append; create header if file new/empty) ----------
$csv = USAGE_CSV;
$need_header = !file_exists($csv) || (is_file($csv) && filesize($csv) === 0);
$fh = @fopen($csv, 'ab');
if ($fh) {
    if ($need_header) {
        // Header must match what logs.php expects
        fputcsv($fh, ['ts','ts_iso','ts_hkt','kind','anon_id','thread','lang','text']);
    }
    // Flatten newlines in text for CSV
    $flat = preg_replace("/\r?\n/", " \\n ", $text);
    // Include ts as first field for compatibility with logs.php
    fputcsv($fh, [(string)$ts, $ts_iso, $ts_hkt, $kind, $anon_id, $thread, $lang, $flat]);
    fflush($fh);
    fclose($fh);
}

// ---------- NDJSON write (append) ----------
$ndj = USAGE_NDJSON;
$fh2 = @fopen($ndj, 'ab');
if ($fh2) {
    $row = [
        'ts'       => $ts,
        'ts_iso'   => $ts_iso,
        'ts_hkt'   => $ts_hkt,
        'kind'     => $kind,
        'anon_id'  => $anon_id,
        'thread'   => $thread,
        'lang'     => $lang,
        'text'     => $text,
        // Deliberately no IP or user agent: the consent gate promises
        // anonymous logging, so the anon_id cookie is the only identifier.
    ];
    fwrite($fh2, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
    fflush($fh2);
    fclose($fh2);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'      => true,
    'anon_id' => $anon_id,
    'ts_iso'  => $ts_iso,
]);