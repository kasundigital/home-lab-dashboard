<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_integration') {
        $id = (int)($_POST['id'] ?? 0);
        $existingStmt = $pdo->prepare('SELECT * FROM integrations WHERE id = ?');
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();

        $name = trim((string)($_POST['name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'Other'));
        $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        $username = trim((string)($_POST['username'] ?? ''));
        $apiKey = trim((string)($_POST['api_key'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $verifySsl = isset($_POST['verify_ssl']) ? 1 : 0;

        if (!$existing) $error = 'Integration was not found.';
        elseif ($name === '') $error = 'Integration name is required.';
        elseif ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL)) $error = 'Base URL must be a valid URL.';
        else {
            $stmt = $pdo->prepare('UPDATE integrations SET name=:name,category=:category,base_url=:base_url,api_key=:api_key,username=:username,password=:password,enabled=:enabled,verify_ssl=:verify_ssl,notes=:notes,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $stmt->execute([
                ':name'=>$name, ':category'=>$category, ':base_url'=>$baseUrl,
                ':api_key'=>$apiKey !== '' ? $apiKey : ($existing['api_key'] ?? ''),
                ':username'=>$username,
                ':password'=>$password !== '' ? $password : ($existing['password'] ?? ''),
                ':enabled'=>$enabled, ':verify_ssl'=>$verifySsl, ':notes'=>$notes, ':id'=>$id
            ]);
            if ($baseUrl !== '') {
                $s=$pdo->prepare('UPDATE services SET url=? WHERE lower(name)=lower(?)');
                $s->execute([$baseUrl,$name]);
            }
            header('Location: /settings.php?saved=1#integration-'.$id);
            exit;
        }
    }

    // Test buttons save the form first, so a freshly pasted token can be tested straight away.
    if (in_array($action, ['save_notifications', 'test_telegram', 'test_discord'], true)) {
        $mode = (string)($_POST['telegram_mode'] ?? 'off');
        $discord = trim((string)($_POST['discord_webhook_url'] ?? ''));
        $callback = trim((string)($_POST['bill_status_webhook_url'] ?? ''));
        $adminIds = trim((string)($_POST['telegram_admin_chat_ids'] ?? ''));
        $botToken = trim((string)($_POST['telegram_bot_token'] ?? ''));

        if (!in_array($mode, ['off', 'polling', 'forward'], true)) $error = 'Unknown Telegram mode.';
        elseif ($botToken !== '' && !preg_match('/^\d+:[\w-]{20,}$/', $botToken)) $error = 'Telegram bot token looks wrong (expected 123456:ABC...).';
        elseif ($discord !== '' && !preg_match('#^https://(discord\.com|discordapp\.com|canary\.discord\.com|ptb\.discord\.com)/api/webhooks/#', $discord)) $error = 'Discord webhook URL must look like https://discord.com/api/webhooks/...';
        elseif ($callback !== '' && !filter_var($callback, FILTER_VALIDATE_URL)) $error = 'Bill status webhook must be a valid URL.';
        elseif ($adminIds !== '' && !preg_match('/^[\s,]*-?\d+([\s,]+-?\d+)*[\s,]*$/', $adminIds)) $error = 'Admin chat IDs must be numbers separated by commas.';
        else {
            if ($botToken !== '') {
                if ($botToken !== setting('telegram_bot_token')) set_setting('telegram_offset', '0');
                set_setting('telegram_bot_token', $botToken);
            }
            if ($discord !== '' || isset($_POST['clear_discord'])) set_setting('discord_webhook_url', $discord !== '' ? $discord : null);
            set_setting('telegram_mode', $mode);
            set_setting('telegram_admin_chat_ids', $adminIds);
            set_setting('bill_status_webhook_url', $callback !== '' ? $callback : null);
            set_setting('reminder_hour', (string)max(0, min(23, (int)($_POST['reminder_hour'] ?? 8))));
            set_setting('reminder_days_before', (string)max(0, min(60, (int)($_POST['reminder_days_before'] ?? 3))));
            foreach (array_keys(NOTIFY_EVENTS) as $event) {
                foreach (array_keys(NOTIFY_CHANNELS) as $channel) {
                    set_setting("notify_{$event}_{$channel}", isset($_POST['notify'][$event][$channel]) ? '1' : '0');
                }
            }
            if ($action === 'save_notifications') {
                header('Location: /settings.php?saved_notifications=1#notifications');
                exit;
            }
        }
    }

    if ($action === 'clear_telegram_token') {
        set_setting('telegram_bot_token', null);
        set_setting('telegram_offset', '0');
        header('Location: /settings.php?cleared=1#notifications');
        exit;
    }

    if ($action === 'regenerate_api_token') {
        set_setting('api_token', bin2hex(random_bytes(24)));
        header('Location: /settings.php?token=1#api');
        exit;
    }

    if (!$error && ($action === 'test_telegram' || $action === 'test_discord')) {
        $message = 'Test notification from ' . (getenv('APP_NAME') ?: 'Home Lab Dashboard') . ' at ' . now();
        try {
            if ($action === 'test_discord') {
                discord_send($message);
                $notice = 'Discord test message sent.';
            } else {
                $bot = telegram_api('getMe');
                $ids = telegram_admin_chat_ids();
                if (!$ids) throw new RuntimeException("Bot @{$bot['username']} works, but no admin chat IDs are set.");
                foreach ($ids as $id) telegram_send($id, $message);
                $notice = "Telegram test sent by @{$bot['username']} to " . count($ids) . ' chat(s).';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if ($action === 'clear_secret') {
        $id = (int)($_POST['id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        if (in_array($field,['api_key','password'],true)) {
            $stmt=$pdo->prepare("UPDATE integrations SET {$field}=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$id]);
            header('Location: /settings.php?cleared=1#integration-'.$id);
            exit;
        }
    }
}

if (isset($_GET['saved'])) $notice='Integration settings saved.';
if (isset($_GET['cleared'])) $notice='Stored secret cleared.';
if (isset($_GET['saved_notifications'])) $notice='Notification settings saved.';
if (isset($_GET['token'])) $notice='New API token generated. Update it in your n8n workflows.';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'SERVER_IP:8088');
$apiToken = (string)setting('api_token', '');
$telegramMode = setting('telegram_mode', 'off');
$telegramToken = setting('telegram_bot_token');
$telegramError = setting('telegram_last_error');
$workerHeartbeat = setting('worker_heartbeat');
$workerAlive = $workerHeartbeat && strtotime($workerHeartbeat) > time() - 120;
$notificationLog = $pdo->query('SELECT * FROM notification_log ORDER BY id DESC LIMIT 15')->fetchAll();

$integrations=$pdo->query('SELECT * FROM integrations ORDER BY category,name')->fetchAll();
$byCategory=[];
foreach($integrations as $i) $byCategory[$i['category']][]=$i;
$appName=getenv('APP_NAME') ?: 'Home Lab Dashboard';
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings · <?= h($appName) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= h(app_version()) ?>">
</head>
<body>
<div class="app-shell">
<aside class="sidebar">
<div class="brand"><div class="brand-mark">HL</div><div><strong>Home Lab</strong><span>Control Center</span></div></div>
<nav>
<a href="/#overview">Overview</a>
<a href="/#bills">Bills</a>
<a href="/#telegram">Telegram</a>
<a href="/#services">Services</a>
<a href="/#server">Server</a>
<a class="active" href="/settings.php">Settings</a>
</nav>
<div class="sidebar-footer">v<?= h(app_version()) ?></div>
</aside>

<main>
<header class="topbar">
<div><h1>Settings</h1><p>Configure apps, service URLs, API keys and tokens.</p></div>
<button id="themeToggle" class="icon-button" type="button">☾</button>
</header>

<?php if($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
<?php if($error): ?><div class="notice error-notice"><?= h($error) ?></div><?php endif; ?>

<section id="api">
<div class="section-heading"><div><h2>API for n8n</h2><p>Your n8n billing workflow sends bills here. Same <code>external_id</code> = same bill, so re-sending with <code>"status":"paid"</code> turns it green.</p></div></div>
<div class="panel settings-note">
<div class="settings-form">
<label class="full">API token<input readonly value="<?= h($apiToken) ?>" onclick="this.select()"></label>
</div>
<p class="code-help">n8n → <strong>HTTP Request</strong> node: Method <code>POST</code>, URL <code><?= h($baseUrl) ?>/api/bills.php</code>, header <code>Authorization: Bearer &lt;token&gt;</code>, JSON body:</p>
<pre>{
  "external_id": "EDL-1234567-2026-10",
  "provider": "EDL",
  "category": "Electricity",
  "account_no": "1234567",
  "billing_month": "2026-10",
  "amount": 1000,
  "due_date": "2026-10-25",
  "status": "unpaid",
  "usage_value": 120,
  "usage_unit": "kWh"
}</pre>
<p class="code-help">Send <code>{"external_id": "EDL-1234567-2026-10", "status": "paid"}</code> once paid. A paid bill stays green even if n8n re-sends it as unpaid; add <code>"reopen": true</code> to move it back. You can send several at once as <code>{"bills": [ ... ]}</code>. <code>GET <?= h($baseUrl) ?>/api/bills.php?status=unpaid</code> lists bills.</p>
<form method="post" class="settings-actions" onsubmit="return confirm('Generate a new token? n8n workflows using the old one will stop working.')"><button type="submit" name="action" value="regenerate_api_token">Generate new token</button></form>
</div>
</section>

<section id="notifications">
<div class="section-heading"><div><h2>Telegram &amp; Discord</h2><p>Background worker: <span class="badge <?= $workerAlive ? 'paid' : 'overdue' ?>"><?= $workerAlive ? 'Running' : 'Not running' ?></span> <?= $workerHeartbeat ? 'last seen ' . h($workerHeartbeat) : '' ?></p></div></div>
<form method="post" class="panel settings-note">
<div class="settings-form">
<label>Telegram bot token<input type="password" name="telegram_bot_token" autocomplete="new-password" placeholder="<?= h(integration_secret_display($telegramToken)) ?>"></label>
<label>How Telegram messages arrive<select name="telegram_mode">
<option value="off" <?= $telegramMode === 'off' ? 'selected' : '' ?>>Off</option>
<option value="polling" <?= $telegramMode === 'polling' ? 'selected' : '' ?>>Dashboard reads the bot directly (polling)</option>
<option value="forward" <?= $telegramMode === 'forward' ? 'selected' : '' ?>>n8n forwards updates to the dashboard</option>
</select></label>
<label class="full">Admin chat IDs (who gets alerts and may use /commands)<input name="telegram_admin_chat_ids" value="<?= h(setting('telegram_admin_chat_ids', '')) ?>" placeholder="123456789, 987654321"></label>
<label class="full">Discord webhook URL (the alert channel)<input type="password" name="discord_webhook_url" autocomplete="new-password" placeholder="<?= h(setting('discord_webhook_url') ? 'Configured – leave blank to keep' : 'https://discord.com/api/webhooks/...') ?>"></label>
<label class="full">Bill status webhook (optional – called when you mark a bill paid/unpaid, e.g. an n8n Webhook URL)<input type="url" name="bill_status_webhook_url" value="<?= h(setting('bill_status_webhook_url', '')) ?>" placeholder="http://192.168.100.10:5678/webhook/bill-status"></label>
<label>Daily reminder hour (0–23)<input type="number" min="0" max="23" name="reminder_hour" value="<?= h(setting('reminder_hour', '8')) ?>"></label>
<label>Remind this many days before due<input type="number" min="0" max="60" name="reminder_days_before" value="<?= h(setting('reminder_days_before', '3')) ?>"></label>
</div>

<?php if ($telegramMode === 'polling' && $telegramError): ?><div class="notice error-notice">Telegram: <?= h($telegramError) ?><?php if (str_contains($telegramError, 'webhook') || str_contains($telegramError, 'Conflict')): ?> — this bot already sends its updates to a webhook (probably n8n). Use “n8n forwards updates” instead.<?php endif; ?></div><?php endif; ?>
<?php if ($telegramMode === 'forward'): ?><p class="code-help">In n8n, after your <strong>Telegram Trigger</strong>, add an <strong>HTTP Request</strong> node: <code>POST <?= h($baseUrl) ?>/api/telegram.php</code>, header <code>Authorization: Bearer &lt;API token&gt;</code>, body = the trigger's JSON (<code>{{ JSON.stringify($json) }}</code>).</p><?php endif; ?>

<h3 class="service-category">Send these events to</h3>
<div class="table-wrap"><table class="matrix">
<thead><tr><th>Event</th><?php foreach (NOTIFY_CHANNELS as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach (NOTIFY_EVENTS as $event => $label): ?>
<tr><td><?= h($label) ?></td><?php foreach (array_keys(NOTIFY_CHANNELS) as $channel): ?><td><input type="checkbox" name="notify[<?= h($event) ?>][<?= h($channel) ?>]" value="1" <?= notify_enabled($event, $channel) ? 'checked' : '' ?>></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="settings-actions">
<?php if ($telegramToken): ?><button type="submit" name="action" value="clear_telegram_token" formnovalidate>Clear bot token</button><?php endif; ?>
<?php if (setting('discord_webhook_url')): ?><label class="switch-row"><input type="checkbox" name="clear_discord" value="1"><span>Remove Discord webhook</span></label><?php endif; ?>
<button type="submit" name="action" value="test_telegram">Test Telegram</button>
<button type="submit" name="action" value="test_discord">Test Discord</button>
<button class="primary-button" type="submit" name="action" value="save_notifications">Save</button>
</div>
<p class="code-help">Telegram setup: create a bot with <strong>@BotFather</strong>, paste the token, then message your bot and send <code>/start</code>. Your chat ID appears under Telegram → Manage users. Admin commands: <code>/bills</code>, <code>/paid 12</code>, <code>/status</code>, <code>/users</code>, <code>/watch @name</code>.</p>
</form>

<?php if ($notificationLog): ?>
<div class="panel table-panel" style="margin-top:12px"><div class="table-wrap"><table>
<thead><tr><th>Time</th><th>Event</th><th>Channel</th><th>Result</th><th>Message</th></tr></thead>
<tbody><?php foreach ($notificationLog as $row): ?>
<tr><td><?= h($row['created_at']) ?></td><td><?= h($row['event']) ?></td><td><?= h($row['channel']) ?></td><td><span class="badge <?= $row['ok'] ? 'paid' : 'overdue' ?>"><?= $row['ok'] ? 'Sent' : 'Failed' ?></span><?php if ($row['error']): ?><small><?= h($row['error']) ?></small><?php endif; ?></td><td><?= h(mb_strimwidth((string)$row['message'], 0, 80, '…')) ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
</section>

<section>
<div class="section-heading"><div><h2>App Integrations</h2><p>Leave API key/password blank to keep the currently stored value.</p></div></div>

<?php foreach($byCategory as $category=>$items): ?>
<h3 class="service-category"><?= h($category) ?></h3>
<div class="integration-grid">
<?php foreach($items as $integration): ?>
<article class="panel integration-card" id="integration-<?= (int)$integration['id'] ?>">
<form method="post">
<input type="hidden" name="id" value="<?= (int)$integration['id'] ?>">
<input type="hidden" name="field" value="">

<div class="integration-head">
<div><h3><?= h($integration['name']) ?></h3><span class="badge <?= $integration['enabled']?'paid':'unpaid' ?>"><?= $integration['enabled']?'Enabled':'Disabled' ?></span></div>
<label class="switch-row"><input type="checkbox" name="enabled" value="1" <?= $integration['enabled']?'checked':'' ?>><span>Enabled</span></label>
</div>

<div class="settings-form">
<label>Name<input name="name" value="<?= h($integration['name']) ?>" required></label>
<label>Category<select name="category">
<?php foreach(['Media','Automation','Monitoring','Network','External','Other'] as $option): ?>
<option <?= $integration['category']===$option?'selected':'' ?>><?= h($option) ?></option>
<?php endforeach; ?>
</select></label>

<label class="full">Base URL<input type="url" name="base_url" value="<?= h($integration['base_url']) ?>" placeholder="http://192.168.100.10:8989"></label>
<label>Username<input name="username" value="<?= h($integration['username']) ?>" autocomplete="off" placeholder="Optional"></label>
<label>API key / token<input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= h(integration_secret_display($integration['api_key'])) ?>"></label>
<label>Password<input type="password" name="password" value="" autocomplete="new-password" placeholder="<?= h(integration_secret_display($integration['password'])) ?>"></label>
<label class="switch-row compact"><input type="checkbox" name="verify_ssl" value="1" <?= $integration['verify_ssl']?'checked':'' ?>><span>Verify SSL/TLS</span></label>
<label class="full">Notes<textarea name="notes" rows="2" placeholder="Optional notes"><?= h($integration['notes']) ?></textarea></label>
</div>

<div class="integration-meta"><span>Key: <code><?= h($integration['slug']) ?></code></span><span>Updated: <?= h($integration['updated_at']) ?></span></div>

<div class="settings-actions">
<?php if(!empty($integration['api_key'])): ?><button type="submit" name="action" value="clear_secret" onclick="this.form.field.value='api_key'">Clear API key</button><?php endif; ?>
<?php if(!empty($integration['password'])): ?><button type="submit" name="action" value="clear_secret" onclick="this.form.field.value='password'">Clear password</button><?php endif; ?>
<button class="primary-button" type="submit" name="action" value="save_integration">Save</button>
</div>
</form>
</article>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</section>

<section><div class="panel settings-note"><strong>Security note</strong><p>Credentials are stored only in the local SQLite data volume and are never committed to Git. Keep this dashboard LAN/VPN-only until login and encrypted secret storage are added.</p></div></section>

<footer><span>Home Lab Dashboard v<?= h(app_version()) ?></span><span>Designed &amp; Developed by <a href="https://www.kasunindika.com" target="_blank" rel="noreferrer">Kasun Indika</a></span></footer>
</main>
</div>
<script src="/assets/app.js?v=<?= h(app_version()) ?>"></script>
</body>
</html>