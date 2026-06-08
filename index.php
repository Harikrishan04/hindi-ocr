<?php
/**
 * Hindi OCR & NLP Portal
 * Integrated with C++ Engine and Word Export
 * * Standardized for modern UI/UX, Accessibility (WCAG), and Maintainability.
 */

mb_internal_encoding('UTF-8');
mb_language('uni');

// Include dependencies (suppressed errors if missing for template rendering safety)
@include_once 'algorithms.php';
@include_once 'load_dic.php';

define('WORD_RE',  '/^[\p{L}\p{M}\x{200C}\x{200D}]+$/u');
define('TOKEN_RE', '/([\p{L}\p{M}\x{200C}\x{200D}]+|[^\p{L}\p{M}\x{200C}\x{200D}]+)/u');
define('UPLOAD_DIR', 'uploads/');

// 1. Download handler
if (isset($_POST['download_doc'])) {
    $content  = $_POST['extracted_text'];
    $filename = "Hindi_OCR_Report_" . date('Ymd_His') . ".doc";
    
    header("Content-Type: application/vnd.ms-word; charset=UTF-8");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Content-Disposition: attachment;filename=" . $filename);
    
    echo "<html lang='hi'><head><meta charset='UTF-8'><title>OCR Output</title></head><body>";
    echo "<h2>Extracted Hindi Text</h2>";
    echo "<p style='font-family: Arial, sans-serif; line-height: 1.6;'>"
       . nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
       . "</p>";
    echo "</body></html>";
    exit;
}

// 2. C++ engine output parser
function parse_engine_output(string $raw_output): array {
    $data  = ['text' => $raw_output, 'stats' => ['s'=>0,'w'=>0,'c'=>0,'a'=>0]];
    $parts = explode("#####STATS#####", $raw_output);
    
    if (count($parts) > 1) {
        $data['text'] = trim($parts[0]);
        $statsBlock   = $parts[1];
        
        preg_match('/W:(\d+)/',     $statsBlock, $w);
        preg_match('/S:(\d+)/',     $statsBlock, $s);
        preg_match('/C:(\d+)/',     $statsBlock, $c);
        preg_match('/A:([\d\.]+)/', $statsBlock, $a);
        
        $data['stats'] = [
            'w' => $w[1] ?? 0,
            's' => $s[1] ?? 0,
            'c' => $c[1] ?? 0,
            'a' => $a[1] ?? 0,
        ];
    }
    return $data;
}

// 3. OCR processing
$res          = null;
$uploadedName = '';
$uploadedB64  = '';
$errorMsg     = '';

