<?php
declare(strict_types=1);

const APP_VERSION_FALLBACK = '0.3.0';

date_default_timezone_set(getenv('TZ') ?: 'UTC');

function data_dir(): string
{
    return rtrim(getenv('DATA_DIR') ?: '/var/www/data', '/');
}

function app_version(): string
{
    $path = '/var/www/VERSION';
    return is_readable($path) ? trim((string) file_get_contents($path)) : APP_VERSION_FALLBACK;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = data_dir();
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . $dir . '/homelab.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // The web server and the background worker share this file.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $pdo->exec('CREATE TABLE IF NOT EXISTS bills (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider TEXT NOT NULL,
        category TEXT NOT NULL,
        billing_month TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        due_date TEXT,
        status TEXT NOT NULL DEFAULT "unpaid",
        paid_date TEXT,
        usage_value REAL,
        usage_unit TEXT,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category TEXT NOT NULL,
        url TEXT NOT NULL,
        icon TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS integrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        category TEXT NOT NULL,
        base_url TEXT,
        api_key TEXT,
        username TEXT,
        password TEXT,
        enabled INTEGER NOT NULL DEFAULT 1,
        verify_ssl INTEGER NOT NULL DEFAULT 1,
        notes TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        username TEXT,
        first_name TEXT,
        last_name TEXT,
        language_code TEXT,
        watched INTEGER NOT NULL DEFAULT 0,
        is_new INTEGER NOT NULL DEFAULT 1,
        message_count INTEGER NOT NULL DEFAULT 0,
        last_message TEXT,
        first_seen TEXT NOT NULL,
        last_seen TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        update_id INTEGER UNIQUE,
        user_id INTEGER NOT NULL,
        chat_id INTEGER NOT NULL,
        chat_title TEXT,
        message_id INTEGER,
        kind TEXT NOT NULL DEFAULT "text",
        text TEXT,
        sent_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_telegram_messages_user ON telegram_messages (user_id, sent_at)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS notification_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event TEXT NOT NULL,
        channel TEXT NOT NULL,
        ok INTEGER NOT NULL,
        message TEXT,
        error TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    migrate_add_columns($pdo, 'bills', [
        'external_id' => 'TEXT',
        'account_no' => 'TEXT',
        'source' => 'TEXT NOT NULL DEFAULT "manual"',
    ]);
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_bills_external_id ON bills (external_id) WHERE external_id IS NOT NULL');

    if ((int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO services (name, category, url, icon, sort_order) VALUES (?, ?, ?, ?, ?)');
        foreach ([
            ['Jellyfin','Media','http://192.168.100.10:8096','▶',10],
            ['Sonarr','Media','http://192.168.100.10:8989','TV',20],
            ['Radarr','Media','http://192.168.100.10:7878','MV',30],
            ['Prowlarr','Media','http://192.168.100.10:9696','PR',40],
            ['SABnzbd','Media','http://192.168.100.10:8080','DL',50],
            ['n8n','Automation','http://192.168.100.10:5678','⚡',60],
        ] as $service) $stmt->execute($service);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM integrations')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO integrations (slug,name,category,base_url,enabled,verify_ssl) VALUES (?,?,?,?,?,?)');
        foreach ([
            ['jellyfin','Jellyfin','Media','http://192.168.100.10:8096',1,1],
            ['sonarr','Sonarr','Media','http://192.168.100.10:8989',1,1],
            ['radarr','Radarr','Media','http://192.168.100.10:7878',1,1],
            ['prowlarr','Prowlarr','Media','http://192.168.100.10:9696',1,1],
            ['bazarr','Bazarr','Media','http://192.168.100.10:6767',1,1],
            ['sabnzbd','SABnzbd','Media','http://192.168.100.10:8080',1,1],
            ['jellyseerr','Jellyseerr','Media','http://192.168.100.10:5055',1,1],
            ['n8n','n8n','Automation','http://192.168.100.10:5678',0,1],
            ['adguard','AdGuard Home','Network','http://192.168.100.2',0,1],
            ['stackpulse','StackPulse','Monitoring','',0,1],
            ['homeassistant','Home Assistant','External','',0,1],
        ] as $i) $stmt->execute($i);
    }

    if (setting('api_token') === null) set_setting('api_token', bin2hex(random_bytes(24)));

    return $pdo;
}

function migrate_add_columns(PDO $pdo, string $table, array $columns): void
{
    $existing = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $name $definition");
    }
}

function setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string)$value;
}

function set_setting(string $key, ?string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([$key, $value]);
}

function setting_bool(string $key, bool $default = false): bool
{
    $value = setting($key);
    return $value === null ? $default : $value === '1';
}

function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }

function money(float $value): string { return 'Rs ' . number_format($value, 2); }
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function integration_secret_display(?string $value): string {
    if (!$value) return 'Not configured';
    $length = strlen($value);
    if ($length <= 8) return str_repeat('•', max(8, $length));
    return substr($value,0,3) . str_repeat('•', min(18,$length-6)) . substr($value,-3);
}

require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/bills.php';
require_once __DIR__ . '/telegram.php';
