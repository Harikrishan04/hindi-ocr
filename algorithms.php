<?php
/**
 * algorithms.php
 * Hindi Spell-Checker — Edit Distance Algorithms
 * All functions are UTF-8 / multibyte safe.
 */

mb_internal_encoding('UTF-8');

// ── 1. HAMMING DISTANCE ──────────────────────────────────────────────────────
// Counts positions where two equal-length strings differ.
// Returns -1 when lengths differ (undefined for unequal lengths).
function hamming_distance(string $a, string $b): int {
    $la = mb_strlen($a);
    $lb = mb_strlen($b);
    if ($la !== $lb) return -1;
    $dist = 0;
    for ($i = 0; $i < $la; $i++) {
        if (mb_substr($a, $i, 1) !== mb_substr($b, $i, 1)) $dist++;
    }
    return $dist;
}

// ── 2. LONGEST COMMON SUBSEQUENCE LENGTH ────────────────────────────────────
// Returns the LCS length via DP table.
// Similarity = lcs_length / max(len_a, len_b).
function lcs_length(string $a, string $b): int {
    $la = mb_strlen($a);
    $lb = mb_strlen($b);
    $dp = array_fill(0, $la + 1, array_fill(0, $lb + 1, 0));
    for ($i = 1; $i <= $la; $i++) {
        $ca = mb_substr($a, $i - 1, 1);
        for ($j = 1; $j <= $lb; $j++) {
            $dp[$i][$j] = ($ca === mb_substr($b, $j - 1, 1))
                ? $dp[$i-1][$j-1] + 1
                : max($dp[$i-1][$j], $dp[$i][$j-1]);
        }
    }
    return $dp[$la][$lb];
}

// ── 3. LEVENSHTEIN EDIT DISTANCE (multibyte) ────────────────────────────────
// Minimum insert / delete / substitute edits to transform a → b.
// PHP's built-in levenshtein() is ASCII-only — this version handles Devanagari.
// Early-exit optimisation: if current row minimum already exceeds $maxCost,
// the result will never be useful so we return $maxCost + 1 immediately.
function levenshtein_mb(string $a, string $b, int $maxCost = PHP_INT_MAX): int {
    $la = mb_strlen($a);
    $lb = mb_strlen($b);
    if ($la === 0) return $lb;
    if ($lb === 0) return $la;
    // Quick length-difference lower bound
    if (abs($la - $lb) > $maxCost) return $maxCost + 1;

    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $charA  = mb_substr($a, $i - 1, 1);
        $curr   = [$i];
        $rowMin = $i;
        for ($j = 1; $j <= $lb; $j++) {
            $cost     = ($charA === mb_substr($b, $j - 1, 1)) ? 0 : 1;
            $curr[$j] = min($curr[$j-1] + 1, $prev[$j] + 1, $prev[$j-1] + $cost);
            if ($curr[$j] < $rowMin) $rowMin = $curr[$j];
        }
        if ($rowMin > $maxCost) return $maxCost + 1; // early exit
        $prev = $curr;
    }
    return $prev[$lb];
}

// ── 4. JARO DISTANCE ────────────────────────────────────────────────────────
// Character-matching similarity score in [0, 1]; 1 = identical.
function jaro_distance(string $a, string $b): float {
    if ($a === $b) return 1.0;
    $la = mb_strlen($a);
    $lb = mb_strlen($b);
    if ($la === 0 || $lb === 0) return 0.0;

    $window   = max(0, (int) floor(max($la, $lb) / 2) - 1);
    $aMatched = array_fill(0, $la, false);
    $bMatched = array_fill(0, $lb, false);
    $matches  = 0;

    for ($i = 0; $i < $la; $i++) {
        $ca    = mb_substr($a, $i, 1);
        $start = max(0, $i - $window);
        $end   = min($i + $window + 1, $lb);
        for ($j = $start; $j < $end; $j++) {
            if ($bMatched[$j] || $ca !== mb_substr($b, $j, 1)) continue;
            $aMatched[$i] = $bMatched[$j] = true;
            $matches++;
            break;
        }
    }
    if ($matches === 0) return 0.0;

    $trans = 0;
    $k     = 0;
    for ($i = 0; $i < $la; $i++) {
        if (!$aMatched[$i]) continue;
        while (!$bMatched[$k]) $k++;
        if (mb_substr($a, $i, 1) !== mb_substr($b, $k, 1)) $trans++;
        $k++;
    }

    return (($matches / $la) + ($matches / $lb) + (($matches - $trans / 2) / $matches)) / 3;
}

