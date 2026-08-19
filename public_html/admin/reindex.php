<?php
declare(strict_types=1);
// admin/reindex.php – build JSON indices in /data from decks in /uploads
// Features: incremental mode, size-sorted processing, resource management

// ─── Try to raise resource limits for shared hosting ───
@ini_set('memory_limit', '256M');
@set_time_limit(300);

require __DIR__ . '/_auth.php';
header('Content-Type: application/json; charset=UTF-8');
lsb_require_admin(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'POST required']);
  exit;
}
lsb_verify_csrf();

$root = dirname(__DIR__);             // /public_html
require_once $root . '/lib/tokenizer.php';

// ─── Paths & mode ───
$uploads = UPLOAD_DIR;
$dataDir = DATA_DIR;
if (!is_dir($uploads)) { @mkdir($uploads, 0775, true); }
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

// force=1 reprocesses all files regardless of timestamps
$force = !empty($_GET['force']) || !empty($_POST['force']);

$report = [
  'ok' => true,
  'processed' => [],
  'skipped' => [],
  'errors' => [],
  'unchanged' => [],
  'cleaned' => [],
];

// ─── Helpers ───

function slugify(string $s): string {
  $s = preg_replace('~[^\pL\d]+~u', '-', $s);
  $s = trim($s, '-');
  $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
  $s = strtolower($s);
  $s = preg_replace('~[^-\w]+~', '', $s);
  return $s ?: 'deck';
}

/**
 * Ensure string is valid UTF-8 (prevents json_encode failures that cause 0-byte files).
 */
function ensure_utf8(string $s): string {
  if (!mb_check_encoding($s, 'UTF-8')) {
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
  }
  $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? $s;
  return $s;
}

function xml_text_from_pptx(string $xml): string {
  // Extract text from <a:t> nodes; tolerate line breaks/runs
  $xml = preg_replace('/<a:br\s*\/>/i', "\n", $xml);
  preg_match_all('/<a:t[^>]*>(.*?)<\/a:t>/is', $xml, $m);
  $parts = array_map(function($t){
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim($t);
  }, $m[1] ?? []);
  $txt = trim(implode(' ', array_filter($parts, fn($x)=>$x!=='') ));
  $txt = preg_replace('/\s{2,}/u', ' ', $txt);
  return $txt;
}

function parse_pptx(string $file): array {
  $slides = [];
  $noteTexts = [];
  $zip = new ZipArchive();
  if ($zip->open($file) !== true) {
    throw new RuntimeException("Zip open failed");
  }

  // Single pass through zip entries (instead of two) to reduce I/O
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (preg_match('#^ppt/slides/slide(\d+)\.xml$#i', $name, $m)) {
      $n = (int)$m[1];
      $xml = $zip->getFromIndex($i);
      $slides[$n] = ['n'=>$n, 'text'=>xml_text_from_pptx($xml ?: ''), 'notes'=>''];
      unset($xml);
    } elseif (preg_match('#^ppt/notesSlides/notesSlide(\d+)\.xml$#i', $name, $m)) {
      $n = (int)$m[1];
      $xml = $zip->getFromIndex($i);
      $noteTexts[$n] = xml_text_from_pptx($xml ?: '');
      unset($xml);
    }
  }

  $zip->close();

  // Merge notes into slides
  foreach ($noteTexts as $n => $notes) {
    if (isset($slides[$n])) {
      $slides[$n]['notes'] = $notes;
    }
  }
  unset($noteTexts);

  ksort($slides, SORT_NUMERIC);
  return array_values(array_filter($slides, fn($s) => $s['text'] !== '' || $s['notes'] !== ''));
}