if (isset($_POST['process']) && isset($_FILES['hindi_image'])) {
    try {
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
        
        $tmpName = $_FILES['hindi_image']['tmp_name'];
        $ext     = pathinfo($_FILES['hindi_image']['name'], PATHINFO_EXTENSION);
        $path    = UPLOAD_DIR . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $uploadedName = $_FILES['hindi_image']['name'];
        
        if (move_uploaded_file($tmpName, $path)) {
            $mime        = mime_content_type($path);
            $uploadedB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
            
            $cmd    = "./ocr_engine ocr " . escapeshellarg(realpath($path)) . " 2>&1";
            $output = shell_exec($cmd);
            $res    = parse_engine_output($output ?? '');
            
            unlink($path);
        } else {
            throw new Exception("Failed to upload image.");
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

// 4. Normalise dictionary keys to NFC
$normDictionary = [];
if (!empty($dictionary) && is_array($dictionary)) {
    foreach ($dictionary as $key => $val) {
        $normKey = normalizer_normalize(trim($key), Normalizer::FORM_C);
        if ($normKey !== false && $normKey !== '') {
            $normDictionary[$normKey] = $val;
        }
    }
}

// 5. Tokenise & spell-check logic
$inputText    = $res['text'] ?? '';
$renderedText = '';
$spellRows    = [];
$totalWords   = 0;
$knownWords   = 0;
$typoCount    = 0;

if (!empty($inputText)) {
    preg_match_all(TOKEN_RE, $inputText, $tokens);
    $out = '';
    
    foreach ($tokens[0] as $token) {
        if (!preg_match(WORD_RE, $token)) {
            $out .= htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            continue;
        }
        
        $totalWords++;
        $normWord = normalizer_normalize(trim($token), Normalizer::FORM_C);
        $normWord = ($normWord !== false) ? $normWord : trim($token);
        $safe     = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        
        if (isset($normDictionary[$normWord])) {
            $knownWords++;
            $out .= '<span>' . $safe . '</span>';
        } else {
            $typoCount++;
            $out .= '<span class="typo-word" data-word="' . $safe . '" aria-invalid="true">' . $safe . '</span>';
            
            // Dummy fallbacks if functions don't exist yet
            $suggestions = function_exists('get_suggestions') ? get_suggestions($normWord, $normDictionary, 5) : [];
            $nearest     = $suggestions[0]['word'] ?? '';
            
            $ham = ($nearest !== '' && function_exists('hamming_distance')) ? hamming_distance($normWord, $nearest) : -1;
            $lcsLen = ($nearest !== '' && function_exists('lcs_length')) ? lcs_length($normWord, $nearest) : 0;
            $lev = ($nearest !== '' && function_exists('levenshtein_mb')) ? levenshtein_mb($normWord, $nearest) : '—';
            $jw = ($nearest !== '' && function_exists('jaro_winkler')) ? round(jaro_winkler($normWord, $nearest) * 100, 1) : 0;
            
            $maxLen = max(mb_strlen($normWord), mb_strlen($nearest ?: $normWord));
            $lcsSim = $maxLen > 0 ? round($lcsLen / $maxLen * 100, 1) : 0;
            
            $spellRows[] = [
                'word'        => $normWord,
                'nearest'     => $nearest,
                'hamming'     => ($ham === -1) ? 'N/A' : (string)$ham,
                'lcs'         => $nearest !== '' ? "$lcsLen ({$lcsSim}%)" : '—',
                'levenshtein' => $lev,
                'jaro'        => $nearest !== '' ? "{$jw}%" : '—',
                'suggestions' => $suggestions,
            ];
        }
    }
    $renderedText = $out;
}
?>
<!DOCTYPE html>
<html lang="hi" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0c0a09">
<meta name="description" content="Optical Character Recognition and NLP analysis portal for Hindi text.">
<title>Hindi OCR & NLP Analysis Portal</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&family=Noto+Sans+Devanagari:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js" defer></script>

<style>
/* ==========================================================================
   CSS Variables & Design System
   ========================================================================== */
:root {
    /* Colors - Neutral Palette */
    --clr-bg-main: #f3f4f6;
    --clr-bg-surface: #ffffff;
    --clr-bg-subtle: #f9fafb;
    --clr-border: #e5e7eb;
    --clr-border-hover: #d1d5db;
    
    /* Colors - Text */
    --clr-text-primary: #111827;
    --clr-text-secondary: #4b5563;
    --clr-text-tertiary: #9ca3af;
    
    /* Colors - Brand/Status */
    --clr-primary: #2563eb;
    --clr-primary-hover: #1d4ed8;
    --clr-primary-bg: #eff6ff;
    
    --clr-success: #16a34a;
    --clr-success-bg: #dcfce7;
    --clr-success-text: #15803d;
    
    --clr-danger: #dc2626;
    --clr-danger-bg: #fee2e2;
    --clr-danger-text: #b91c1c;

    --clr-warning: #d97706;
    --clr-warning-bg: #fef3c7;
    
    /* Typography */
    --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
    --font-mono: 'JetBrains Mono', ui-monospace, monospace;
    --font-deva: 'Noto Sans Devanagari', sans-serif;
    
    /* Spacing & Layout */
    --space-1: 0.25rem;
    --space-2: 0.5rem;
    --space-3: 0.75rem;
    --space-4: 1rem;
    --space-5: 1.25rem;
    --space-6: 1.5rem;
    --space-8: 2rem;
    
    /* Borders & Shadows */
    --radius-sm: 0.375rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    
    /* Transitions */
    --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
}

/* ==========================================================================
   CSS Reset & Base Styles
   ========================================================================== */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: var(--font-sans);
    color: var(--clr-text-primary);
    background-color: var(--clr-bg-main);
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Focus outlines for Accessibility */
:focus-visible {
    outline: 2px solid var(--clr-primary);
    outline-offset: 2px;
    border-radius: 2px;
}

img, picture, svg {
    display: block;
    max-width: 100%;
}

button {
    font-family: inherit;
    cursor: pointer;
    border: none;
    background: none;
}

/* ==========================================================================
   Layout Components
   ========================================================================== */
.site-header {
    background-color: #0c0a09;
    color: #ffffff;
    padding: var(--space-3) var(--space-6);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 50;
    box-shadow: var(--shadow-sm);
}

.brand {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

.brand-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: var(--clr-primary);
    border-radius: var(--radius-sm);
    padding: var(--space-2);
}

.brand-icon svg {
    width: 1.25rem;
    height: 1.25rem;
}

.brand-text h1 {
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: -0.01em;
}

.brand-text p {
    font-size: 0.75rem;
    color: var(--clr-text-tertiary);
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--space-6);
    display: flex;
    flex-direction: column;
    gap: var(--space-6);
}

/* ==========================================================================
   Cards & Containers
   ========================================================================== */
.card {
    background-color: var(--clr-bg-surface);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.card-header {
    padding: var(--space-4);
    border-bottom: 1px solid var(--clr-border);
    background-color: var(--clr-bg-subtle);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-title {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--clr-text-primary);
}

.card-body {
    padding: var(--space-4);
}

.card-footer {
    padding: var(--space-3) var(--space-4);
    border-top: 1px solid var(--clr-border);
    background-color: var(--clr-bg-subtle);
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

/* ==========================================================================
   Form & Upload Zone
   ========================================================================== */
.upload-grid {
    display: grid;
    gap: var(--space-6);
    grid-template-columns: 1fr;
}

@media (min-width: 768px) {
    .upload-grid { grid-template-columns: 2fr 1fr; }
}

.upload-zone {
    position: relative;
    border: 2px dashed var(--clr-border);
    border-radius: var(--radius-md);
    background-color: var(--clr-bg-subtle);
    padding: var(--space-8) var(--space-4);
    text-align: center;
    transition: all var(--transition-fast);
    cursor: pointer;
}

.upload-zone:hover, .upload-zone.drag-active {
    border-color: var(--clr-primary);
    background-color: var(--clr-primary-bg);
}

.upload-input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.upload-icon {
    color: var(--clr-text-secondary);
    margin: 0 auto var(--space-3);
}

.upload-text {
    font-size: 0.875rem;
    color: var(--clr-text-secondary);
}

.upload-text strong {
    color: var(--clr-primary);
}

/* ==========================================================================
   Buttons
   ========================================================================== */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-4);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    font-weight: 500;
    transition: all var(--transition-fast);
    min-height: 2.5rem; /* Touch target minimum */
}

.btn-primary {
    background-color: var(--clr-primary);
    color: white;
    width: 100%;
}

.btn-primary:hover {
    background-color: var(--clr-primary-hover);
}

.btn-primary:disabled {
    background-color: var(--clr-text-tertiary);
    cursor: not-allowed;
}

.btn-secondary {
    background-color: var(--clr-bg-surface);
    border: 1px solid var(--clr-border);
    color: var(--clr-text-primary);
}

.btn-secondary:hover {
    background-color: var(--clr-bg-subtle);
    border-color: var(--clr-border-hover);
}

.btn-success {
    background-color: var(--clr-success);
    color: white;
}

.btn-success:hover {
    background-color: var(--clr-success-text);
}

/* Loading State for Buttons */
.spinner {
    display: none;
    animation: spin 1s linear infinite;
}

.btn.is-loading .spinner { display: block; }
.btn.is-loading .icon-default { display: none; }

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* ==========================================================================
   Results & Data Display
   ========================================================================== */
.results-grid {
    display: grid;
    gap: var(--space-6);
    grid-template-columns: 1fr;
    align-items: start;
}

@media (min-width: 1024px) {
    .results-grid { grid-template-columns: 320px 1fr; }
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-3);
}

.stat-tile {
    background-color: var(--clr-bg-surface);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-md);
    padding: var(--space-3);
}

