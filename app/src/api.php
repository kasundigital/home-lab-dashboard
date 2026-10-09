<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function api_respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/** Require "Authorization: Bearer <token>" or "X-API-Key: <token>" matching the stored API token. */
function api_require_token(): void
{
    $headers = function_exists('getallheaders') ? array_change_key_case(getallheaders() ?: [], CASE_LOWER) : [];
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $headers['authorization'] ?? '';
    $token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : ($_SERVER['HTTP_X_API_KEY'] ?? $headers['x-api-key'] ?? '');
    $expected = (string)setting('api_token', '');
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        api_respond(401, ['ok' => false, 'error' => 'Invalid or missing API token']);
    }
}

function api_json_body(): array
{
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) api_respond(400, ['ok' => false, 'error' => 'Request body must be a JSON object or array']);
    return $body;
}
