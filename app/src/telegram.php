<?php
declare(strict_types=1);

/**
 * Process one Telegram update, whether it came from long polling (worker)
 * or was forwarded by n8n to /api/telegram.php.
 */
function telegram_handle_update(array $update): void
{
    $message = $update['message'] ?? $update['edited_message'] ?? null;
    $from = $message['from'] ?? null;
    if (!is_array($message) || !is_array($from) || !empty($from['is_bot']) || !isset($from['id'], $message['chat']['id'])) return;

    $pdo = db();
    $userId = (int)$from['id'];
    $chat = $message['chat'];
    $text = (string)($message['text'] ?? $message['caption'] ?? '');
    $kind = telegram_message_kind($message);
    $sentAt = isset($message['date']) ? date('Y-m-d H:i:s', (int)$message['date']) : now();

    // update_id makes forwarding/polling retries idempotent.
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO telegram_messages (update_id, user_id, chat_id, chat_title, message_id, kind, text, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$update['update_id'] ?? null, $userId, (int)$chat['id'], $chat['title'] ?? null, $message['message_id'] ?? null, $kind, $text, $sentAt]);
    if ($stmt->rowCount() === 0) return;

    $isAdmin = in_array((string)$userId, telegram_admin_chat_ids(), true);
    $user = telegram_upsert_user($from, $text !== '' ? $text : "[$kind]", $sentAt);
    if ($isAdmin && $user['row']['is_new']) $pdo->prepare('UPDATE telegram_users SET is_new = 0 WHERE user_id = ?')->execute([$userId]);

    if ($user['created'] && !$isAdmin) {
        notify('telegram_new_user', 'New Telegram user: ' . telegram_user_label($user['row']) . " (id {$userId})" . ($text !== '' ? "\nFirst message: " . mb_substr($text, 0, 300) : ''));
    }

    if ($isAdmin && str_starts_with($text, '/')) {
        $reply = telegram_command($text);
        if ($reply !== null) {
            try {
                telegram_send((int)$chat['id'], $reply);
            } catch (Throwable $e) {
                error_log('[telegram] reply failed: ' . $e->getMessage());
            }
        }
        return;
    }

    if ($user['row']['watched'] && !$user['created'] && !$isAdmin) {
        notify('telegram_watched_message', telegram_user_label($user['row']) . ': ' . ($text !== '' ? mb_substr($text, 0, 800) : "[$kind]"));
    }
}

function telegram_message_kind(array $message): string
{
    if (isset($message['text'])) return str_starts_with($message['text'], '/') ? 'command' : 'text';
    foreach (['photo', 'video', 'document', 'voice', 'audio', 'sticker', 'location', 'contact'] as $kind) {
        if (isset($message[$kind])) return $kind;
    }
    return 'other';
}

