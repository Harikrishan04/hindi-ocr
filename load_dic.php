<?php
$dictionary = [];

if (file_exists(__DIR__ . '/dic.txt')) {
    $lines = file(__DIR__ . '/dic.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $word) {
        $word = trim($word);
        if ($word !== '') {
            $dictionary[$word] = true;
        }
    }
}

// Optional format: one entry per line as "word frequency".
// Higher frequency words are preferred when edit scores are otherwise close.
if (file_exists(__DIR__ . '/word_frequency.txt')) {
    $lines = file(__DIR__ . '/word_frequency.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/u', trim($line));
        if (count($parts) < 2) {
            continue;
        }
        $word = $parts[0];
        $freq = max(1, (int) $parts[1]);
        $dictionary[$word] = $freq;
    }
}

ksort($dictionary, SORT_STRING);

?>
