<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/tokenizer.php';
header('Content-Type: application/json; charset=utf-8');

/**
 * BM25-backed course-material retrieval endpoint.
 *
 * Query:   /data_index.php?q=...&topk=2
 * No query: returns stats about the index.
 */

const INDEX_FILE = __DIR__ . '/index_bm25.json';
const MAX_CHARS = 120000;

function bad(string $message, int $code = 500): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function load_json_file(string $path): ?array {
    if (!is_file($path) || !is_readable($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function stop_words(): array {
    return [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
        'may', 'might', 'must', 'shall', 'can', 'to', 'of', 'in', 'for', 'on', 'with',
        'at', 'by', 'from', 'as', 'into', 'through', 'during', 'before', 'after',
        'and', 'but', 'or', 'nor', 'so', 'yet', 'not', 'only', 'than', 'too',
        'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'him', 'his', 'she',
        'her', 'it', 'its', 'they', 'them', 'their', 'what', 'which', 'who',
        'this', 'that', 'these', 'those', 'if', 'then', 'because', 'while',
        'where', 'when', 'how', 'why', 'all', 'each', 'every', 'any', 'some',
        'about', 'tell', 'explain', 'describe', 'discuss', 'please', 'help',
    ];
}

function query_terms(string $query): array {
    $terms = lsb_tokenize(lsb_norm($query));
    $stop = stop_words();
    $filtered = array_values(array_filter(
        $terms,
        // CJK bigrams/unigrams always count; latin terms must be >1 char and
        // not an English stop word.
        fn($term) => lsb_has_cjk($term)
            || (mb_strlen($term, 'UTF-8') > 1 && !in_array($term, $stop, true))
    ));
    return $filtered ?: $terms;
}

function doc_source(string $docId): string {
    $slug = explode('#', $docId, 2)[0];
    return $slug . '.json';
}

function doc_label(string $docId, string $text): string {
    $firstLine = trim(strtok($text, "\n") ?: '');
    if ($firstLine !== '') return $firstLine;
    return explode('#', $docId, 2)[0];
}

$index = load_json_file(INDEX_FILE);
if (!$index || empty($index['meta']) || empty($index['docs']) || empty($index['post'])) {
    bad('Search index is missing or invalid. Rebuild index_bm25.json.', 500);
}

$meta = $index['meta'];
$docs = $index['docs'];
$post = $index['post'];

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$topk = isset($_GET['topk']) ? (int)$_GET['topk'] : 2;
if ($topk <= 0) $topk = 2;
if ($topk > 5) $topk = 5;

if ($q === '') {
    echo json_encode([
        'ok' => true,
        'index' => basename(INDEX_FILE),
        'docs_total' => count($docs),
        'terms_total' => count($post),
        'built_at' => $meta['built_at'] ?? null,
        'hint' => '?q=proportionality&topk=2',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$terms = query_terms($q);
$N = max(1, (int)($meta['N'] ?? count($docs)));
$avgdl = max(1.0, (float)($meta['avgdl'] ?? 1.0));
$k1 = (float)($meta['k1'] ?? 1.5);
$b = (float)($meta['b'] ?? 0.75);

$scores = [];
foreach ($terms as $term) {
    if (empty($post[$term]) || !is_array($post[$term])) continue;

    $posting = $post[$term];
    $df = count($posting);
    $idf = log(1 + (($N - $df + 0.5) / ($df + 0.5)));

    foreach ($posting as $docId => $tf) {
        if (empty($docs[$docId])) continue;
        $doc = $docs[$docId];
        $dl = max(1.0, (float)($doc['len'] ?? 1));
        $tf = (float)$tf;
        $denominator = $tf + $k1 * (1 - $b + $b * ($dl / $avgdl));
        $scores[$docId] = ($scores[$docId] ?? 0.0) + $idf * (($tf * ($k1 + 1)) / $denominator);
    }
}

if (!$scores) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

arsort($scores, SORT_NUMERIC);

// The frontend historically passes topk as "number of lectures". Return enough
// ranked chunks to provide comparable context while keeping the existing API.
$maxDocs = $topk * 20;
$output = [];
$totalChars = 0;
$added = 0;

foreach ($scores as $docId => $score) {
    if ($added >= $maxDocs) break;
    $text = (string)($docs[$docId]['text'] ?? '');
    if ($text === '') continue;

    $textLen = mb_strlen($text, 'UTF-8');
    if ($totalChars + $textLen > MAX_CHARS && $added > 0) break;

    $output[] = [
        'source' => doc_source((string)$docId),
        'type' => 'bm25',
        'label' => doc_label((string)$docId, $text),
        'text' => $text,
        'score' => $score,
    ];
    $totalChars += $textLen;
    $added++;
}

echo json_encode($output, JSON_UNESCAPED_UNICODE);
