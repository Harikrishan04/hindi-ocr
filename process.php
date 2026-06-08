<?php
function handle_ocr_upload($file) {
    $uploadDir = __DIR__ . '/uploads/';
    $engine = __DIR__ . '/ocr_engine';
    $maxUploadBytes = 5 * 1024 * 1024;

    if (!is_executable($engine)) {
        return 'Error: OCR engine is not available.';
    }
    if (!isset($file['error'], $file['tmp_name'], $file['size']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'Error: Could not upload file.';
    }
    if ($file['size'] > $maxUploadBytes) {
        return 'Error: Image is too large. Maximum size is 5 MB.';
    }
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) {
        return 'Error: Could not create upload directory.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/tiff' => 'tif',
        'image/bmp' => 'bmp',
    ];
    if (!isset($allowed[$mime])) {
        return 'Error: Unsupported image type.';
    }

    $uploadPath = $uploadDir . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return 'Error: Could not store uploaded file.';
    }

    $cmd = escapeshellarg($engine) . ' ocr ' . escapeshellarg($uploadPath) . ' 2>&1';
    $output = shell_exec($cmd);
    if (is_file($uploadPath)) {
        unlink($uploadPath);
    }

    return (string) $output;
}
?>
