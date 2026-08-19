<?php
// public_html/admin/index.php
declare(strict_types=1);
require __DIR__ . '/_auth.php';
lsb_require_admin();

$root = dirname(__DIR__);              // /public_html

// Resolve upload directory (override in config.php with define('UPLOAD_DIR', '/abs/path'))
$uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : realpath($root . '/uploads');
if ($uploadDir === false) {
  $try = $root . '/uploads';
  if (!is_dir($try)) { @mkdir($try, 0775, true); }
  $uploadDir = realpath($try);
}
$uploadOk = ($uploadDir !== false && is_dir($uploadDir) && is_readable($uploadDir));

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Collect files from uploads directory
$files = [];
if ($uploadOk) {
  foreach (['*.pptx','*.ppt','*.pdf','*.txt'] as $pat) {
    foreach (glob($uploadDir . DIRECTORY_SEPARATOR . $pat) as $p) {
      $files[] = [
        'path' => $p,
        'name' => basename($p),
        'size' => @filesize($p) ?: 0,
        'mtime'=> @filemtime($p) ?: 0,
        'source' => 'uploads'
      ];
    }
  }
}

// Collect files from extra directory
$extraDir = defined('EXTRA_DIR') ? EXTRA_DIR : realpath($root . '/extra');
$extraOk = ($extraDir !== false && is_dir($extraDir) && is_readable($extraDir));
if ($extraOk) {
  foreach (['*.txt'] as $pat) {
    foreach (glob($extraDir . DIRECTORY_SEPARATOR . $pat) as $p) {
      $files[] = [
        'path' => $p,
        'name' => basename($p),
        'size' => @filesize($p) ?: 0,
        'mtime'=> @filemtime($p) ?: 0,
        'source' => 'extra'
      ];
    }
  }
}