.stat-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.05em;
    color: var(--clr-text-secondary);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 600;
    font-family: var(--font-mono);
    color: var(--clr-text-primary);
    margin-top: var(--space-1);
}

/* Progress / Accuracy Bar */
.progress-container {
    margin-bottom: var(--space-4);
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-2);
}

.progress-track {
    height: 0.5rem;
    background-color: var(--clr-border);
    border-radius: 9999px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.6s ease;
}

/* Dynamic Colors */
.color-good { color: var(--clr-success); }
.bg-good { background-color: var(--clr-success); }
.color-warn { color: var(--clr-warning); }
.bg-warn { background-color: var(--clr-warning); }
.color-bad { color: var(--clr-danger); }
.bg-bad { background-color: var(--clr-danger); }

/* Typography Engine Box */
.text-box {
    font-family: var(--font-deva);
    font-size: 1.125rem;
    line-height: 1.8;
    background-color: var(--clr-bg-subtle);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-sm);
    padding: var(--space-4);
    min-height: 150px;
    white-space: pre-wrap;
}

.typo-word {
    color: var(--clr-danger-text);
    text-decoration: underline wavy var(--clr-danger);
    text-underline-offset: 4px;
    cursor: help;
}

.w-fix {
    background-color: var(--clr-success-bg);
    color: var(--clr-success-text);
    padding: 0 4px;
    border-radius: 4px;
}