// ── 4b. JARO-WINKLER ────────────────────────────────────────────────────────
// Extends Jaro with a prefix bonus (up to 4 chars, scaling factor p = 0.1).
function jaro_winkler(string $a, string $b, float $p = 0.1): float {
    $jaro   = jaro_distance($a, $b);
    $prefix = 0;
    $limit  = min(4, mb_strlen($a), mb_strlen($b));
    for ($i = 0; $i < $limit; $i++) {
        if (mb_substr($a, $i, 1) === mb_substr($b, $i, 1)) $prefix++;
        else break;
    }
    return $jaro + $prefix * $p * (1 - $jaro);
}

// ── SUGGESTION ENGINE ────────────────────────────────────────────────────────
//
// PERFORMANCE FIX FOR LARGE SORTED DICTIONARIES
// ─────────────────────────────────────────────
// The naive approach (call levenshtein_mb on every entry) is O(n × m²) and
// times out on dictionaries with tens of thousands of Devanagari words.
//
// Since load_dic.php produces a dictionary sorted in Unicode (UTF-8) order,
// we exploit that ordering:
//
//  1. Extract the sorted keys into a plain array once.
//  2. Binary-search for the position where $word would be inserted.
//  3. Expand a window of ±WINDOW_SIZE entries around that position.
//     Words near the same position share the same leading akshara(s), so
//     they are the most likely candidates regardless of which edit operation
//     produced the typo.
//  4. Run the full distance functions only on this small window (~200 words),
//     reducing the per-typo cost from O(n) to O(WINDOW_SIZE).
//
// Edge cases handled:
//  • Words that differ only by a suffix (prefix match) fall inside the window.
//  • Words that differ only by a prefix are caught by a secondary pass that
//    checks entries whose length is within ±MAX_LEN_DIFF of $word.
//  • If the window produces no candidate within MAX_LEV, we fall back to a
//    bounded full scan (still fast because levenshtein_mb exits early via
//    the $maxCost parameter).

define('WINDOW_SIZE',   100);   // entries either side of binary-search hit
define('MAX_LEN_DIFF',    3);   // ignore dict words longer/shorter by this much
define('MAX_LEV',          3);  // discard candidates with Levenshtein > this
define('MAX_OCR_COST',   3.2);  // allow visually likely OCR fixes beyond raw edits

function mb_chars(string $s): array {
    $chars = [];
    $len = mb_strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $chars[] = mb_substr($s, $i, 1);
    }
    return $chars;
}

function is_devanagari_mark(string $ch): bool {
    return preg_match('/^[\x{0900}-\x{0903}\x{093A}-\x{094D}\x{0951}-\x{0957}\x{0962}-\x{0963}]$/u', $ch) === 1;
}

function hindi_ocr_substitution_cost(string $a, string $b): float {
    if ($a === $b) return 0.0;

    $groups = [
        ['ा', 'ो', 'ौ', 'ॉ'],
        ['ि', 'ी', 'े', 'ै'],
        ['ु', 'ू', 'ृ'],
        ['ं', 'ँ', 'ॅ'],
        ['।', '|', '!'],
        ['न', 'त', 'ज', 'ि'],
        ['र', 'व', 'य'],
        ['ब', 'व', 'भ'],
        ['द', 'ध', 'घ'],
        ['क', 'क्', 'क़'],
        ['ज', 'ज़', 'ञ'],
        ['श', 'ष', 'स'],
        ['ह', 'म'],
        ['ड', 'ढ़', 'ढ'],
        ['ल', 'ळ'],
        ['ग', 'घ'],
        ['च', 'छ'],
    ];

    foreach ($groups as $group) {
        if (in_array($a, $group, true) && in_array($b, $group, true)) {
            return 0.45;
        }
    }

    if (is_devanagari_mark($a) && is_devanagari_mark($b)) {
        return 0.55;
    }

    return 1.0;
}

