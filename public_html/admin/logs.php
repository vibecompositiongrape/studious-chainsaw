<?php
declare(strict_types=1);
require __DIR__ . '/_auth.php';

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!lsb_is_admin()) {
  header('Location: login.php?next=logs.php');
  exit;
}

// Define paths from config
$CSV_FILE = defined('USAGE_CSV') ? USAGE_CSV : '';
$NDJ_FILE = defined('USAGE_NDJSON') ? USAGE_NDJSON : '';

// ---- Download raw log files ----
if (isset($_GET['download'])) {
  $kind = (string)$_GET['download'];
  $path = '';
  $name = '';
  $type = 'application/octet-stream';

  if ($kind === 'csv') {
    $path = $CSV_FILE;
    $name = 'legalstudybot-usage.csv';
    $type = 'text/csv; charset=utf-8';
  } elseif ($kind === 'ndjson') {
    $path = $NDJ_FILE;
    $name = 'legalstudybot-usage.ndjson';
    $type = 'application/x-ndjson; charset=utf-8';
  } else {
    http_response_code(400);
    echo 'Unsupported download type';
    exit;
  }

  if (!$path || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    echo 'Log file not found';
    exit;
  }

  header('Content-Type: ' . $type);
  header('Content-Length: ' . filesize($path));
  header('Content-Disposition: attachment; filename="' . $name . '"');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
  exit;
}

// ---- Reset logs (POST-only, CSRF-protected: a GET link could be
// triggered by any page an admin visits) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
  lsb_verify_csrf();
  if ($CSV_FILE && is_file($CSV_FILE)) {
    // Recreate the file with only the header
    file_put_contents($CSV_FILE, "ts,ts_iso,ts_hkt,kind,anon_id,thread,lang,text\n");
  }
  if ($NDJ_FILE && is_file($NDJ_FILE)) {
    // Truncate the file
    file_put_contents($NDJ_FILE, "");
  }
  // Redirect back to the logs page without the reset parameter
  header("Location: logs.php?reset=done");
  exit;
}


function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 100; // Increased default limit

// Read and process CSV data
$csv_rows = [];
if ($CSV_FILE && is_file($CSV_FILE) && filesize($CSV_FILE) > 0 && ($fh = fopen($CSV_FILE, 'rb')) !== false) {
  // Read the header row
  $header = fgetcsv($fh, 0, ',', '"', ''); 
  if ($header) {
      $headerCount = count($header);
      while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
          if (count($row) === $headerCount) {
              $assoc = array_combine($header, $row);
              if (is_array($assoc)) {
                  $csv_rows[] = $assoc;
              }
          }
      }
  }
  fclose($fh);
}
if (!empty($csv_rows)) {
    usort($csv_rows, fn($a,$b) => strcmp($b['ts_iso'] ?? '', $a['ts_iso'] ?? ''));
    $csv_rows = array_slice($csv_rows, 0, $limit);
}

// Read and process NDJSON data
$ndj_rows = [];
if ($NDJ_FILE && is_file($NDJ_FILE)) {
  $lines = @file($NDJ_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  foreach ($lines as $line) {
    $j = json_decode($line, true);
    if (is_array($j)) $ndj_rows[] = $j;
  }
}
if(!empty($ndj_rows)){
    usort($ndj_rows, fn($a,$b) => (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0));
    $ndj_rows = array_slice($ndj_rows, 0, $limit);
}


// File sizes
$csv_size = ($CSV_FILE && is_file($CSV_FILE)) ? filesize($CSV_FILE) : 0;
$ndj_size = ($NDJ_FILE && is_file($NDJ_FILE)) ? filesize($NDJ_FILE) : 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Logs — LegalStudyBot Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;margin:24px;color:#111}
  h1{margin:0 0 .25rem}
  .muted{color:#6b7280}
  .bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:.5rem 0 1rem}
  .btn{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .7rem;border:1px solid #d1d5db;border-radius:8px;background:#fff;text-decoration:none;color:#111}
  .btn.primary{background:#111;color:#fff;border-color:#111}
  .btn.danger{background:#fee;color:#b00;border-color:#fca5a5}
  .btn:focus{outline:2px solid #2563eb;outline-offset:2px}
  table{width:100%;border-collapse:collapse;margin:10px 0 24px}
  th,td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left;vertical-align:top}
  th{background:#f3f4f6}
  tr.q td{background:#eef5ff}
  tr.a td{background:#f1f5f9}
  .empty-state{text-align:center;padding:40px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin:20px 0;}
  pre{background:#f8fafc;border:1px solid #e5e7eb;padding:10px;border-radius:8px;overflow:auto}
  code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.95em}
</style>
</head>
<body>

<h1>Logs — LegalStudyBot</h1>
<div class="muted">Newest first — showing up to <?=h((string)$limit)?> entries</div>

<div class="bar">
  <a class="btn" href="?limit=10">Last 10</a>
  <a class="btn" href="?limit=50">Last 50</a>
  <a class="btn" href="?limit=200">Last 200</a>
  <a class="btn primary" href="logs.php?download=csv">Download CSV</a>
  <a class="btn" href="logs.php?download=ndjson">Download NDJSON</a>
  <form method="post" action="logs.php" data-confirm="Clear ALL logs?" style="display:inline">
    <input type="hidden" name="csrf_token" value="<?=h(lsb_csrf_token())?>">
    <input type="hidden" name="action" value="reset">
    <button type="submit" class="btn danger">Reset Logs</button>
  </form>
</div>
<script src="admin.js" defer></script>

<?php if (isset($_GET['reset']) && $_GET['reset']==='done'): ?>
  <p style="color:green;font-weight:600">✅ Logs cleared successfully.</p>
<?php endif; ?>

<p class="muted">
  CSV size: <?=number_format($csv_size)?> bytes ·
  NDJSON size: <?=number_format($ndj_size)?> bytes
</p>

<h2>CSV View (tabular)</h2>
<?php if (empty($csv_rows)): ?>
    <div class="empty-state">
        <p>No log entries yet.</p>
    </div>
<?php else: ?>
    <table>
      <tr>
        <th>Time (HKT)</th>
        <th>Kind</th>
        <th>Anon&nbsp;ID</th>
        <th>Thread</th>
        <th>Lang</th>
        <th>Text</th>
      </tr>
      <?php foreach ($csv_rows as $r): ?>
        <tr class="<?=h($r['kind'] ?? '')?>">
          <td><?=h($r['ts_hkt'] ?? '')?></td>
          <td><?=h($r['kind'] ?? '')?></td>
          <td><?=h($r['anon_id'] ?? '')?></td>
          <td><?=h($r['thread'] ?? '')?></td>
          <td><?=h($r['lang'] ?? '')?></td>
          <td><?=nl2br(h($r['text'] ?? ''))?></td>
        </tr>
      <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>NDJSON Raw (debug)</h2>
<?php if (empty($ndj_rows)): ?>
    <div class="empty-state">
        <p>No log entries yet.</p>
    </div>
<?php else: ?>
    <pre><code><?php
    foreach ($ndj_rows as $row) {
      echo h(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\n\n";
    }
    ?></code></pre>
<?php endif; ?>

</body>
</html>
