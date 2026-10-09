<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_bill') {
        $provider = trim((string) ($_POST['provider'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'Other'));
        $billingMonth = trim((string) ($_POST['billing_month'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $usageValue = trim((string) ($_POST['usage_value'] ?? ''));
        $usageUnit = trim((string) ($_POST['usage_unit'] ?? ''));
        $accountNo = trim((string) ($_POST['account_no'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($provider !== '' && $billingMonth !== '' && $amount >= 0) {
            $stmt = $pdo->prepare(
                'INSERT INTO bills (provider, category, account_no, billing_month, amount, due_date, usage_value, usage_unit, notes)
                 VALUES (:provider, :category, :account_no, :billing_month, :amount, :due_date, :usage_value, :usage_unit, :notes)'
            );
            $stmt->execute([
                ':provider' => $provider,
                ':category' => $category,
                ':account_no' => $accountNo !== '' ? $accountNo : null,
                ':billing_month' => $billingMonth,
                ':amount' => $amount,
                ':due_date' => $dueDate !== '' ? $dueDate : null,
                ':usage_value' => $usageValue !== '' ? (float) $usageValue : null,
                ':usage_unit' => $usageUnit !== '' ? $usageUnit : null,
                ':notes' => $notes !== '' ? $notes : null,
            ]);
            header('Location: /?saved=1');
            exit;
        }
        $notice = 'Provider, billing month and amount are required.';
    }

    if ($action === 'mark_paid' || $action === 'mark_unpaid') {
        bill_set_status((int) ($_POST['id'] ?? 0), $action === 'mark_paid' ? 'paid' : 'unpaid', 'dashboard');
        header('Location: /#bills');
        exit;
    }

    if ($action === 'telegram_watch' || $action === 'telegram_dismiss') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($action === 'telegram_watch') telegram_set_watched($userId, true);
        else $pdo->prepare('UPDATE telegram_users SET is_new = 0 WHERE user_id = ?')->execute([$userId]);
        header('Location: /#telegram');
        exit;
    }

    if ($action === 'delete_bill') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM bills WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: /');
        exit;
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Bill saved successfully.';
}

// Unpaid first (soonest due on top), then paid bills newest first.
$bills = $pdo->query('SELECT * FROM bills ORDER BY status = "paid", CASE WHEN status = "paid" THEN COALESCE(paid_date, updated_at) END DESC, COALESCE(due_date, "9999-12-31") ASC, id DESC LIMIT 100')->fetchAll();
$services = $pdo->query('SELECT * FROM services ORDER BY category, sort_order, name')->fetchAll();

$unpaidBills = bills_unpaid();
$unpaidTotal = array_sum(array_map(fn($b) => (float) $b['amount'], $unpaidBills));
$unpaidCount = count($unpaidBills);
$overdueCount = count(array_filter($unpaidBills, fn($b) => bill_state($b) === 'overdue'));
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM bills WHERE status = "paid" AND paid_date >= ?');
$stmt->execute([date('Y-m-01')]);
$paidThisMonth = (float) $stmt->fetchColumn();

$telegramUserCount = (int) $pdo->query('SELECT COUNT(*) FROM telegram_users')->fetchColumn();
$telegramNewUsers = $pdo->query('SELECT * FROM telegram_users WHERE is_new = 1 ORDER BY first_seen DESC LIMIT 12')->fetchAll();
$telegramNewCount = (int) $pdo->query('SELECT COUNT(*) FROM telegram_users WHERE is_new = 1')->fetchColumn();
$telegramMessages = $pdo->query('SELECT m.*, u.username, u.first_name, u.last_name, u.user_id FROM telegram_messages m
    JOIN telegram_users u ON u.user_id = m.user_id WHERE u.watched = 1 ORDER BY m.sent_at DESC, m.id DESC LIMIT 30')->fetchAll();
$billStateLabels = BILL_STATE_LABELS;

$servicesByCategory = [];
foreach ($services as $service) {
    $servicesByCategory[$service['category']][] = $service;
}

$appName = getenv('APP_NAME') ?: 'Home Lab Dashboard';
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= h(app_version()) ?>">
</head>
<body data-auto-refresh="60">
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">HL</div>
            <div><strong>Home Lab</strong><span>Control Center</span></div>
        </div>
        <nav>
            <a class="active" href="#overview">Overview</a>
            <a href="#bills">Bills</a>
            <a href="#telegram">Telegram<?php if ($telegramNewCount): ?> <span class="nav-count"><?= $telegramNewCount ?></span><?php endif; ?></a>
            <a href="#services">Services</a>
            <a href="#server">Server</a>
            <a href="/settings.php">Settings</a>
        </nav>
        <div class="sidebar-footer">v<?= h(app_version()) ?></div>
    </aside>

    <main>
        <header class="topbar">
            <div>
                <h1>Home Lab Dashboard</h1>
                <p>Household, media and server overview</p>
            </div>
            <button id="themeToggle" class="icon-button" type="button" aria-label="Toggle theme">☾</button>
        </header>

        <?php if ($notice): ?>
            <div class="notice"><?= h($notice) ?></div>
        <?php endif; ?>

        <section id="overview">
            <div class="section-heading">
                <div><h2>Overview</h2><p>Quick glance across your home lab.</p></div>
            </div>
            <div class="kpi-grid">
                <article class="kpi-card <?= $unpaidCount ? 'kpi-danger' : 'kpi-good' ?>">
                    <span>To pay</span>
                    <strong><?= money($unpaidTotal) ?></strong>
                    <small><?= $unpaidCount ?> unpaid<?= $overdueCount ? " · $overdueCount overdue" : '' ?></small>
                </article>
                <article class="kpi-card kpi-good">
                    <span>Paid this month</span>
                    <strong><?= money($paidThisMonth) ?></strong>
                    <small><?= h(date('F Y')) ?></small>
                </article>
                <a class="kpi-card" href="#telegram">
                    <span>Telegram users</span>
                    <strong><?= $telegramUserCount ?></strong>
                    <small><?= $telegramNewCount ?> new</small>
                </a>
                <article class="kpi-card">
                    <span>Server</span>
                    <strong class="status-good">Online</strong>
                    <small>Local dashboard active</small>
                </article>
            </div>
        </section>

        <section id="bills">
            <div class="section-heading">
                <div><h2>Household Bills</h2><p>Bills from n8n appear here automatically. Red = to pay, green = paid.</p></div>
                <button class="primary-button" type="button" data-dialog-open="billDialog">+ Add bill</button>
            </div>

            <div class="panel table-panel">
                <?php if (!$bills): ?>
                    <div class="empty-state">No bills yet. Send them from n8n (see Settings → API) or add one manually.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Provider</th><th>Month</th><th>Usage</th><th>Due</th><th>Amount</th><th>Status</th><th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bills as $bill): $state = bill_state($bill); ?>
                                <tr class="bill-row bill-<?= h($state) ?>">
                                    <td><strong><?= h($bill['provider']) ?></strong><small>#<?= (int) $bill['id'] ?> · <?= h($bill['category']) ?><?= $bill['account_no'] ? ' · ' . h($bill['account_no']) : '' ?><?= $bill['source'] !== 'manual' ? ' · ' . h($bill['source']) : '' ?></small></td>
                                    <td><?= h($bill['billing_month']) ?></td>
                                    <td><?= $bill['usage_value'] !== null ? h((string) $bill['usage_value']) . ' ' . h($bill['usage_unit']) : '—' ?></td>
                                    <td><?= h($bill['due_date'] ?: '—') ?></td>
                                    <td><?= money((float) $bill['amount']) ?></td>
                                    <td><span class="badge <?= h($state) ?>"><?= h($billStateLabels[$state]) ?></span><?php if ($bill['paid_date']): ?><small><?= h($bill['paid_date']) ?></small><?php endif; ?></td>
                                    <td class="actions">
                                        <?php if ($bill['status'] !== 'paid'): ?>
                                            <form method="post"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= (int) $bill['id'] ?>"><button class="good" type="submit">Mark paid</button></form>
                                        <?php else: ?>
                                            <form method="post"><input type="hidden" name="action" value="mark_unpaid"><input type="hidden" name="id" value="<?= (int) $bill['id'] ?>"><button type="submit">Undo</button></form>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('Delete this bill?')"><input type="hidden" name="action" value="delete_bill"><input type="hidden" name="id" value="<?= (int) $bill['id'] ?>"><button class="danger" type="submit">Delete</button></form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section id="telegram">
            <div class="section-heading">
                <div><h2>Telegram</h2><p>New users and messages from the users you selected.</p></div>
                <a class="primary-button" href="/telegram.php">Manage users</a>
            </div>
            <div class="telegram-grid">
                <div class="panel list-panel">
                    <h3>New users <span class="muted"><?= $telegramNewCount ?></span></h3>
                    <?php if (!$telegramNewUsers): ?>
                        <div class="empty-state">No new users.</div>
                    <?php endif; ?>
                    <?php foreach ($telegramNewUsers as $user): ?>
                        <div class="list-row">
                            <div><strong><?= h(telegram_user_label($user)) ?></strong><small><?= h($user['first_seen']) ?> · <?= h(mb_strimwidth((string) $user['last_message'], 0, 60, '…')) ?></small></div>
                            <div class="actions">
                                <form method="post"><input type="hidden" name="action" value="telegram_watch"><input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>"><button class="good" type="submit">Show messages</button></form>
                                <form method="post"><input type="hidden" name="action" value="telegram_dismiss"><input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>"><button type="submit">Dismiss</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="panel list-panel">
                    <h3>Messages from selected users</h3>
                    <?php if (!$telegramMessages): ?>
                        <div class="empty-state">No messages. Select users with “Show messages”.</div>
                    <?php endif; ?>
                    <?php foreach ($telegramMessages as $message): ?>
                        <div class="message-row">
                            <div class="message-meta"><strong><?= h(telegram_user_label($message)) ?></strong><span><?= h($message['sent_at']) ?><?= $message['chat_title'] ? ' · ' . h($message['chat_title']) : '' ?></span></div>
                            <div class="message-text"><?= $message['text'] !== '' && $message['text'] !== null ? nl2br(h($message['text'])) : '<em>[' . h($message['kind']) . ']</em>' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="services">
            <div class="section-heading">
                <div><h2>Services</h2><p>Fast access to your media, automation and lab apps.</p></div>
            </div>
            <?php foreach ($servicesByCategory as $category => $items): ?>
                <h3 class="service-category"><?= h($category) ?></h3>
                <div class="service-grid">
                    <?php foreach ($items as $service): ?>
                        <a class="service-card" href="<?= h($service['url']) ?>" target="_blank" rel="noreferrer">
                            <span class="service-icon"><?= h($service['icon'] ?: '•') ?></span>
                            <div><strong><?= h($service['name']) ?></strong><small><?= h(parse_url($service['url'], PHP_URL_HOST) ?: $service['url']) ?></small></div>
                            <span class="arrow">↗</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section id="server">
            <div class="section-heading">
                <div><h2>Server</h2><p>Live host metrics will be added in the next module.</p></div>
            </div>
            <div class="panel server-placeholder">
                <div><span>Status</span><strong class="status-good">Online</strong></div>
                <div><span>Metrics</span><strong>Coming next</strong></div>
                <div><span>Docker</span><strong>Integration planned</strong></div>
                <div><span>Storage</span><strong>SMART module planned</strong></div>
            </div>
        </section>

        <footer>
            <span>Home Lab Dashboard v<?= h(app_version()) ?></span>
            <span>Designed &amp; Developed by <a href="https://www.kasunindika.com" target="_blank" rel="noreferrer">Kasun Indika</a></span>
        </footer>
    </main>
</div>

<dialog id="billDialog">
    <form method="post" class="dialog-card">
        <input type="hidden" name="action" value="add_bill">
        <div class="dialog-header"><div><h2>Add bill</h2><p>Record a household bill and optional usage.</p></div><button type="button" class="icon-button" data-dialog-close>×</button></div>
        <div class="form-grid">
            <label>Provider<input name="provider" placeholder="CEB / NWSDB / SLT" required></label>
            <label>Account no.<input name="account_no" placeholder="Optional"></label>
            <label>Category<select name="category"><option>Electricity</option><option>Water</option><option>Internet</option><option>Mobile</option><option>Subscription</option><option>Other</option></select></label>
            <label>Billing month<input type="month" name="billing_month" required></label>
            <label>Amount (LKR)<input type="number" step="0.01" min="0" name="amount" required></label>
            <label>Due date<input type="date" name="due_date"></label>
            <label>Usage<input type="number" step="0.01" min="0" name="usage_value" placeholder="e.g. 250"></label>
            <label>Usage unit<input name="usage_unit" placeholder="kWh / m³ / GB"></label>
            <label class="full">Notes<textarea name="notes" rows="3" placeholder="Optional note"></textarea></label>
        </div>
        <div class="dialog-actions"><button type="button" data-dialog-close>Cancel</button><button class="primary-button" type="submit">Save bill</button></div>
    </form>
</dialog>

<script src="/assets/app.js?v=<?= h(app_version()) ?>"></script>
</body>
</html>
