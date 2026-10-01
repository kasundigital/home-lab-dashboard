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