/* Data Tables */
.table-responsive {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
    min-width: 700px;
}

.table th {
    background-color: var(--clr-bg-subtle);
    color: var(--clr-text-secondary);
    font-weight: 600;
    text-transform: uppercase;
    text-align: left;
    padding: var(--space-3);
    border-bottom: 1px solid var(--clr-border);
}

.table td {
    padding: var(--space-3);
    border-bottom: 1px solid var(--clr-border);
    vertical-align: middle;
}

.table tbody tr:hover {
    background-color: var(--clr-bg-subtle);
}

/* Suggestion Chips */
.chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    background-color: var(--clr-primary-bg);
    color: var(--clr-primary-hover);
    border: 1px solid var(--clr-border);
    border-radius: 9999px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.chip:hover {
    background-color: var(--clr-primary);
    color: white;
}

.chip.applied {
    background-color: var(--clr-success-bg);
    color: var(--clr-success-text);
    border-color: var(--clr-success);
}

/* Screen Reader Only utility */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border-width: 0;
}
</style>
</head>
<body>

<header class="site-header">
    <div class="brand">
        <div class="brand-icon" aria-hidden="true">
            <i data-lucide="scan-text"></i>
        </div>
        <div class="brand-text">
            <h1>Hindi OCR Portal</h1>
            <p>Optical Character Recognition & NLP Analysis</p>
        </div>
    </div>
</header>