function hindi_ocr_confusions(string $ch): array {
    $groups = [
        ['ा', 'ो', 'ौ', 'ॉ'],
        ['ि', 'ी', 'े', 'ै'],
        ['ु', 'ू', 'ृ'],
        ['ं', 'ँ', 'ॅ'],
        ['।', '|', '!'],
        ['न', 'त', 'ि'],
        ['र', 'व', 'य'],
        ['ब', 'व', 'भ'],
        ['द', 'ध', 'घ'],
        ['क', 'क़'],
        ['ज', 'ज़', 'ञ'],
        ['श', 'ष', 'स'],
        ['ह', 'म'],
        ['ड', 'ढ़', 'ढ'],
        ['ल', 'ळ'],
        ['ग', 'घ'],
        ['च', 'छ'],
    ];

    foreach ($groups as $group) {
        if (in_array($ch, $group, true)) {
            return array_values(array_diff($group, [$ch]));
        }
    }
    return [];
}

function join_chars(array $chars): string {
    return implode('', $chars);
}

function hindi_ocr_variants(string $word, int $limit = 80): array {
    $chars = mb_chars($word);
    $variants = [];
    $n = count($chars);

    for ($i = 0; $i < $n && count($variants) < $limit; $i++) {
        foreach (hindi_ocr_confusions($chars[$i]) as $replacement) {
            $copy = $chars;
            $copy[$i] = $replacement;
            $variants[join_chars($copy)] = true;
            if (count($variants) >= $limit) break 2;
        }

        if (is_devanagari_mark($chars[$i])) {
            $copy = $chars;
            array_splice($copy, $i, 1);
            $variants[join_chars($copy)] = true;
        }
    }

    for ($i = 0; $i + 1 < $n && count($variants) < $limit; $i++) {
        $copy = $chars;
        [$copy[$i], $copy[$i + 1]] = [$copy[$i + 1], $copy[$i]];
        $variants[join_chars($copy)] = true;
    }

    unset($variants[$word]);
    return array_keys($variants);
}

function hindi_ocr_edit_cost(string $a, string $b, float $maxCost = INF): float {
    $ac = mb_chars($a);
    $bc = mb_chars($b);
    $la = count($ac);
    $lb = count($bc);

    if ($la === 0) return (float) $lb;
    if ($lb === 0) return (float) $la;

    $prev = [];
    for ($j = 0; $j <= $lb; $j++) {
        $prev[$j] = (float) $j;
    }

    for ($i = 1; $i <= $la; $i++) {
        $deleteCost = is_devanagari_mark($ac[$i - 1]) ? 0.55 : 1.0;
        $curr = [$prev[0] + $deleteCost];
        $rowMin = $curr[0];

        for ($j = 1; $j <= $lb; $j++) {
            $insertCost = is_devanagari_mark($bc[$j - 1]) ? 0.55 : 1.0;
            $subCost = hindi_ocr_substitution_cost($ac[$i - 1], $bc[$j - 1]);
            $curr[$j] = min(
                $curr[$j - 1] + $insertCost,
                $prev[$j] + $deleteCost,
                $prev[$j - 1] + $subCost
            );
            if ($curr[$j] < $rowMin) {
                $rowMin = $curr[$j];
            }
        }

        if ($rowMin > $maxCost) {
            return $maxCost + 0.01;
        }
        $prev = $curr;
    }

    return $prev[$lb];
}

function dictionary_frequency(array $dictionary, string $word): int {
    $value = $dictionary[$word] ?? 1;
    return is_numeric($value) ? max(1, (int) $value) : 1;
}