// Sort all files by modification time (newest first)
usort($files, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Admin – LegalStudyBot</title>
<meta http-equiv="Cache-Control" content="no-store" />
<style>
  :root{
    --bg:#f7f7f8; --card:#fff; --border:#e5e7eb; --text:#111827; --muted:#6b7280;
    --btn:#111827; --btnText:#fff; --accent:#2563eb; --danger:#b91c1c;
  }
  html,body{height:100%}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,"Noto Sans",sans-serif}
  .wrap{max-width:1100px;margin:0 auto;padding:20px}
  header{display:flex;align-items:center;justify-content:space-between;margin:10px 0 16px}
  h1{margin:0;font-size:1.6rem}
  .muted{color:var(--muted)}
  .row{display:flex;gap:16px;align-items:stretch;flex-wrap:wrap}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
  .card .inner{padding:16px}
  .col{flex:1 1 520px}
  label{display:block;margin:.35rem 0 .25rem;font-weight:600}
  input[type=file]{display:block;width:100%;padding:.6rem;border:1px solid var(--border);border-radius:10px;background:#fff}
  .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem .95rem;border-radius:10px;border:1px solid var(--border);background:#fff;cursor:pointer;font-weight:600}
  .btn.primary{background:var(--btn);color:var(--btnText);border-color:var(--btn)}
  .btn.accent{background:var(--accent);color:#fff;border-color:var(--accent)}
  .btn.danger{background:#fff;color:var(--danger);border-color:var(--danger)}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px 8px;border-bottom:1px solid var(--border);text-align:left;font-size:.95rem}
  th{font-weight:700}
  .right{text-align:right}
  .tools{display:flex;gap:.5rem;align-items:center}
  .status{margin-left:.6rem;color:var(--muted);font-size:.95rem}
  .topbar{display:flex;gap:.6rem;align-items:center}
  .pill{padding:.4rem .7rem;border:1px solid var(--border);border-radius:999px;background:#fff}
  .small{font-size:.9rem}
  .fine{font-variant-numeric:tabular-nums}
  .footer{margin:20px 0;color:var(--muted);font-size:.92rem}
  .warn{color:#92400e}
  .success{color:var(--green);background:#f0fdf4;border:1px solid #86efac;padding:10px;border-radius:8px;margin:10px 0}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <div>
      <h1>LegalStudyBot – Admin</h1>
      <div class="muted small">Uploads: <?php echo $uploadOk ? h((string)$uploadDir) : '<span class="warn">unavailable</span>'; ?></div>
      <div class="muted small">Extra: <?php echo $extraOk ? h((string)$extraDir) : '<span class="warn">unavailable</span>'; ?></div>
    </div>
    <div class="topbar">
      <a class="pill" href="../index.html" target="_blank" rel="noopener">Open student page</a>
      <a class="pill" href="../dashboard.php">Dashboard</a>
      <a class="pill" href="logs.php">Logs</a>
      <a class="pill" href="logout.php">Log out</a>
    </div>
  </header>

  <?php
    $flashMsg = $_SESSION['flash'] ?? '';
    $justUploaded = $flashMsg !== '' && stripos($flashMsg, 'Uploaded') !== false;
    if ($flashMsg !== ''):
      unset($_SESSION['flash']);
  ?>
    <div class="success">
      <?php echo h($flashMsg); ?>
      <?php if ($justUploaded): ?>
        <span id="autoReindexNote" style="margin-left:.5rem;color:var(--muted)">Auto-indexing…</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="row">
    <!-- Upload -->
    <div class="col card">
      <div class="inner">
        <h2 style="margin-top:0">Upload content</h2>
        <p class="muted">Supported: .pptx, .ppt, .pdf, .txt. You can select multiple files.</p>

        <?php if (!$uploadOk): ?>
          <p class="warn">Upload directory is not available. Define <code>UPLOAD_DIR</code> in <code>config.php</code> or create <code><?php echo h($root . '/uploads'); ?></code> with write permission.</p>
        <?php endif; ?>

        <form id="uploadForm" action="upload.php" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?php echo h(lsb_csrf_token()); ?>"/>
          <label for="files">Choose files</label>
          <input id="files" type="file" name="pptx[]" accept=".pptx,.ppt,.pdf,.txt" multiple <?php echo $uploadOk ? '' : 'disabled'; ?>/>
          <div style="margin-top:.8rem;display:flex;gap:.6rem;align-items:center">
            <button id="uploadBtn" type="submit" class="btn primary" <?php echo $uploadOk ? '' : 'disabled'; ?>>Upload</button>
            <span class="muted small">TXT files work best for content extracted from PDFs.</span>
          </div>
        </form>
      </div>
    </div>

    <!-- Re-index -->
    <div class="col card">
      <div class="inner">
        <h2 style="margin-top:0">Search index</h2>
        <p class="muted">Rebuild the course search index so new/updated files are included in retrieval.</p>
        <div class="tools">
          <button id="reindexBtn" class="btn accent">Re-index now</button>
          <button id="forceReindexBtn" class="btn" title="Reprocess all files from scratch, even if unchanged">Force full re-index</button>
          <span id="reindexStatus" class="status">Idle</span>
        </div>
        <div style="margin-top:.7rem">
          <button id="warmBtn" class="btn">Warm data_index</button>
          <span id="warmStatus" class="status"></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Existing files -->
  <div class="card" style="margin-top:16px">
    <div class="inner">
      <h2 style="margin-top:0">Existing files</h2>
      <?php if (!$uploadOk && !$extraOk): ?>
        <p class="warn">No listing – directories unavailable.</p>
      <?php elseif (empty($files)): ?>
        <p class="muted">No files found.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>File</th>
              <th>Source</th>
              <th class="right">Size</th>
              <th>Modified</th>
              <th class="right">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($files as $f): ?>
            <tr>
              <td>
                <?php 
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $type_badge = '';
                if ($ext === 'txt') $type_badge = ' <span style="color:#059669;font-size:0.85em">[TXT]</span>';
                elseif ($ext === 'pdf') $type_badge = ' <span style="color:#dc2626;font-size:0.85em">[PDF]</span>';
                elseif ($ext === 'pptx' || $ext === 'ppt') $type_badge = ' <span style="color:#2563eb;font-size:0.85em">[PPT]</span>';
                echo h($f['name']) . $type_badge; 
                ?>
              </td>
              <td>
                <?php 
                $source = $f['source'] ?? 'uploads';
                $source_badge = $source === 'extra' 
                  ? '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-size:0.85em">extra</span>'
                  : '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:4px;font-size:0.85em">uploads</span>';
                echo $source_badge;
                ?>
              </td>
              <td class="right fine">
                <?php
                  $kb = $f['size']/1024; $mb=$kb/1024;
                  echo $mb>=1 ? number_format($mb,2).' MB' : number_format($kb,0).' KB';
                ?>
              </td>
              <td class="fine"><?php echo date('Y-m-d H:i', $f['mtime']); ?></td>
              <td class="right">
                <form action="delete.php" method="post" data-confirm="Delete this file?">
                  <input type="hidden" name="csrf_token" value="<?php echo h(lsb_csrf_token()); ?>"/>
                  <input type="hidden" name="file" value="<?php echo h($f['name']); ?>"/>
                  <input type="hidden" name="source" value="<?php echo h($f['source'] ?? 'uploads'); ?>"/>
                  <button type="submit" class="btn danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="footer">
    <span class="muted">Tip:</span> Re-indexing runs automatically after upload. Use <strong>Force full re-index</strong> to reprocess all files from scratch.
  </div>

</div>

<div id="adminConfig" data-csrf="<?php echo h(lsb_csrf_token()); ?>" data-auto-reindex="<?php echo $justUploaded ? '1' : '0'; ?>" hidden></div>
<script src="admin.js" defer></script>
</body>
</html>