<main class="container">

    <?php if ($errorMsg): ?>
    <div class="card" style="border-left: 4px solid var(--clr-danger);" role="alert" aria-live="assertive">
        <div class="card-body" style="color: var(--clr-danger-text); display: flex; gap: var(--space-2);">
            <i data-lucide="alert-triangle"></i>
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    </div>
    <?php endif; ?>

    <section aria-labelledby="upload-heading">
        <h2 id="upload-heading" class="sr-only">Upload Image for OCR</h2>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="image-up"></i> Image Input</span>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="ocr-form">
                    <div class="upload-grid">
                        
                        <div class="upload-zone" id="drop-zone" tabindex="0" role="button" aria-describedby="upload-hint">
                            <input type="file" name="hindi_image" id="file-input" class="upload-input" accept="image/*" required aria-label="Select Hindi document image">
                            <i data-lucide="upload-cloud" class="upload-icon" style="width: 48px; height: 48px;"></i>
                            <p class="upload-text"><strong>Click to select</strong> or drag and drop</p>
                            <p class="upload-text" style="font-size: 0.75rem; margin-top: var(--space-1);" id="upload-hint">PNG, JPG, TIFF up to 10MB</p>
                            
                            <div id="img-preview" style="display: none; margin-top: var(--space-4); border-radius: var(--radius-sm); overflow: hidden;">
                                <img id="preview-img" src="" alt="Selected document preview" style="max-height: 200px; margin: 0 auto;">
                                <p id="preview-name" style="font-size: 0.75rem; margin-top: var(--space-2); color: var(--clr-text-secondary);"></p>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: var(--space-4);">
                            <div class="stat-tile" style="background-color: var(--clr-bg-subtle);">
                                <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: var(--space-2);">Analysis Features</h3>
                                <ul style="list-style: none; display: flex; flex-direction: column; gap: var(--space-2); font-size: 0.8125rem; color: var(--clr-text-secondary);">
                                    <li style="display: flex; align-items: center; gap: var(--space-2);"><i data-lucide="bar-chart-2" style="width: 16px;"></i> Sentence & Word counts</li>
                                    <li style="display: flex; align-items: center; gap: var(--space-2);"><i data-lucide="spell-check" style="width: 16px;"></i> Advanced Spell Checking</li>
                                    <li style="display: flex; align-items: center; gap: var(--space-2);"><i data-lucide="file-down" style="width: 16px;"></i> Microsoft Word Export</li>
                                </ul>
                            </div>
                            
                            <button type="submit" name="process" class="btn btn-primary" id="submit-btn" style="margin-top: auto;">
                                <i data-lucide="cpu" class="icon-default"></i>
                                <i data-lucide="loader-2" class="spinner"></i>
                                <span class="btn-text">Run OCR Analysis</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <?php if ($res): ?>
    <section aria-labelledby="results-heading">
        <h2 id="results-heading" class="sr-only">OCR Processing Results</h2>
        <div class="results-grid">
            
            <aside style="display: flex; flex-direction: column; gap: var(--space-4);">
                
                <?php if ($uploadedB64): ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i data-lucide="image"></i> Processed Image</span>
                    </div>
                    <div class="card-body" style="padding: 0;">
                        <img src="<?= $uploadedB64 ?>" alt="Processed Document" style="width: 100%; border-bottom-left-radius: var(--radius-lg); border-bottom-right-radius: var(--radius-lg);">
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i data-lucide="activity"></i> NLP Engine Stats</span>
                    </div>
                    <div class="card-body stat-grid">
                        <div class="stat-tile">
                            <div class="stat-label">Sentences</div>
                            <div class="stat-value"><?= htmlspecialchars((string)$res['stats']['s']) ?></div>
                        </div>
                        <div class="stat-tile">
                            <div class="stat-label">Words</div>
                            <div class="stat-value"><?= htmlspecialchars((string)$res['stats']['w']) ?></div>
                        </div>
                        <div class="stat-tile">
                            <div class="stat-label">Characters</div>
                            <div class="stat-value"><?= htmlspecialchars((string)$res['stats']['c']) ?></div>
                        </div>
                        <div class="stat-tile">
                            <div class="stat-label">Avg Len</div>
                            <div class="stat-value"><?= htmlspecialchars((string)$res['stats']['a']) ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($totalWords > 0): ?>
                <?php 
                    $accuracy = round(($knownWords / $totalWords) * 100);
                    $accClass = $accuracy >= 80 ? 'good' : ($accuracy >= 50 ? 'warn' : 'bad');
                ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i data-lucide="shield-check"></i> Dictionary Accuracy</span>
                    </div>
                    <div class="card-body">
                        <div class="progress-container">
                            <div class="progress-header">
                                <span class="stat-label">Accuracy Score</span>
                                <span class="stat-value color-<?= $accClass ?>" style="font-size: 1.25rem;"><?= $accuracy ?>%</span>
                            </div>
                            <div class="progress-track" aria-hidden="true">
                                <div class="progress-fill bg-<?= $accClass ?>" style="width: <?= $accuracy ?>%;"></div>
                            </div>
                        </div>
                        <div class="stat-grid">
                            <div class="stat-tile" style="grid-column: 1 / -1;">
                                <div class="stat-label">Total Valid Words</div>
                                <div class="stat-value"><?= $totalWords ?></div>
                            </div>
                            <div class="stat-tile" style="border-bottom: 3px solid var(--clr-success);">
                                <div class="stat-label">Known</div>
                                <div class="stat-value color-good"><?= $knownWords ?></div>
                            </div>
                            <div class="stat-tile" style="border-bottom: 3px solid var(--clr-danger);">
                                <div class="stat-label">Unknown</div>
                                <div class="stat-value color-bad"><?= $typoCount ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </aside>

            <div style="display: flex; flex-direction: column; gap: var(--space-4);">
                
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">
                            <i data-lucide="file-text"></i> Extracted Text
                        </span>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="extracted_text" value="<?= htmlspecialchars(strip_tags($renderedText), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <button type="submit" name="download_doc" class="btn btn-success" style="padding: var(--space-1) var(--space-3); min-height: 2rem;">
                                <i data-lucide="download" style="width: 14px;"></i> Export .doc
                            </button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="text-box" id="raw-text" lang="hi">
                            <?= !empty($renderedText) ? $renderedText : htmlspecialchars($inputText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="copyToClipboard('raw-text', this)" aria-live="polite">
                            <i data-lucide="copy"></i> Copy Text
                        </button>
                    </div>
                </div>

                <?php if ($totalWords > 0 && !empty($spellRows)): ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i data-lucide="table-2"></i> Typo Resolution</span>
                        <span style="font-size: 0.75rem; color: var(--clr-text-secondary);">Click chips to apply fixes</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Unrecognised Word</th>
                                    <th>Best Match</th>
                                    <th title="Levenshtein Distance">Lev.</th>
                                    <th title="Jaro-Winkler">J-W</th>
                                    <th>Suggestions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($spellRows as $row): 
                                    $origSafe = htmlspecialchars($row['word'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                    $nearSafe = htmlspecialchars($row['nearest'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                ?>
                                <tr>
                                    <td style="font-family: var(--font-deva); color: var(--clr-danger-text); text-decoration: underline wavy var(--clr-danger);"><?= $origSafe ?></td>
                                    <td style="font-family: var(--font-deva); font-weight: 600; color: var(--clr-success-text);"><?= $nearSafe ?: '—' ?></td>
                                    <td style="font-family: var(--font-mono); color: var(--clr-text-secondary);"><?= htmlspecialchars((string)$row['levenshtein']) ?></td>
                                    <td style="font-family: var(--font-mono); color: var(--clr-text-secondary);"><?= htmlspecialchars((string)$row['jaro']) ?></td>
                                    <td>
                                        <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                            <?php if (!empty($row['suggestions'])): ?>
                                                <?php foreach ($row['suggestions'] as $sug): 
                                                    $sugSafe = htmlspecialchars($sug['word'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                                ?>
                                                <button type="button" class="chip" data-orig="<?= $origSafe ?>" data-repl="<?= $sugSafe ?>" onclick="applyCorrection(this)">
                                                    <span style="font-family: var(--font-deva);"><?= $sugSafe ?></span>
                                                    <span style="font-family: var(--font-mono); opacity: 0.6; margin-left: 4px; padding-left: 4px; border-left: 1px solid currentColor;"><?= $sug['lev'] ?></span>
                                                </button>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span style="color: var(--clr-text-tertiary); font-style: italic;">No suggestions</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i data-lucide="check-square"></i> Corrected Document Preview</span>
                    </div>
                    <div class="card-body">
                        <div class="text-box" id="corrected-output" lang="hi">
                            <?php
                            preg_match_all(TOKEN_RE, $inputText, $allToks);
                            $typoSet = [];
                            foreach ($spellRows as $r) $typoSet[$r['word']] = true;
                            foreach ($allToks[0] as $tok) {
                                $safe = htmlspecialchars($tok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                if (!preg_match(WORD_RE, $tok)) {
                                    echo $safe;
                                } else {
                                    $nw = normalizer_normalize(trim($tok), Normalizer::FORM_C) ?: trim($tok);
                                    if (isset($typoSet[$nw])) {
                                        echo '<span class="typo-word w-unkn" data-word="' . $safe . '">' . $safe . '</span>';
                                    } else {
                                        echo $safe;
                                    }
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="copyToClipboard('corrected-output', this)" aria-live="polite">
                            <i data-lucide="copy"></i> Copy Corrected Text
                        </button>
                    </div>
                </div>
                
                <?php elseif ($totalWords > 0): ?>
                <div class="card" style="background-color: var(--clr-success-bg); border-color: var(--clr-success);">
                    <div class="card-body" style="display: flex; align-items: center; gap: var(--space-3); color: var(--clr-success-text);">
                        <i data-lucide="check-circle-2" style="width: 24px; height: 24px;"></i>
                        <div>
                            <h3 style="font-weight: 600;">Document is clean</h3>
                            <p style="font-size: 0.875rem;">All <?= $totalWords ?> words matched the known dictionary successfully.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </section>
    <?php endif; ?>

</main>

<script>
/**
 * Modern DOM Initialization
 */
document.addEventListener('DOMContentLoaded', () => {
    // 1. Initialize Icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // 2. Form State Management (UX Improvement)
    const form = document.getElementById('ocr-form');
    const submitBtn = document.getElementById('submit-btn');
    
    if (form) {
        form.addEventListener('submit', () => {
            if (submitBtn) {
                submitBtn.classList.add('is-loading');
                submitBtn.setAttribute('disabled', 'true');
                submitBtn.querySelector('.btn-text').textContent = 'Processing Document...';
            }
        });
    }

    // 3. File Input & Drag/Drop Preview (UX Improvement)
    const fileInput = document.getElementById('file-input');
    const dropZone = document.getElementById('drop-zone');
    const previewContainer = document.getElementById('img-preview');
    const previewImg = document.getElementById('preview-img');
    const previewName = document.getElementById('preview-name');

    const handleFileSelect = (file) => {
        if (!file || !file.type.startsWith('image/')) return;
        
        previewName.textContent = file.name;
        
        // Use native FileReader with error handling
        const reader = new FileReader();
        reader.onload = (e) => {
            previewImg.src = e.target.result;
            previewContainer.style.display = 'block';
            dropZone.querySelector('.upload-icon').style.display = 'none';
        };
        reader.onerror = () => {
            console.error('Error reading file for preview.');
        };
        reader.readAsDataURL(file);
    };

    if (fileInput) {
        fileInput.addEventListener('change', (e) => {
            handleFileSelect(e.target.files[0]);
        });
    }

    if (dropZone) {
        // Keyboard accessibility for drag zone
        dropZone.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                fileInput.click();
            }
        });

        // Drag events
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropZone.classList.add('drag-active');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropZone.classList.remove('drag-active');
            });
        });

        dropZone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length) {
                fileInput.files = files; // Update input
                handleFileSelect(files[0]);
            }
        });
    }
});