function suggestion_rank_score(float $ocrCost, int $lev, float $jw, int $frequency, int $lengthDiff): float {
    $frequencyBoost = min(4.0, log10($frequency + 1)) * 0.07;
    return $ocrCost + ($lev * 0.08) + ($lengthDiff * 0.35) - ($jw * 0.35) - $frequencyBoost;
}

function add_suggestion_candidate(array &$candidates, array &$seen, string $word, string $candidate, array $dictionary): void {
    if (isset($seen[$candidate])) {
        return;
    }

    $lev = levenshtein_mb($word, $candidate, MAX_LEV + 1);
    $ocrCost = hindi_ocr_edit_cost($word, $candidate, MAX_OCR_COST);
    if ($lev > MAX_LEV && $ocrCost > MAX_OCR_COST) {
        return;
    }

    $jw = jaro_winkler($word, $candidate);
    $frequency = dictionary_frequency($dictionary, $candidate);
    $lengthDiff = abs(mb_strlen($word) - mb_strlen($candidate));
    $score = suggestion_rank_score($ocrCost, $lev, $jw, $frequency, $lengthDiff);

    $candidates[] = [
        'word' => $candidate,
        'lev' => $lev,
        'jw' => $jw,
        'ocr_cost' => round($ocrCost, 3),
        'frequency' => $frequency,
        'length_diff' => $lengthDiff,
        'score' => round($score, 4),
    ];
    $seen[$candidate] = true;
}

function dictionary_insertion_point(array $keys, string $word): int {
    $lo = 0;
    $hi = count($keys) - 1;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        $cmp = strcmp($word, $keys[$mid]);
        if ($cmp === 0) {
            return $mid;
        } elseif ($cmp < 0) {
            $hi = $mid - 1;
        } else {
            $lo = $mid + 1;
        }
    }
    return $lo;
}

function collect_window_candidates(array &$candidates, array &$seen, string $word, array $dictionary, array $keys, int $center, int $windowSize): void {
    $n = count($keys);
    $wLen = mb_strlen($word);
    $start = max(0, $center - $windowSize);
    $end = min($n - 1, $center + $windowSize);

    for ($idx = $start; $idx <= $end; $idx++) {
        $dw = $keys[$idx];
        if (isset($seen[$dw])) continue;
        if (abs($wLen - mb_strlen($dw)) > MAX_LEN_DIFF) continue;
        add_suggestion_candidate($candidates, $seen, $word, $dw, $dictionary);
    }
}

function get_suggestions(string $word, array $dictionary, int $topN = 1): array {
    if (empty($dictionary)) return [];

    $wLen = mb_strlen($word);
    $keys = array_keys($dictionary);   // sorted UTF-8 order
    $n    = count($keys);

    $candidates = [];
    $seen       = [];

    // ── Collect candidates near the original word and likely OCR variants ──
    $searchTerms = array_merge([$word], hindi_ocr_variants($word));
    foreach ($searchTerms as $term) {
        $center = dictionary_insertion_point($keys, $term);
        collect_window_candidates($candidates, $seen, $word, $dictionary, $keys, $center, WINDOW_SIZE);
    }

    // ── Fallback: bounded full scan if window produced nothing ──
    // levenshtein_mb($a, $b, MAX_LEV) returns MAX_LEV+1 immediately when
    // the length difference alone rules out a match, so this is still fast.
    if (empty($candidates)) {
        foreach ($keys as $dw) {
            if (isset($seen[$dw])) continue;
            $dLen = mb_strlen($dw);
            if (abs($wLen - $dLen) > MAX_LEN_DIFF) continue;
            add_suggestion_candidate($candidates, $seen, $word, $dw, $dictionary);
        }
    }

    // Sort: Hindi OCR-aware score first; then raw edit distance and similarity.
    usort($candidates, fn($x, $y) =>
        $x['score'] !== $y['score']
            ? $x['score'] <=> $y['score']
            : ($x['lev'] !== $y['lev']
                ? $x['lev'] <=> $y['lev']
                : $y['jw'] <=> $x['jw'])
    );

    return array_slice($candidates, 0, $topN);
}
