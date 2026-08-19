<?php
// config.example.php — copy to config.php on the server and fill in real values.
// config.php is NOT tracked in Git and is preserved across deploys.
declare(strict_types=1);

// ---- Admin ----
define('ADMIN_PASSWORD', 'change-me');            // /admin login password

// ---- Content directories (absolute paths) ----
define('UPLOAD_DIR', __DIR__ . '/uploads');       // raw uploaded decks (.pptx/.pdf/.txt)
define('EXTRA_DIR',  __DIR__ . '/extra');         // extra .txt materials (cases, statutes…)
define('DATA_DIR',   __DIR__ . '/data');          // parsed per-deck JSON output

// ---- Logging ----
define('LOG_DIR',      __DIR__ . '/logs');
define('USAGE_CSV',    LOG_DIR . '/usage.csv');
define('USAGE_NDJSON', LOG_DIR . '/usage.ndjson');

// ---- SMTP (email transcript feature, via PHPMailer) ----
define('SMTP_HOST',   'smtp.example.com');
define('SMTP_PORT',   465);
define('SMTP_SECURE', 'ssl');                     // 'ssl' (465) or 'tls' (587)
define('SMTP_USER',   'bot@example.com');
define('SMTP_PASS',   'change-me');
define('SMTP_FROM',   'bot@example.com');