// Parse TXT files with robust chunking
function parse_txt(string $txtFile): array {
  $content = @file_get_contents($txtFile);
  if (!$content) return [];

  // Normalize all line endings to LF
  $content = preg_replace('~\R~u', "\n", $content);
  $content = trim($content);

  if ($content === '') return [];

  $maxLen = 1000;
  $slides = [];

  // Try splitting on double newlines first
  $parts = preg_split('/\n{2,}/', $content);

  // If we only got one huge block, try splitting on single newlines
  if (count($parts) === 1 && mb_strlen($parts[0], 'UTF-8') > $maxLen * 2) {
      $parts = preg_split('/\n/', $content);
  }

  // If still one huge block (no newlines at all), split on sentences
  if (count($parts) === 1 && mb_strlen($parts[0], 'UTF-8') > $maxLen * 2) {
      $parts = preg_split('/(?<=[.!?])\s+/', $content);
  }

  $n = 0;
  $buf = '';

  foreach ($parts as $part) {
    $part = trim($part);
    if ($part === '') continue;

    // If this single part exceeds maxLen, split it further
    if (mb_strlen($part, 'UTF-8') > $maxLen) {
      // Flush buffer first
      if ($buf !== '') {
        $slides[] = ['n' => ++$n, 'text' => $buf, 'notes' => ''];
        $buf = '';
      }

      // Split oversized part by sentences
      $sentences = preg_split('/(?<=[.!?])\s+/', $part) ?: [$part];
      $subBuf = '';

      foreach ($sentences as $sent) {
        $sent = trim($sent);
        if ($sent === '') continue;

        if ($subBuf === '') {
          $subBuf = $sent;
        } elseif (mb_strlen($subBuf . ' ' . $sent, 'UTF-8') <= $maxLen) {
          $subBuf .= ' ' . $sent;
        } else {
          $slides[] = ['n' => ++$n, 'text' => $subBuf, 'notes' => ''];
          $subBuf = $sent;
        }
      }

      if ($subBuf !== '') {
        // If still too long, hard-split as last resort
        while (mb_strlen($subBuf, 'UTF-8') > $maxLen) {
          $slides[] = ['n' => ++$n, 'text' => mb_substr($subBuf, 0, $maxLen, 'UTF-8'), 'notes' => ''];
          $subBuf = trim(mb_substr($subBuf, $maxLen, null, 'UTF-8'));
        }
        if ($subBuf !== '') {
          $slides[] = ['n' => ++$n, 'text' => $subBuf, 'notes' => ''];
        }
      }
      continue;
    }

    // Normal accumulation
    if ($buf === '') {
      $buf = $part;
    } elseif (mb_strlen($buf . "\n\n" . $part, 'UTF-8') <= $maxLen) {
      $buf .= "\n\n" . $part;
    } else {
      $slides[] = ['n' => ++$n, 'text' => $buf, 'notes' => ''];
      $buf = $part;
    }
  }

  if ($buf !== '') {
    $slides[] = ['n' => ++$n, 'text' => $buf, 'notes' => ''];
  }

  return $slides;
}


// Simple fallback PDF parser using PHP (no external tools required)
function parse_pdf_simple(string $pdfFile): array {
  $content = @file_get_contents($pdfFile);
  if (!$content) return [];

  $slides = [];
  $text_chunks = [];

  // Try to extract text from PDF content streams
  if (preg_match_all('/stream(.*?)endstream/s', $content, $streams)) {
    foreach ($streams[1] as $stream) {
      $stream = @gzuncompress($stream) ?: $stream;

      if (preg_match_all('/BT(.*?)ET/s', $stream, $texts)) {
        foreach ($texts[1] as $text) {
          if (preg_match_all('/\((.*?)\)\s*Tj/s', $text, $matches)) {
            foreach ($matches[1] as $match) {
              $decoded = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $match);
              $decoded = trim($decoded);
              if ($decoded !== '') {
                $text_chunks[] = $decoded;
              }
            }
          }
        }
      }
    }
  }

  // Free the raw PDF content
  unset($content, $streams);

  if (empty($text_chunks)) {
    // Reload for fallback regex (avoids holding both in memory)
    $content = @file_get_contents($pdfFile);
    if ($content) {
      preg_match_all('/\(((?:[^\(\)\\\\]|\\\\.|\\\\[0-7]{1,3})+)\)/', $content, $matches);
      foreach ($matches[1] as $match) {
        $decoded = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $match);
        $decoded = trim($decoded);
        if (strlen($decoded) > 3 && preg_match('/[a-zA-Z]/', $decoded)) {
          $text_chunks[] = $decoded;
        }
      }
      unset($content, $matches);
    }
  }

  if (!empty($text_chunks)) {
    $all_text = implode(' ', $text_chunks);
    unset($text_chunks);
    $all_text = preg_replace('/\s+/', ' ', $all_text);
    // Sanitize to valid UTF-8 before chunking
    $all_text = ensure_utf8($all_text);

    $chunks = str_split($all_text, 500);
    $n = 0;
    foreach ($chunks as $chunk) {
      $chunk = trim($chunk);
      if ($chunk !== '') {
        $slides[] = ['n' => ++$n, 'text' => $chunk, 'notes' => ''];
      }
    }
  }

  return $slides;
}

