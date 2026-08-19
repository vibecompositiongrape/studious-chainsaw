<?php
// dashboard.php — usage analytics for LegalStudyBot (no external libs).
// Reads logs/usage.csv written by log_client.php:
//   ts, ts_iso, ts_hkt, kind, anon_id, thread, lang, text
declare(strict_types=1);

require __DIR__ . '/admin/_auth.php';
if (!lsb_is_admin()) {
  header('Location: admin/login.php?next=index.php');
  exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------- Load CSV ----------
$csv = defined('USAGE_CSV') ? USAGE_CSV : '';
$rows = [];
if ($csv && is_file($csv) && ($fh = fopen($csv, 'r')) !== false) {
    $header = fgetcsv($fh, 0, ',', '"', '\\');
    if ($header) {
        $headerCount = count($header);
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (count($r) !== $headerCount) continue;
            $assoc = array_combine($header, $r);
            if (is_array($assoc)) $rows[] = $assoc;
        }
    }
    fclose($fh);
}

// ---------- Aggregate ----------
$totalQ = 0; $totalA = 0;
$users = [];
$byDate = [];       // YYYY-MM-DD => question count
$langCounts = [];
$sumQChars = 0; $sumAChars = 0;
$hkt = new DateTimeZone('Asia/Hong_Kong');

foreach ($rows as $r) {
    $kind = $r['kind'] ?? '';
    $ts = (int)($r['ts'] ?? 0);
    if ($ts <= 0) continue;
    $day = (new DateTimeImmutable('@' . $ts))->setTimezone($hkt)->format('Y-m-d');
    $len = mb_strlen((string)($r['text'] ?? ''), 'UTF-8');

    if (($r['anon_id'] ?? '') !== '') $users[$r['anon_id']] = true;

    if ($kind === 'q') {
        $totalQ++;
        $sumQChars += $len;
        $byDate[$day] = ($byDate[$day] ?? 0) + 1;
        $lang = ($r['lang'] ?? '') === '' ? 'en' : $r['lang'];
        $langCounts[$lang] = ($langCounts[$lang] ?? 0) + 1;
    } elseif ($kind === 'a') {
        $totalA++;
        $sumAChars += $len;
    }
}
ksort($byDate);
arsort($langCounts);
$maxDay = $byDate ? max($byDate) : 0;
$last14 = array_slice($byDate, -14, null, true);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Usage Dashboard — LegalStudyBot</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:24px;color:#111;background:#f7f7f8}
  .wrap{max-width:900px;margin:0 auto}
  h1{margin:0 0 4px}
  .muted{color:#6b7280}
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:20px 0}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px}
  .card .num{font-size:1.7rem;font-weight:700;font-variant-numeric:tabular-nums}
  .card .lbl{font-size:.85rem;color:#6b7280}
  .panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:0 0 16px}
  .panel h2{margin:0 0 12px;font-size:1.05rem}
  .chart{display:flex;align-items:flex-end;gap:6px;height:150px;padding-top:8px}
  .chart .col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px;min-width:0}
  .chart .bar{width:100%;max-width:48px;background:#2563eb;border-radius:4px 4px 0 0;min-height:2px}
  .chart .lab{font-size:.68rem;color:#6b7280;white-space:nowrap}
  .chart .val{font-size:.72rem;color:#374151;font-variant-numeric:tabular-nums}
  table{width:100%;border-collapse:collapse}
  th,td{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:.92rem}
  .bar-nav{margin:10px 0 0}
  .bar-nav a{color:#2563eb;text-decoration:none;margin-right:14px}
  .empty{padding:32px;text-align:center;color:#6b7280}
</style>
</head>
<body>
<div class="wrap">
  <h1>Usage Dashboard</h1>
  <div class="muted">LegalStudyBot — question/answer activity (times in HKT)</div>
  <p class="bar-nav">
    <a href="admin/index.php">← Admin</a>
    <a href="admin/logs.php">Raw logs</a>
    <a href="index.html">Student page</a>
  </p>

  <?php if ($totalQ === 0 && $totalA === 0): ?>
    <div class="panel"><div class="empty">No data yet. Once students start using the chatbot, activity will appear here.</div></div>
  <?php else: ?>

  <div class="cards">
    <div class="card"><div class="num"><?=number_format($totalQ)?></div><div class="lbl">Questions asked</div></div>
    <div class="card"><div class="num"><?=number_format($totalA)?></div><div class="lbl">Answers logged</div></div>
    <div class="card"><div class="num"><?=number_format(count($users))?></div><div class="lbl">Unique students</div></div>
    <div class="card"><div class="num"><?=$totalQ ? number_format($sumQChars / $totalQ, 0) : 0?></div><div class="lbl">Avg question length (chars)</div></div>
    <div class="card"><div class="num"><?=$totalA ? number_format($sumAChars / $totalA, 0) : 0?></div><div class="lbl">Avg answer length (chars)</div></div>
  </div>

  <div class="panel">
    <h2>Questions per day (last 14 active days)</h2>
    <?php if ($last14): ?>
      <div class="chart">
        <?php foreach ($last14 as $day => $count): ?>
          <div class="col">
            <span class="val"><?=$count?></span>
            <div class="bar" style="height:<?=$maxDay ? max(2, (int)round($count / $maxDay * 110)) : 2?>px"></div>
            <span class="lab"><?=h(substr($day, 5))?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty">No dated activity yet.</div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Language split (questions)</h2>
    <table>
      <tr><th>Language</th><th>Questions</th><th>Share</th></tr>
      <?php foreach ($langCounts as $lang => $count): ?>
        <tr>
          <td><?=h((string)$lang)?></td>
          <td><?=number_format($count)?></td>
          <td><?=$totalQ ? number_format($count / $totalQ * 100, 1) : 0?>%</td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php endif; ?>
</div>
</body>
</html>
