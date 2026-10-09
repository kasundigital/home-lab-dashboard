<?php
declare(strict_types=1);

// Background loop: Telegram long polling + scheduled jobs (bill reminders).
// The container entrypoint restarts it when it exits, so it exits every
// ~10 minutes to pick up code/config changes and release memory.
require __DIR__ . '/../src/bootstrap.php';

$stopAt = time() + 600;
$log = fn(string $msg) => fwrite(STDOUT, '[worker ' . now() . "] $msg\n");
$log('started');

while (time() < $stopAt) {
    set_setting('worker_heartbeat', now());

    try {
        bill_reminder_job();
    } catch (Throwable $e) {
        $log('reminder job failed: ' . $e->getMessage());
    }

    if (setting('telegram_mode', 'off') === 'polling' && setting('telegram_bot_token')) {
        try {
            $count = telegram_poll(25);
            if ($count) $log("processed $count Telegram update(s)");
            set_setting('telegram_last_error', null);
        } catch (Throwable $e) {
            // 409 = the bot has a webhook (e.g. an n8n Telegram Trigger); use forward mode instead.
            set_setting('telegram_last_error', now() . ' ' . $e->getMessage());
            $log($e->getMessage());
            sleep(30);
        }
    } else {
        sleep(20);
    }
}
$log('restarting');