function have_pdftotext(): bool {
  $which = trim((string)@shell_exec('which pdftotext 2>/dev/null'));
  return $which !== '';
}

function parse_pdf_with_pdftotext(string $pdfFile): array {
  $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
  @unlink($tmp);
  $txtFile = $tmp . '.txt';
  $cmd = 'pdftotext -enc UTF-8 -layout ' . escapeshellarg($pdfFile) . ' ' . escapeshellarg($txtFile) . ' 2>/dev/null';
  @shell_exec($cmd);
  $text = is_file($txtFile) ? @file_get_contents($txtFile) : '';
  @unlink($txtFile);

  if ($text === '' || $text === false) return [];

  $pages = preg_split('/\f/', $text);
  if (!$pages || count($pages) < 2) {
    $pages = preg_split('/\n\s*\n/', $text);
  }

  $slides = [];
  $n = 0;
  foreach ($pages as $p) {
    $p = trim(preg_replace('/[ \t]+/',' ', $p));
    if ($p === '') continue;
    $slides[] = ['n'=> ++$n, 'text'=>$p, 'notes'=>''];
  }
  return $slides;
}

/**
 * Classify extra file name like "case-sham-tsz-kit.txt" into type and label
 */
function classify_extra_name(string $filename): array {
    $base = pathinfo($filename, PATHINFO_BASENAME);
    $noExt = preg_replace('/\.[^.]+$/', '', $base);
    $parts = explode('-', $noExt, 2);

    if (count($parts) === 2) {
        [$prefix, $rest] = $parts;
    } else {
        $prefix = $parts[0];
        $rest = $parts[0];
    }

    $prefix = strtolower($prefix);
    $type = match ($prefix) {
        'case'    => 'case',
        'exam'    => 'exam',
        'statute' => 'statute',
        'notes'   => 'notes',
        default   => 'extra',
    };

    $label = ucwords(str_replace(['-', '_'], ' ', $rest));
    return [$type, $label];
}

/**
 * Safely encode and write a JSON document. Returns true on success.
 * Cleans up 0-byte files on failure.
 */
function write_json(string $outPath, array $doc, array &$report, string $label): bool {
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $report['errors'][] = "$label: JSON encoding failed – " . json_last_error_msg();
        if (is_file($outPath)) @unlink($outPath);
        return false;
    }

    $ok = @file_put_contents($outPath, $json);
    if ($ok === false || $ok === 0) {
        $report['errors'][] = "$label: file write failed";
        if (is_file($outPath) && filesize($outPath) === 0) @unlink($outPath);
        return false;
    }

    return true;
}

