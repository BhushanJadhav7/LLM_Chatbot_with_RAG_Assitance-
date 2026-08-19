<?php

/**
 * upload.php — File Upload Handler
 *
 * Validates an incoming PDF upload and forwards it to the FastAPI /upload
 * endpoint. The FastAPI layer handles saving the file to disk.
 *
 * POST /upload.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ── Request validation ────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('Method not allowed. Expected POST.', HTTP_BAD_REQUEST);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    send_json_error('No file received. Send a "file" field in multipart/form-data.', HTTP_BAD_REQUEST);
}

$upload = $_FILES['file'];

// PHP upload error check
if ($upload['error'] !== UPLOAD_ERR_OK) {
    $phpErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
    ];
    $msg = $phpErrors[$upload['error']] ?? 'Unknown upload error (code ' . $upload['error'] . ').';
    send_json_error($msg, HTTP_BAD_REQUEST);
}

// Size check (50 MB cap)
if ($upload['size'] > MAX_UPLOAD_SIZE) {
    $limitMB = round(MAX_UPLOAD_SIZE / 1024 / 1024);
    send_json_error("File too large. Maximum allowed size is {$limitMB} MB.", HTTP_UNPROCESSABLE_ENTITY);
}

if ($upload['size'] === 0) {
    send_json_error('File is empty.', HTTP_BAD_REQUEST);
}

// MIME-type validation via libmagic (not just extension)
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $upload['tmp_name']);
finfo_close($finfo);

if ($mimeType !== 'application/pdf') {
    send_json_error("Invalid file type ({$mimeType}). Only PDF files are accepted.", HTTP_UNPROCESSABLE_ENTITY);
}

// ── Forward to FastAPI ────────────────────────────────────────────────────────

$sanitizedName = sanitize_filename($upload['name']);
$tmpPath       = $upload['tmp_name'];

try {
    $backendResponse = forward_file_request('/upload', $tmpPath, $sanitizedName);
    send_json_response([
        'filename'         => $sanitizedName,
        'backend_response' => $backendResponse,
    ]);
} catch (Exception $e) {
    $code = $e->getCode() >= 400 ? $e->getCode() : HTTP_INTERNAL_SERVER_ERROR;
    send_json_error('Backend upload failed: ' . $e->getMessage(), $code);
}
