<?php
/**
 * PHP FastAPI Communication Layer - Configuration
 *
 * Manages configuration and helper functions for communicating with the FastAPI backend.
 *
 * @version 1.0.0
 * @author Senior Full Stack Architect
 */

declare(strict_types=1);

// ============================================================================
// CONFIGURATION
// ============================================================================

/**
 * The base URL of the Python FastAPI backend.
 * Update this if your FastAPI server runs on a different address or port.
 */
define('FASTAPI_BASE_URL', 'http://127.0.0.1:8000');

/**
 * The timeout in seconds for requests to the FastAPI backend.
 */
define('REQUEST_TIMEOUT', 300);

/**
 * The maximum allowed file size for uploads, in bytes (e.g., 50MB).
 */
define('MAX_UPLOAD_SIZE', 50 * 1024 * 1024);

// ============================================================================
// HTTP STATUS CONSTANTS
// ============================================================================

define('HTTP_OK', 200);
define('HTTP_BAD_REQUEST', 400);
define('HTTP_UNPROCESSABLE_ENTITY', 422);
define('HTTP_INTERNAL_SERVER_ERROR', 500);
define('HTTP_SERVICE_UNAVAILABLE', 503);

// ============================================================================
// JSON RESPONSE HELPERS
// ============================================================================

/**
 * Sends a structured JSON response to the client and terminates the script.
 *
 * @param mixed $data The payload to include in the response.
 * @param int $statusCode The HTTP status code to send.
 * @return void
 */
function send_json_response($data, int $statusCode = HTTP_OK): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    $response = [
        'status' => ($statusCode >= 200 && $statusCode < 300) ? 'success' : 'error',
        'code' => $statusCode,
        'data' => $data,
    ];
    echo json_encode($response);
    exit;
}

/**
 * Sends a structured JSON error response and terminates the script.
 *
 * @param string $message The error message.
 * @param int $statusCode The HTTP status code.
 * @param array|null $details Optional additional error details.
 * @return void
 */
function send_json_error(string $message, int $statusCode = HTTP_BAD_REQUEST, ?array $details = null): void
{
    $errorData = ['message' => $message];
    if ($details !== null) {
        $errorData['details'] = $details;
    }
    send_json_response($errorData, $statusCode);
}

// ============================================================================
// COMMON HELPER FUNCTIONS
// ============================================================================

/**
 * Validates a JSON string and decodes it into an associative array.
 *
 * @param string $jsonString The JSON string to validate.
 * @return array|null The decoded array, or null if the JSON is invalid.
 */
function validate_json(string $jsonString): ?array
{
    $data = json_decode($jsonString, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $data;
}

/**
 * Sanitizes a filename to prevent security risks like directory traversal.
 *
 * @param string $filename The original filename.
 * @return string The sanitized filename.
 */
function sanitize_filename(string $filename): string
{
    // Remove path components and illegal characters
    $filename = preg_replace("/[^a-zA-Z0-9._-]/", "_", basename($filename));
    return empty($filename) ? 'unnamed_file' : $filename;
}

/**
 * Forwards a file to the FastAPI backend using a multipart/form-data POST request.
 *
 * @param string $endpoint The API endpoint to hit (e.g., '/upload').
 * @param string $tmpFilePath The path to the temporary uploaded file.
 * @param string $originalFilename The original name of the file.
 * @return array The decoded JSON response from the API.
 * @throws Exception if the cURL request fails or the API returns an error.
 */
function forward_file_request(string $endpoint, string $tmpFilePath, string $originalFilename): array
{
    $url = FASTAPI_BASE_URL . $endpoint;
    $ch = curl_init($url);

    if ($ch === false) {
        throw new Exception('Failed to initialize cURL session.', HTTP_INTERNAL_SERVER_ERROR);
    }

    try {
        $cfile = new CURLFile($tmpFilePath, mime_content_type($tmpFilePath), $originalFilename);
        $payload = ['file' => $cfile];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorNum = curl_errno($ch);
        $curlError = curl_error($ch);

        if ($curlErrorNum > 0) {
            throw new Exception("cURL Error: {$curlError}", HTTP_SERVICE_UNAVAILABLE);
        }

        if ($httpCode >= 400) {
            $errorDetails = validate_json((string)$responseBody) ?? ['raw_response' => $responseBody];
            throw new Exception("FastAPI Error: Received HTTP status {$httpCode}", $httpCode);
        }

        $decodedResponse = validate_json((string)$responseBody);
        if ($decodedResponse === null) {
            throw new Exception('Invalid JSON response from FastAPI.', HTTP_INTERNAL_SERVER_ERROR);
        }

        return $decodedResponse;
    } finally {
        curl_close($ch);
    }
}

/**
 * Makes a streaming POST request to the FastAPI chat endpoint.
 *
 * @param string $endpoint The API endpoint (e.g., '/chat').
 * @param array $payload The data to send as a JSON payload.
 * @return void
 */
function stream_chat_request(string $endpoint, array $payload): void
{
    $url = FASTAPI_BASE_URL . $endpoint;
    $ch = curl_init($url);

    if ($ch === false) {
        http_response_code(HTTP_INTERNAL_SERVER_ERROR);
        echo json_encode(['error' => 'Failed to initialize cURL session.']);
        return;
    }

    $jsonPayload = json_encode($payload);

    // Set headers for streaming
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    // Callback function to process each chunk of data
    $writeCallback = function ($ch, $chunk) {
        echo $chunk;
        flush(); // Flush the output buffer to the client
        ob_flush();
        return strlen($chunk); // Return the number of bytes written
    };

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: text/event-stream',
        'Content-Length: ' . strlen($jsonPayload)
    ]);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);
    curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false); // Needed for the write function to be called

    $curlResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlResponse === false || $httpCode >= 400) {
        // If an error occurs, we can't send headers anymore.
        // The best we can do is log it. The client will detect a broken stream.
        error_log("Streaming cURL Error: {$curlError} | HTTP Code: {$httpCode}");
    }

    curl_close($ch);
}
