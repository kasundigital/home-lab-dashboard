<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($action === 'watch' || $action === 'unwatch') telegram_set_watched($userId, $action === 'watch');
    if ($action === 'mark_all_seen') $pdo->exec('UPDATE telegram_users SET is_new = 0');
    $query = http_build_query(array_filter(['filter' => $_POST['filter'] ?? '', 'q' => $_POST['q'] ?? '', 'user' => $_POST['open_user'] ?? '']));
    header('Location: /telegram.php' . ($query ? "?$query" : ''));
    exit;
}

$filter = in_array($_GET['filter'] ?? '', ['watched', 'new'], true) ? $_GET['filter'] : 'all';
$search = trim((string)($_GET['q'] ?? ''));
$openUser = (int)($_GET['user'] ?? 0);

$where = [];
$params = [];
if ($filter === 'watched') $where[] = 'watched = 1';
if ($filter === 'new') $where[] = 'is_new = 1';
if ($search !== '') {
    $where[] = '(username LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR CAST(user_id AS TEXT) LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$stmt = $pdo->prepare('SELECT * FROM telegram_users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY last_seen DESC LIMIT 300');
$stmt->execute($params);
$users = $stmt->fetchAll();

$counts = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(watched), 0) AS watched, COALESCE(SUM(is_new), 0) AS new FROM telegram_users')->fetch();

$history = [];
$historyUser = null;
if ($openUser) {
    $historyUser = telegram_find_user((string)$openUser);
    $stmt = $pdo->prepare('SELECT * FROM telegram_messages WHERE user_id = ? ORDER BY sent_at DESC, id DESC LIMIT 100');
    $stmt->execute([$openUser]);
    $history = $stmt->fetchAll();
}

$adminIds = telegram_admin_chat_ids();
$appName = getenv('APP_NAME') ?: 'Home Lab Dashboard';
$hidden = fn() => '<input type="hidden" name="filter" value="' . h($filter) . '"><input type="hidden" name="q" value="' . h($search) . '"><input type="hidden" name="open_user" value="' . ($openUser ?: '') . '">';
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Telegram · <?= h($appName) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= h(app_version()) ?>">
</head>
<body>
<div class="app-shell">
<aside class="sidebar">
<div class="brand"><div class="brand-mark">HL</div><div><strong>Home Lab</strong><span>Control Center</span></div></div>
<nav>
<a href="/#overview">Overview</a>
<a href="/#bills">Bills</a>
<a class="active" href="/telegram.php">Telegram</a>
<a href="/#services">Services</a>
<a href="/#server">Server</a>
<a href="/settings.php">Settings</a>
</nav>
<div class="sidebar-footer">v<?= h(app_version()) ?></div>
</aside>

<main>
<header class="topbar">
<div><h1>Telegram users</h1><p>Choose whose messages appear on the dashboard.</p></div>
<button id="themeToggle" class="icon-button" type="button">☾</button>
</header>

<?php if (setting('telegram_mode', 'off') === 'off'): ?>
<div class="notice error-notice">Telegram is off. Set the bot token and mode in <a href="/settings.php#notifications">Settings</a>.</div>
<?php endif; ?>

<section>
<div class="section-heading">
<div class="filter-tabs">
<a class="<?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">All <?= (int)$counts['total'] ?></a>
<a class="<?= $filter === 'new' ? 'active' : '' ?>" href="?filter=new">New <?= (int)$counts['new'] ?></a>
<a class="<?= $filter === 'watched' ? 'active' : '' ?>" href="?filter=watched">Shown on dashboard <?= (int)$counts['watched'] ?></a>
</div>
<form class="search-form" method="get"><input type="hidden" name="filter" value="<?= h($filter) ?>"><input name="q" value="<?= h($search) ?>" placeholder="Search name, @username or ID"></form>
</div>

<div class="panel table-panel"><div class="table-wrap">
<?php if (!$users): ?>
<div class="empty-state">No users yet. They appear when someone messages your bot.</div>
<?php else: ?>
<table>
<thead><tr><th>User</th><th>User / chat ID</th><th>First seen</th><th>Last message</th><th>Msgs</th><th></th></tr></thead>
<tbody>
<?php foreach ($users as $user): ?>
<tr>
<td><strong><?= h(telegram_user_label($user)) ?></strong>
<?php if ($user['is_new']): ?><span class="badge due-soon">New</span><?php endif; ?>
<?php if ($user['watched']): ?><span class="badge paid">Shown</span><?php endif; ?>
<?php if (in_array((string)$user['user_id'], $adminIds, true)): ?><span class="badge info">Admin</span><?php endif; ?></td>
<td><code><?= (int)$user['user_id'] ?></code></td>
<td><?= h($user['first_seen']) ?></td>
<td><?= h(mb_strimwidth((string)$user['last_message'], 0, 50, '…')) ?><small><?= h($user['last_seen']) ?></small></td>
<td><?= (int)$user['message_count'] ?></td>
<td class="actions">
<form method="post"><?= $hidden() ?><input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
<?php if ($user['watched']): ?><button type="submit" name="action" value="unwatch">Hide messages</button>
<?php else: ?><button class="good" type="submit" name="action" value="watch">Show messages</button><?php endif; ?>
</form>
<a class="button-link" href="?<?= h(http_build_query(['filter' => $filter, 'q' => $search, 'user' => $user['user_id']])) ?>#history">History</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div></div>
<?php if ((int)$counts['new']): ?><form method="post" class="settings-actions"><?= $hidden() ?><button type="submit" name="action" value="mark_all_seen">Mark all as seen</button></form><?php endif; ?>
</section>

<?php if ($historyUser): ?>
<section id="history">
<div class="section-heading"><div><h2><?= h(telegram_user_label($historyUser)) ?></h2><p>Last 100 messages · ID <?= (int)$historyUser['user_id'] ?></p></div></div>
<div class="panel list-panel">
<?php if (!$history): ?><div class="empty-state">No messages stored.</div><?php endif; ?>
<?php foreach ($history as $message): ?>
<div class="message-row">
<div class="message-meta"><strong><?= h($message['kind']) ?></strong><span><?= h($message['sent_at']) ?><?= $message['chat_title'] ? ' · ' . h($message['chat_title']) : '' ?></span></div>
<div class="message-text"><?= $message['text'] !== '' && $message['text'] !== null ? nl2br(h($message['text'])) : '<em>[' . h($message['kind']) . ']</em>' ?></div>
</div>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<footer><span>Home Lab Dashboard v<?= h(app_version()) ?></span><span>Designed &amp; Developed by <a href="https://www.kasunindika.com" target="_blank" rel="noreferrer">Kasun Indika</a></span></footer>
</main>
</div>
<script src="/assets/app.js?v=<?= h(app_version()) ?>"></script>
</body>
</html>
