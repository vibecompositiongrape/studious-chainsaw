<?php
// admin/login.php
declare(strict_types=1);
require __DIR__ . '/_auth.php';

// Only allow redirecting to a plain PHP filename inside /admin/.
function safe_next(string $next): string {
  $next = trim($next);
  return preg_match('/^[a-z0-9_]+\.php$/i', $next) ? $next : 'index.php';
}

$next = safe_next((string)($_GET['next'] ?? 'index.php'));
$err  = '';

// Simple per-session lockout against password guessing.
$MAX_ATTEMPTS = 8;
$LOCKOUT_SECONDS = 900;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pw   = $_POST['password'] ?? '';
  $next = safe_next((string)($_POST['next'] ?? 'index.php'));

  $attempts   = (int)($_SESSION['login_attempts'] ?? 0);
  $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);

  if ($lockedUntil > time()) {
    $err = 'Too many failed attempts. Try again in ' . (int)ceil(($lockedUntil - time()) / 60) . ' minute(s).';
  } elseif (hash_equals(ADMIN_PASSWORD, $pw)) {
    session_regenerate_id(true);
    unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
    // Set BOTH keys so old/new pages work
    $_SESSION['admin']    = true;
    $_SESSION['admin_ok'] = true;
    header('Location: ' . $next);
    exit;
  } else {
    $attempts++;
    $_SESSION['login_attempts'] = $attempts;
    if ($attempts >= $MAX_ATTEMPTS) {
      $_SESSION['login_locked_until'] = time() + $LOCKOUT_SECONDS;
      $_SESSION['login_attempts'] = 0;
    }
    sleep(1); // slow down brute force
    $err = 'Incorrect password.';
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Login – LegalStudyBot</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;margin:40px;background:#f7f7f8}
  .card{max-width:420px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:22px}
  h1{margin:0 0 10px}
  .muted{color:#6b7280;margin:0 0 18px}
  label{display:block;margin:.5rem 0 .3rem;font-weight:600}
  input[type=password]{width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}
  button{margin-top:12px;padding:.6rem 1rem;border-radius:8px;border:1px solid #111;background:#111;color:#fff;cursor:pointer;font-weight:600}
  .err{color:#b91c1c;margin:10px 0}
</style>
</head>
<body>
  <div class="card">
    <h1>Admin Login</h1>
    <p class="muted">Enter your administrator password.</p>
    <?php if ($err): ?><div class="err"><?=htmlspecialchars($err)?></div><?php endif; ?>
    <form method="post" action="login.php">
      <input type="hidden" name="next" value="<?=htmlspecialchars($next)?>">
      <label for="pw">Password</label>
      <input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>
      <button type="submit">Sign in</button>
    </form>
  </div>
</body>
</html>