function rebuild_bm25_index(string $dataDir, string $indexPath, array &$report): void {
  $k1 = 1.5;
  $b = 0.75;
  $docs = [];
  $post = [];
  $totalLen = 0;
  $N = 0;

  foreach (glob($dataDir . '/*.json') ?: [] as $f) {
    $j = json_decode((string)@file_get_contents($f), true);
    if (!$j || empty($j['slides']) || !is_array($j['slides'])) continue;

    $title = $j['title'] ?? ($j['slug'] ?? basename($f, '.json'));
    $slug = $j['slug'] ?? basename($f, '.json');

    foreach ($j['slides'] as $s) {
      if (empty($s['text']) && empty($s['notes'])) continue;
      $n = $s['n'] ?? ($N + 1);
      $id = $slug . '#' . $n;
      $text = "[{$title} — Slide {$n}]\n" . ($s['text'] ?? '');
      if (!empty($s['notes'])) $text .= "\n" . $s['notes'];

      $plain = lsb_norm($text);
      if ($plain === '') continue;

      $N++;
      $len = mb_strlen($plain, 'UTF-8');
      $docs[$id] = ['text' => $text, 'len' => $len];
      $totalLen += $len;

      // Same tokenizer as data_index.php (lib/tokenizer.php) — CJK bigrams
      // make Chinese queries actually match.
      $terms = lsb_tokenize($plain);
      $tf = [];
      foreach ($terms as $t) {
        if ($t === '') continue;
        $tf[$t] = ($tf[$t] ?? 0) + 1;
      }
      foreach ($tf as $t => $c) {
        $post[$t][$id] = $c;
      }
    }
  }

  $out = [
    'meta' => [
      'N' => $N,
      'avgdl' => $N ? $totalLen / $N : 0,
      'k1' => $k1,
      'b' => $b,
      'built_at' => date('c'),
    ],
    'docs' => $docs,
    'post' => $post,
  ];

  $json = json_encode($out, JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    $report['errors'][] = 'BM25 index JSON encoding failed - ' . json_last_error_msg();
    return;
  }

  $tmp = $indexPath . '.tmp';
  if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $indexPath)) {
    @unlink($tmp);
    $report['errors'][] = 'BM25 index write failed';
    return;
  }

  $report['bm25'] = [
    'ok' => true,
    'docs' => $N,
    'terms' => count($post),
    'index' => basename($indexPath),
  ];
}


// ═══════════════════════════════════════════════════════════════
// Step 1: Clean up 0-byte JSONs left by previous failed runs
// ═══════════════════════════════════════════════════════════════
$existingJsons = glob($dataDir . '/*.json') ?: [];
foreach ($existingJsons as $jsonPath) {
    if (filesize($jsonPath) === 0) {
        @unlink($jsonPath);
        $report['cleaned'][] = basename($jsonPath) . ' (0-byte removed)';
    }
}


// ═══════════════════════════════════════════════════════════════
// Step 2: Process uploads – sorted by size (smallest first)
// ═══════════════════════════════════════════════════════════════
$files = glob($uploads . '/*.{pptx,ppt,pdf,PDF,txt,TXT}', GLOB_BRACE) ?: [];
usort($files, fn($a, $b) => filesize($a) <=> filesize($b));

$hasPdfTool = have_pdftotext();
$validSlugs = [];  // track all slugs that should exist in /data

foreach ($files as $path) {
  $base = basename($path);
  $name = pathinfo($base, PATHINFO_FILENAME);
  $slug = slugify($name);
  $out  = $dataDir . '/' . $slug . '.json';
  $validSlugs[$slug] = true;

  // Incremental: skip if JSON exists, is non-empty, and newer than source
  if (!$force && is_file($out) && filesize($out) > 0 && filemtime($out) >= filemtime($path)) {
    $report['unchanged'][] = $base;
    continue;
  }

  try {
    $slides = [];
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));

    if ($ext === 'pptx' || $ext === 'ppt') {
      $slides = parse_pptx($path);
      if (empty($slides)) {
        $report['skipped'][] = "$base (no extractable text)";
        continue;
      }
    } elseif ($ext === 'txt') {
      $slides = parse_txt($path);
      if (empty($slides)) {
        $report['skipped'][] = "$base (empty text file)";
        continue;
      }
    } elseif ($ext === 'pdf') {
      if ($hasPdfTool) {
        $slides = parse_pdf_with_pdftotext($path);
      }
      if (empty($slides)) {
        $slides = parse_pdf_simple($path);
        if (empty($slides)) {
          $report['skipped'][] = "$base (no text extracted from PDF – try uploading as .txt instead)";
          if (is_file($out)) @unlink($out);
          continue;
        }
      }
    } else {
      $report['skipped'][] = "$base (unsupported extension)";
      continue;
    }

    // Ensure all text is valid UTF-8
    foreach ($slides as &$s) {
      $s['text']  = ensure_utf8($s['text']);
      $s['notes'] = ensure_utf8($s['notes']);
    }
    unset($s);

    $doc = [
      'title'  => $name,
      'slug'   => $slug,
      'source' => 'uploads',
      'type'   => 'slide',
      'slides' => $slides
    ];

    if (write_json($out, $doc, $report, $base)) {
      $report['processed'][] = [
        'file'   => $base,
        'source' => 'uploads',
        'output' => basename($out),
        'chunks' => count($slides),
        'method' => $ext === 'pdf' ? ($hasPdfTool ? 'pdftotext' : 'PHP parser') : 'native'
      ];
    }

    // Free memory before next file
    unset($slides, $doc);
  } catch (Throwable $e) {
    $report['errors'][] = $base . ': ' . $e->getMessage();
    if (is_file($out) && filesize($out) === 0) @unlink($out);
  }

  gc_collect_cycles();
}


