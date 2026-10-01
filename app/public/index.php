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
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($provider !== '' && $billingMonth !== '' && $amount >= 0) {
            $stmt = $pdo->prepare(
                'INSERT INTO bills (provider, category, billing_month, amount, due_date, usage_value, usage_unit, notes)
                 VALUES (:provider, :category, :billing_month, :amount, :due_date, :usage_value, :usage_unit, :notes)'
            );
            $stmt->execute([
                ':provider' => $provider,
                ':category' => $category,
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

    if ($action === 'mark_paid') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE bills SET status = "paid", paid_date = date("now"), updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: /');
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

$bills = $pdo->query('SELECT * FROM bills ORDER BY COALESCE(due_date, "9999-12-31") ASC, id DESC')->fetchAll();
$services = $pdo->query('SELECT * FROM services ORDER BY category, sort_order, name')->fetchAll();

$unpaidTotal = 0.0;
$paidTotal = 0.0;
$unpaidCount = 0;
foreach ($bills as $bill) {
    if ($bill['status'] === 'paid') {
        $paidTotal += (float) $bill['amount'];
    } else {
        $unpaidTotal += (float) $bill['amount'];
        $unpaidCount++;
    }
}

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
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">HL</div>
            <div><strong>Home Lab</strong><span>Control Center</span></div>
        </div>
        <nav>
            <a class="active" href="#overview">Overview</a>
            <a href="#bills">Bills</a>
            <a href="#services">Services</a>
            <a href="#server">Server</a>\n            <a href="/settings.php">Settings</a>
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
                <article class="kpi-card">
                    <span>Outstanding bills</span>
                    <strong><?= money($unpaidTotal) ?></strong>
                    <small><?= $unpaidCount ?> unpaid</small>
                </article>
                <article class="kpi-card">
                    <span>Paid bills</span>
                    <strong><?= money($paidTotal) ?></strong>
                    <small>Recorded history</small>
                </article>
                <article class="kpi-card">
                    <span>Services</span>
                    <strong><?= count($services) ?></strong>
                    <small>Configured shortcuts</small>
                </article>
                <article class="kpi-card">
                    <span>Server</span>
                    <strong class="status-good">Online</strong>
                    <small>Local dashboard active</small>
                </article>
            </div>
        </section>

        <section id="bills">
            <div class="section-heading">
                <div><h2>Household Bills</h2><p>Electricity, water, telecom and other recurring bills.</p></div>
                <button class="primary-button" type="button" data-dialog-open="billDialog">+ Add bill</button>
            </div>

            <div class="panel table-panel">
                <?php if (!$bills): ?>
                    <div class="empty-state">No bills yet. Add your first electricity, water or telecom bill.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Provider</th><th>Month</th><th>Usage</th><th>Due</th><th>Amount</th><th>Status</th><th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bills as $bill): ?>
                                <tr>
                                    <td><strong><?= h($bill['provider']) ?></strong><small><?= h($bill['category']) ?></small></td>
                                    <td><?= h($bill['billing_month']) ?></td>
                                    <td><?= $bill['usage_value'] !== null ? h((string) $bill['usage_value']) . ' ' . h($bill['usage_unit']) : '—' ?></td>
                                    <td><?= h($bill['due_date'] ?: '—') ?></td>
                                    <td><?= money((float) $bill['amount']) ?></td>
                                    <td><span class="badge <?= $bill['status'] === 'paid' ? 'paid' : 'unpaid' ?>"><?= h(ucfirst($bill['status'])) ?></span></td>
                                    <td class="actions">
                                        <?php if ($bill['status'] !== 'paid'): ?>
                                            <form method="post"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= (int) $bill['id'] ?>"><button type="submit">Paid</button></form>
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
