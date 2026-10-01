<?php
declare(strict_types=1);

const APP_VERSION_FALLBACK = '0.1.0';

function app_version(): string
{
    $path = '/var/www/VERSION';
    return is_readable($path) ? trim((string) file_get_contents($path)) : APP_VERSION_FALLBACK;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = '/var/www/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dir . '/homelab.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bills (
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
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            url TEXT NOT NULL,
            icon TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO services (name, category, url, icon, sort_order) VALUES (?, ?, ?, ?, ?)');
        $defaults = [
            ['Jellyfin', 'Media', 'http://192.168.100.10:8096', '▶', 10],
            ['Sonarr', 'Media', 'http://192.168.100.10:8989', 'TV', 20],
            ['Radarr', 'Media', 'http://192.168.100.10:7878', 'MV', 30],
            ['Prowlarr', 'Media', 'http://192.168.100.10:9696', 'PR', 40],
            ['SABnzbd', 'Media', 'http://192.168.100.10:8080', 'DL', 50],
            ['n8n', 'Automation', 'http://192.168.100.10:5678', '⚡', 60],
        ];
        foreach ($defaults as $service) {
            $stmt->execute($service);
        }
    }

    return $pdo;
}

function money(float $value): string
{
    return 'Rs ' . number_format($value, 2);
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
