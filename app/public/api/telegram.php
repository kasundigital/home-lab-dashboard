<?php
declare(strict_types=1);
require __DIR__ . '/../../src/api.php';

// POST /api/telegram.php  {telegram update} | [updates...]
// For bots whose updates already go to n8n: forward the raw Telegram Trigger output here.
api_require_token();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_respond(405, ['ok' => false, 'error' => 'Use POST']);

$body = api_json_body();
$updates = array_is_list($body) ? $body : [$body];
$processed = 0;
foreach ($updates as $update) {
    if (!is_array($update)) continue;
    telegram_handle_update($update);
    $processed++;
}
api_respond(200, ['ok' => true, 'processed' => $processed]);
