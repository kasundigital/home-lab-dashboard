<?php
declare(strict_types=1);

/**
 * Events the dashboard can announce, and the channels each can go to.
 * Per-channel on/off is stored as settings "notify_<event>_<channel>".
 */
const NOTIFY_EVENTS = [
    'new_bill' => 'New bill received from n8n/API',
    'bill_paid' => 'Bill marked as paid',
    'bill_reminder' => 'Daily reminder of due/overdue bills',
    'telegram_new_user' => 'New Telegram user started the bot',
    'telegram_watched_message' => 'Message from a selected Telegram user',
];
const NOTIFY_CHANNELS = ['telegram' => 'Telegram (admin chats)', 'discord' => 'Discord channel'];
const NOTIFY_DEFAULTS = [
    'new_bill' => ['telegram' => true, 'discord' => true],
    'bill_paid' => ['telegram' => false, 'discord' => true],
    'bill_reminder' => ['telegram' => true, 'discord' => true],
    'telegram_new_user' => ['telegram' => true, 'discord' => true],
    'telegram_watched_message' => ['telegram' => false, 'discord' => true],
];

function notify_enabled(string $event, string $channel): bool
{
    return setting_bool("notify_{$event}_{$channel}", NOTIFY_DEFAULTS[$event][$channel] ?? false);
}

/** POST JSON and decode the JSON reply. Returns [httpStatus, decodedBody|null, curlError|null]. */
function http_post_json(string $url, array $payload, int $timeout = 15): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $error = $body === false ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
    return [$status, is_array($decoded) ? $decoded : null, $error];
}

/** Call a Telegram Bot API method. Throws on transport or API errors. */
function telegram_api(string $method, array $params = [], int $timeout = 15): mixed
{
    $token = setting('telegram_bot_token');
    if (!$token) throw new RuntimeException('Telegram bot token is not configured.');
    [$status, $body, $error] = http_post_json("https://api.telegram.org/bot{$token}/{$method}", $params, $timeout);
    if ($error) throw new RuntimeException("Telegram request failed: $error");
    if (!$body || empty($body['ok'])) {
        $description = $body['description'] ?? "HTTP $status";
        throw new RuntimeException("Telegram $method failed: $description", $status);
    }
    return $body['result'];
}

function telegram_send(int|string $chatId, string $text): void
{
    // Telegram rejects messages over 4096 characters.
    telegram_api('sendMessage', [
        'chat_id' => $chatId,
        'text' => mb_substr($text, 0, 4000),
        'disable_web_page_preview' => true,
    ]);
}

/** @return list<string> */
function telegram_admin_chat_ids(): array
{
    $raw = (string)setting('telegram_admin_chat_ids', '');
    return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: []), fn($id) => preg_match('/^-?\d+$/', $id)));
}

function discord_send(string $text): void
{
    $webhook = setting('discord_webhook_url');
    if (!$webhook) throw new RuntimeException('Discord webhook URL is not configured.');
    [$status, $body, $error] = http_post_json($webhook, [
        'username' => getenv('APP_NAME') ?: 'Home Lab Dashboard',
        // Discord rejects content over 2000 characters.
        'content' => mb_substr($text, 0, 1990),
        'allowed_mentions' => ['parse' => []],
    ]);
    if ($error) throw new RuntimeException("Discord request failed: $error");
    if ($status < 200 || $status >= 300) throw new RuntimeException('Discord webhook failed: ' . ($body['message'] ?? "HTTP $status"));
}

/** Send an event to every channel enabled for it. Failures are logged, never thrown. */
function notify(string $event, string $text): void
{
    foreach (array_keys(NOTIFY_CHANNELS) as $channel) {
        if (!notify_enabled($event, $channel)) continue;
        if ($channel === 'telegram') {
            if (!setting('telegram_bot_token')) continue;
            foreach (telegram_admin_chat_ids() as $chatId) {
                notify_attempt($event, "telegram:$chatId", $text, fn() => telegram_send($chatId, $text));
            }
        } elseif ($channel === 'discord') {
            if (!setting('discord_webhook_url')) continue;
            notify_attempt($event, 'discord', $text, fn() => discord_send($text));
        }
    }
}

function notify_attempt(string $event, string $channel, string $text, callable $send): bool
{
    $error = null;
    try {
        $send();
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log("[notify] $event via $channel failed: $error");
    }
    $stmt = db()->prepare('INSERT INTO notification_log (event, channel, ok, message, error) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$event, $channel, $error === null ? 1 : 0, mb_substr($text, 0, 500), $error]);
    db()->exec('DELETE FROM notification_log WHERE id NOT IN (SELECT id FROM notification_log ORDER BY id DESC LIMIT 500)');
    return $error === null;
}