/**
 * Apply Correction Chip to Final Document
 * Modularized function for clarity
 */
function applyCorrection(buttonEl) {
    const origWord = buttonEl.getAttribute('data-orig');
    const replWord = buttonEl.getAttribute('data-repl');

    // Update text nodes
    const errorNodes = document.querySelectorAll('#corrected-output .w-unkn, #corrected-output .w-fix');
    errorNodes.forEach(node => {
        if (node.getAttribute('data-word') === origWord) {
            node.textContent = replWord;
            node.className = 'w-fix';
            node.removeAttribute('style'); // Remove inline wavy underline
            node.setAttribute('aria-invalid', 'false');
        }
    });

    // Toggle chip UI state
    document.querySelectorAll(`.chip[data-orig="${CSS.escape(origWord)}"]`).forEach(chip => {
        chip.classList.toggle('applied', chip === buttonEl);
        chip.setAttribute('aria-pressed', chip === buttonEl ? 'true' : 'false');
    });
}

/**
 * Clipboard Utility with Accessibility Announcements
 */
async function copyToClipboard(elementId, btnEl) {
    const el = document.getElementById(elementId);
    if (!el) return;

    try {
        await navigator.clipboard.writeText(el.innerText);
        
        // Provide visual and screen-reader feedback
        const originalHtml = btnEl.innerHTML;
        btnEl.innerHTML = '<i data-lucide="check"></i> Copied!';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        
        setTimeout(() => {
            btnEl.innerHTML = originalHtml;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }, 2000);
    } catch (err) {
        console.error('Failed to copy text: ', err);
        btnEl.innerHTML = '<i data-lucide="x"></i> Error';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}
</script>
</body>
</html>