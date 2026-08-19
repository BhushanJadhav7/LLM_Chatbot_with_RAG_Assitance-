<?php

/**
 * PHP FastAPI Communication Layer - Chat Handler
 *
 * Handles chat requests from the frontend, validates input,
 * forwards to FastAPI backend, and returns responses.
 *
 * Endpoint: POST /chat.php
 *
 * @version 1.1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

set_time_limit(300);

// ============================================================================
// REQUEST VALIDATION
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('Invalid request method. Expected POST.', HTTP_BAD_REQUEST);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') === false) {
    send_json_error('Invalid Content-Type. Expected application/json.', HTTP_BAD_REQUEST);
}

$requestBody = file_get_contents('php://input');

if (empty($requestBody)) {
    send_json_error('Request body is empty.', HTTP_BAD_REQUEST);
}

// ============================================================================
// INPUT PARSING AND VALIDATION
// ============================================================================

$input = validate_json($requestBody);

if ($input === null) {
    send_json_error('Invalid JSON input.', HTTP_BAD_REQUEST);
}

$message = isset($input['message']) ? (string)$input['message'] : '';

// Trim only — do NOT HTML-encode; this goes to an AI model, not a browser
$message = trim($message);

if (empty($message)) {
    send_json_error('Message cannot be empty.', HTTP_BAD_REQUEST);
}

if (strlen($message) > 5000) {
    send_json_error('Message is too long. Maximum 5000 characters.', HTTP_UNPROCESSABLE_ENTITY);
}

$maxNewTokens = isset($input['max_new_tokens']) ? (int)$input['max_new_tokens'] : 512;
$k            = isset($input['k']) ? (int)$input['k'] : 3;

$maxNewTokens = max(128, min(4096, $maxNewTokens));
$k            = max(1, min(10, $k));

// ============================================================================
// FORWARD TO FASTAPI
// ============================================================================

$url = FASTAPI_BASE_URL . '/chat';
$ch  = curl_init($url);

if ($ch === false) {
    send_json_error('Failed to initialize cURL session.', HTTP_INTERNAL_SERVER_ERROR);
}

$payload     = json_encode(['message' => $message, 'max_new_tokens' => $maxNewTokens, 'k' => $k]);
$headers     = ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)];

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

$responseBody = curl_exec($ch);
$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError    = curl_error($ch);
$curlErrno    = curl_errno($ch);
curl_close($ch);

if ($curlErrno > 0) {
    send_json_error('Cannot reach Python API: ' . $curlError, HTTP_SERVICE_UNAVAILABLE);
}

$decoded = validate_json((string)$responseBody);

if ($httpCode >= 400 || $decoded === null) {
    $detail = $decoded['detail'] ?? $responseBody;
    send_json_error('API error: ' . $detail, $httpCode >= 400 ? $httpCode : HTTP_INTERNAL_SERVER_ERROR);
}

// FastAPI returns { "response": "..." }
// Wrap it in the envelope the JS expects: { status: "success", data: { response: "..." } }
send_json_response(['response' => $decoded['response'] ?? '']);