// ═══════════════════════════════════════════════════════════════
// Step 3: Process extra dir – same approach
// ═══════════════════════════════════════════════════════════════
$extraDir = EXTRA_DIR;
$extraFiles = [];
if (is_dir($extraDir)) {
  $extraFiles = glob($extraDir . '/*.{txt,TXT}', GLOB_BRACE) ?: [];
  usort($extraFiles, fn($a, $b) => filesize($a) <=> filesize($b));

  foreach ($extraFiles as $path) {
    $base = basename($path);
    $name = pathinfo($base, PATHINFO_FILENAME);
    $slug = 'extra-' . slugify($name);
    $out  = $dataDir . '/' . $slug . '.json';
    $validSlugs[$slug] = true;

    if (!$force && is_file($out) && filesize($out) > 0 && filemtime($out) >= filemtime($path)) {
      $report['unchanged'][] = "$base [extra]";
      continue;
    }

    try {
      $slides = parse_txt($path);
      if (empty($slides)) {
        $report['skipped'][] = "$base [extra] (empty text file)";
        continue;
      }

      [$type, $label] = classify_extra_name($base);

      foreach ($slides as &$s) {
        $s['text']  = ensure_utf8($s['text']);
        $s['notes'] = ensure_utf8($s['notes']);
      }
      unset($s);

      $doc = [
        'title'  => $label,
        'slug'   => $slug,
        'source' => 'extra',
        'type'   => $type,
        'slides' => $slides
      ];

      if (write_json($out, $doc, $report, "$base [extra]")) {
        $report['processed'][] = [
          'file'   => $base,
          'source' => 'extra',
          'type'   => $type,
          'output' => basename($out),
          'chunks' => count($slides),
          'method' => 'native'
        ];
      }

      unset($slides, $doc);
    } catch (Throwable $e) {
      $report['errors'][] = "$base [extra]: " . $e->getMessage();
      if (is_file($out) && filesize($out) === 0) @unlink($out);
    }

    gc_collect_cycles();
  }
}


// ═══════════════════════════════════════════════════════════════
// Step 4: Remove orphaned JSONs (source file was renamed/deleted)
// ═══════════════════════════════════════════════════════════════
$allDataJsons = glob($dataDir . '/*.json') ?: [];
foreach ($allDataJsons as $jsonPath) {
  $jsonSlug = basename($jsonPath, '.json');
  if (!isset($validSlugs[$jsonSlug])) {
    @unlink($jsonPath);
    $report['cleaned'][] = basename($jsonPath) . ' (orphaned, removed)';
  }
}


// ═══════════════════════════════════════════════════════════════
// Step 5: Rebuild BM25 index used by data_index.php
// ═══════════════════════════════════════════════════════════════
rebuild_bm25_index($dataDir, $root . '/index_bm25.json', $report);


// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════
$report['summary'] = [
  'total_files'         => count($files) + count($extraFiles),
  'processed'           => count($report['processed']),
  'unchanged'           => count($report['unchanged']),
  'skipped'             => count($report['skipped']),
  'errors'              => count($report['errors']),
  'cleaned'             => count($report['cleaned']),
  'pdftotext_available' => $hasPdfTool,
  'mode'                => $force ? 'full' : 'incremental',
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
