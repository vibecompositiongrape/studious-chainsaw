<?php
declare(strict_types=1);
require __DIR__ . '/_auth.php';
lsb_require_admin();

$root = dirname(__DIR__);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php');
  exit;
}
lsb_verify_csrf();

$file = basename($_POST['file'] ?? '');  // basename() prevents directory traversal
$source = $_POST['source'] ?? 'uploads';

if ($file === '') {
  $_SESSION['flash'] = 'No file specified.';
  header('Location: index.php');
  exit;
}

// Determine target directory based on source
if ($source === 'extra') {
  $targetDir = defined('EXTRA_DIR') ? EXTRA_DIR : $root . '/extra';
} else {
  $targetDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : $root . '/uploads';
}

$path = $targetDir . DIRECTORY_SEPARATOR . $file;

// Security check: ensure the resolved path is within the expected directory
$realPath = realpath($path);
$realDir = realpath($targetDir);

if ($realPath === false || $realDir === false || strpos($realPath, $realDir) !== 0) {
  $_SESSION['flash'] = 'Invalid file path.';
  header('Location: index.php');
  exit;
}

if (!is_file($realPath)) {
  $_SESSION['flash'] = 'File not found: ' . htmlspecialchars($file);
  header('Location: index.php');
  exit;
}

if (@unlink($realPath)) {
  $_SESSION['flash'] = 'Deleted: ' . htmlspecialchars($file) . ' (from ' . $source . ')';
} else {
  $_SESSION['flash'] = 'Failed to delete: ' . htmlspecialchars($file);
}

header('Location: index.php');
exit;