<?php
declare(strict_types=1);
// lib/tokenizer.php — shared BM25 tokenizer for indexing and querying.
// Latin/numeric words pass through; CJK runs become overlapping character
// bigrams (standard trick for Chinese, which has no word boundaries).
// The indexer and the query endpoint MUST use the same tokenizer, so both
// require this file. After changing it, run a Force full re-index.

const LSB_CJK_RANGE = '\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}';

/** Lowercase and strip everything except letters/numbers. */
function lsb_norm(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $text);
    return trim((string)$text);
}

/**
 * Tokenize normalized text: whitespace-split words, then expand any CJK run
 * into bigrams (a lone CJK character stays a unigram).
 *
 * @return string[] tokens
 */
function lsb_tokenize(string $normalized): array {
    $tokens = [];
    foreach (preg_split('~\s+~u', $normalized) ?: [] as $word) {
        if ($word === '') continue;
        $parts = preg_split(
            '~([' . LSB_CJK_RANGE . ']+)~u',
            $word,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if ($parts === false) { $tokens[] = $word; continue; }
        foreach ($parts as $part) {
            if (preg_match('~^[' . LSB_CJK_RANGE . ']+$~u', $part)) {
                $chars = preg_split('~~u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $n = count($chars);
                if ($n === 1) {
                    $tokens[] = $chars[0];
                } else {
                    for ($i = 0; $i < $n - 1; $i++) {
                        $tokens[] = $chars[$i] . $chars[$i + 1];
                    }
                }
            } else {
                $tokens[] = $part;
            }
        }
    }
    return $tokens;
}

/** True if the term contains at least one CJK character. */
function lsb_has_cjk(string $term): bool {
    return (bool)preg_match('~[' . LSB_CJK_RANGE . ']~u', $term);
}