/** @return array{created: bool, row: array} */
function telegram_upsert_user(array $from, string $lastMessage, string $seenAt): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM telegram_users WHERE user_id = ?');
    $stmt->execute([(int)$from['id']]);
    $existing = $stmt->fetch();

    $profile = [
        ':username' => $from['username'] ?? null,
        ':first_name' => $from['first_name'] ?? null,
        ':last_name' => $from['last_name'] ?? null,
        ':language_code' => $from['language_code'] ?? null,
        ':last_message' => mb_substr($lastMessage, 0, 500),
        ':seen' => $seenAt,
        ':user_id' => (int)$from['id'],
    ];

    if (!$existing) {
        $pdo->prepare('INSERT INTO telegram_users (user_id, username, first_name, last_name, language_code, message_count, last_message, first_seen, last_seen)
            VALUES (:user_id, :username, :first_name, :last_name, :language_code, 1, :last_message, :seen, :seen)')->execute($profile);
    } else {
        $pdo->prepare('UPDATE telegram_users SET username = :username, first_name = :first_name, last_name = :last_name, language_code = :language_code,
            message_count = message_count + 1, last_message = :last_message, last_seen = max(last_seen, :seen) WHERE user_id = :user_id')->execute($profile);
    }

    $stmt->execute([(int)$from['id']]);
    return ['created' => !$existing, 'row' => $stmt->fetch()];
}

function telegram_user_label(array $user): string
{
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    if ($name === '') $name = 'User ' . $user['user_id'];
    return $user['username'] ? "$name (@{$user['username']})" : $name;
}

function telegram_find_user(string $ref): ?array
{
    $ref = ltrim(trim($ref), '@');
    $stmt = db()->prepare('SELECT * FROM telegram_users WHERE user_id = ? OR lower(username) = lower(?)');
    $stmt->execute([ctype_digit($ref) ? (int)$ref : -1, $ref]);
    return $stmt->fetch() ?: null;
}

function telegram_set_watched(int $userId, bool $watched): void
{
    db()->prepare('UPDATE telegram_users SET watched = ?, is_new = 0 WHERE user_id = ?')->execute([$watched ? 1 : 0, $userId]);
}

/** Admin-only bot commands. Returns the reply text, or null to stay silent. */
function telegram_command(string $text): ?string
{
    $parts = preg_split('/\s+/', trim($text)) ?: [];
    $command = strtolower(explode('@', ltrim(array_shift($parts) ?? '', '/'))[0]);
    $arg = $parts[0] ?? '';

    switch ($command) {
        case 'start':
        case 'help':
            return "Home Lab commands:\n"
                . "/bills – unpaid bills\n"
                . "/paid <id> – mark a bill paid\n"
                . "/unpaid <id> – mark a bill unpaid\n"
                . "/status – dashboard summary\n"
                . "/users – newest Telegram users\n"
                . "/watch <id|@username> – show this user's messages on the dashboard\n"
                . "/unwatch <id|@username> – hide this user's messages";

        case 'bills':
            $bills = bills_unpaid();
            if (!$bills) return 'All bills are paid. ✅';
            $total = array_sum(array_map(fn($b) => (float)$b['amount'], $bills));
            return 'Unpaid bills (' . money($total) . "):\n" . implode("\n", array_map(fn($b) => '• ' . bill_summary_line($b), $bills));

        case 'paid':
        case 'unpaid':
            if (!ctype_digit($arg)) return "Usage: /$command <bill id>  (see /bills for ids)";
            $bill = bill_set_status((int)$arg, $command, 'Telegram');
            return $bill ? '✅ ' . bill_summary_line($bill) : "Bill #$arg not found.";

        case 'status':
            $pdo = db();
            $bills = bills_unpaid();
            $total = array_sum(array_map(fn($b) => (float)$b['amount'], $bills));
            $overdue = count(array_filter($bills, fn($b) => bill_state($b) === 'overdue'));
            $users = (int)$pdo->query('SELECT COUNT(*) FROM telegram_users')->fetchColumn();
            $newUsers = (int)$pdo->query('SELECT COUNT(*) FROM telegram_users WHERE is_new = 1')->fetchColumn();
            $today = (int)$pdo->query("SELECT COUNT(*) FROM telegram_messages WHERE sent_at >= '" . today() . "'")->fetchColumn();
            return "Home Lab status\n"
                . 'Unpaid bills: ' . count($bills) . ' (' . money($total) . ")" . ($overdue ? ", $overdue overdue" : '') . "\n"
                . "Telegram users: $users ($newUsers new)\n"
                . "Messages today: $today\n"
                . 'Worker last run: ' . (setting('worker_heartbeat') ?? 'never');

        case 'users':
            $rows = db()->query('SELECT * FROM telegram_users ORDER BY first_seen DESC LIMIT 10')->fetchAll();
            if (!$rows) return 'No Telegram users yet.';
            return "Newest users:\n" . implode("\n", array_map(
                fn($u) => '• ' . telegram_user_label($u) . " – id {$u['user_id']}" . ($u['watched'] ? ' 👁' : ''),
                $rows
            ));

        case 'watch':
        case 'unwatch':
            $user = $arg !== '' ? telegram_find_user($arg) : null;
            if (!$user) return "Usage: /$command <user id|@username>  (see /users)";
            telegram_set_watched((int)$user['user_id'], $command === 'watch');
            return ($command === 'watch' ? '👁 Showing messages from ' : 'Hidden messages from ') . telegram_user_label($user);
    }

    return 'Unknown command. Send /help for the list.';
}

/** One long-poll cycle. Used by the worker when the dashboard owns the bot. */
function telegram_poll(int $timeout = 25): int
{
    $offset = (int)setting('telegram_offset', '0');
    $updates = telegram_api('getUpdates', [
        'offset' => $offset,
        'timeout' => $timeout,
        'allowed_updates' => ['message', 'edited_message'],
    ], $timeout + 10);

    foreach ($updates as $update) {
        try {
            telegram_handle_update($update);
        } catch (Throwable $e) {
            error_log('[telegram] update ' . ($update['update_id'] ?? '?') . ' failed: ' . $e->getMessage());
        }
        set_setting('telegram_offset', (string)((int)$update['update_id'] + 1));
    }
    return count($updates);
}
