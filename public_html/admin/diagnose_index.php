<?php
declare(strict_types=1);

// admin/diagnose_index.php — check chunk sizes in your index
// Access via: https://lawschoolchatbot.com/admin/diagnose_index.php

require __DIR__ . '/_auth.php';
lsb_require_admin();
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Index Diagnostic</h1>";
echo "<style>body{font-family:system-ui;max-width:1200px;margin:2rem auto;padding:0 1rem} table{border-collapse:collapse;width:100%} th,td{border:1px solid #ccc;padding:8px;text-align:left} .warn{background:#fef3c7} .error{background:#fee2e2} .ok{background:#d1fae5} pre{background:#f3f4f6;padding:1rem;overflow:auto;max-height:300px;font-size:12px}</style>";

// 1) Check DATA_DIR JSON files
echo "<h2>1. JSON Files in DATA_DIR</h2>";
$jsonFiles = glob(DATA_DIR . '/*.json') ?: [];
echo "<p>Found " . count($jsonFiles) . " JSON files</p>";

if (!empty($jsonFiles)) {
    echo "<table><tr><th>File</th><th>Slides</th><th>Min Size</th><th>Max Size</th><th>Avg Size</th><th>Status</th></tr>";
    
    foreach ($jsonFiles as $path) {
        $fname = basename($path);
        $raw = @file_get_contents($path);
        $j = json_decode($raw, true);
        
        if (!$j || empty($j['slides'])) {
            echo "<tr class='warn'><td>$fname</td><td colspan='5'>No slides or invalid JSON</td></tr>";
            continue;
        }
        
        $sizes = [];
        foreach ($j['slides'] as $s) {
            $text = $s['text'] ?? '';
            $notes = $s['notes'] ?? '';
            $sizes[] = strlen($text) + strlen($notes);
        }
        
        $min = min($sizes);
        $max = max($sizes);
        $avg = array_sum($sizes) / count($sizes);
        
        $status = 'ok';
        $class = 'ok';
        if ($max > 5000) {
            $status = 'CHUNKS TOO LARGE';
            $class = 'error';
        } elseif ($max > 2000) {
            $status = 'Warning: large chunks';
            $class = 'warn';
        }
        
        echo "<tr class='$class'>";
        echo "<td>$fname</td>";
        echo "<td>" . count($sizes) . "</td>";
        echo "<td>" . number_format($min) . "</td>";
        echo "<td>" . number_format($max) . "</td>";
        echo "<td>" . number_format($avg, 0) . "</td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 2) Check EXTRA_DIR TXT files
echo "<h2>2. TXT Files in EXTRA_DIR</h2>";
$txtFiles = glob(EXTRA_DIR . '/*.txt') ?: [];
echo "<p>Found " . count($txtFiles) . " TXT files</p>";

if (!empty($txtFiles)) {
    echo "<table><tr><th>File</th><th>Raw Size</th><th>Chunks (900 char limit)</th><th>Status</th></tr>";
    
    foreach ($txtFiles as $path) {
        $fname = basename($path);
        $raw = @file_get_contents($path);
        $rawSize = strlen($raw);
        
        // Simulate chunking
        $chunks = chunk_text_test(trim($raw), 900);
        $chunkCount = count($chunks);
        $maxChunk = 0;
        foreach ($chunks as $c) {
            $maxChunk = max($maxChunk, strlen($c));
        }
        
        $status = 'ok';
        $class = 'ok';
        if ($maxChunk > 1500) {
            $status = "Max chunk: $maxChunk chars";
            $class = 'warn';
        }
        
        echo "<tr class='$class'>";
        echo "<td>$fname</td>";
        echo "<td>" . number_format($rawSize) . "</td>";
        echo "<td>$chunkCount chunks</td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 3) Check UPLOAD_DIR for duplicates
echo "<h2>3. Potential Duplicates (UPLOAD_DIR vs EXTRA_DIR)</h2>";
$uploadFiles = glob(UPLOAD_DIR . '/*.txt') ?: [];
$extraNames = array_map('basename', $txtFiles);
$uploadNames = array_map('basename', $uploadFiles);

$duplicates = array_intersect($uploadNames, $extraNames);
if (empty($duplicates)) {
    echo "<p class='ok' style='padding:1rem'>No duplicates found.</p>";
} else {
    echo "<p class='error' style='padding:1rem'><strong>Warning:</strong> These files exist in BOTH UPLOAD_DIR and EXTRA_DIR, which may cause inconsistent results:</p>";
    echo "<ul>";
    foreach ($duplicates as $d) {
        echo "<li>$d</li>";
    }
    echo "</ul>";
}

// 4) Simulate a query
echo "<h2>4. Test Query: 'lai chee ying'</h2>";
$testQuery = 'lai chee ying';
$terms = array_filter(preg_split('/\s+/', strtolower($testQuery)));

// Build blocks like data_index.php does
$blocks = [];

// From JSON
foreach ($jsonFiles as $path) {
    $j = json_decode(@file_get_contents($path), true);
    if (!$j || empty($j['slides'])) continue;
    $title = $j['title'] ?? basename($path);
    foreach ($j['slides'] as $s) {
        if (empty($s['text'])) continue;
        $txt = $s['text'] . ' ' . ($s['notes'] ?? '');
        $blocks[] = [
            'source' => basename($path),
            'type' => 'slide',
            'text' => $txt,
            'size' => strlen($txt),
        ];
    }
}

// From EXTRA
foreach ($txtFiles as $path) {
    $raw = trim(@file_get_contents($path) ?: '');
    if ($raw === '') continue;
    $fname = basename($path);
    $chunks = chunk_text_test($raw, 900);
    foreach ($chunks as $chunk) {
        $blocks[] = [
            'source' => $fname,
            'type' => 'extra',
            'text' => $chunk,
            'size' => strlen($chunk),
        ];
    }
}

// Score
foreach ($blocks as &$b) {
    $hay = strtolower($b['text']);
    $score = 0;
    foreach ($terms as $t) {
        $score += substr_count($hay, $t);
    }
    $b['score'] = $score / log(max(50, strlen($hay)) + 10);
}
unset($b);

// Sort and get top 8
usort($blocks, fn($a, $b) => $b['score'] <=> $a['score']);
$top = array_slice($blocks, 0, 8);

$totalSize = array_sum(array_column($top, 'size'));

echo "<p>Total blocks in index: " . count($blocks) . "</p>";
echo "<p>Top 8 results total size: <strong>" . number_format($totalSize) . " chars</strong></p>";

if ($totalSize > 50000) {
    echo "<p class='error' style='padding:1rem'><strong>PROBLEM FOUND:</strong> Results are too large. See breakdown below.</p>";
}

echo "<table><tr><th>#</th><th>Source</th><th>Type</th><th>Size</th><th>Score</th><th>Preview</th></tr>";
foreach ($top as $i => $b) {
    $class = $b['size'] > 5000 ? 'error' : ($b['size'] > 2000 ? 'warn' : '');
    $preview = htmlspecialchars(substr($b['text'], 0, 150)) . '...';
    echo "<tr class='$class'>";
    echo "<td>" . ($i + 1) . "</td>";
    echo "<td>" . htmlspecialchars($b['source']) . "</td>";
    echo "<td>" . $b['type'] . "</td>";
    echo "<td>" . number_format($b['size']) . "</td>";
    echo "<td>" . number_format($b['score'], 4) . "</td>";
    echo "<td><small>$preview</small></td>";
    echo "</tr>";
}
echo "</table>";

// Helper function (copy of chunk_text from data_index.php)
function chunk_text_test(string $text, int $maxLen = 900): array {
    $text = trim($text);
    if ($text === '') return [];

    $paras = preg_split('/\n{2,}/u', $text) ?: [$text];
    $blocks = [];
    $buf = '';

    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') continue;

        if ($buf === '') {
            $buf = $p;
        } elseif (mb_strlen($buf . "\n\n" . $p, 'UTF-8') <= $maxLen) {
            $buf .= "\n\n" . $p;
        } else {
            $blocks[] = $buf;
            $buf = $p;
        }
    }
    if ($buf !== '') $blocks[] = $buf;
    return $blocks;
}
?>