<?php
// server/db.php
$dataDir = is_dir('/home/azsatconnect/mytv_data') ? '/home/azsatconnect/mytv_data' : (dirname(__DIR__, 1) . '/mytv_data');
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

$dbPath = $dataDir . '/mytv.db';
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS resellers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    shop_name TEXT NOT NULL,
    shop_logo TEXT DEFAULT '',
    shop_whatsapp TEXT NOT NULL,
    welcome_msg TEXT DEFAULT '',
    max_devices INTEGER DEFAULT 100,
    created_at TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS devices (
    uid TEXT PRIMARY KEY, code TEXT UNIQUE, client_name TEXT DEFAULT '',
    paired INTEGER DEFAULT 0, active INTEGER DEFAULT 1, expires_at TEXT DEFAULT '',
    type TEXT DEFAULT 'tv', model TEXT DEFAULT '', app_ver TEXT DEFAULT '1.0',
    app_code INTEGER DEFAULT 1, last_seen TEXT DEFAULT '', reseller_id INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS apps (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, package TEXT NOT NULL,
    version_code INTEGER DEFAULT 1, apk TEXT DEFAULT '', icon TEXT DEFAULT '',
    target_uid TEXT DEFAULT '', sort_order INTEGER DEFAULT 0, is_global INTEGER DEFAULT 1,
    reseller_id INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS device_apps (
    uid TEXT NOT NULL, app_id INTEGER NOT NULL, PRIMARY KEY (uid, app_id)
);
CREATE TABLE IF NOT EXISTS store (
    id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, thumb TEXT DEFAULT '', created_at TEXT DEFAULT '',
    reseller_id INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, target_uid TEXT DEFAULT '', created_at TEXT DEFAULT '',
    reseller_id INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS message_seen (
    uid TEXT NOT NULL, message_id INTEGER NOT NULL, PRIMARY KEY (uid, message_id)
);
CREATE TABLE IF NOT EXISTS wx_cache (
    key TEXT PRIMARY KEY, payload TEXT NOT NULL, fetched_at INTEGER NOT NULL
);
");

// التأكد من وجود عمود reseller_id في الأجهزة والتطبيقات
$devCols = array_column($pdo->query("PRAGMA table_info(devices)")->fetchAll(), 'name');
if (!in_array('reseller_id', $devCols, true)) {
    $pdo->exec("ALTER TABLE devices ADD COLUMN reseller_id INTEGER DEFAULT 0");
}
$appCols = array_column($pdo->query("PRAGMA table_info(apps)")->fetchAll(), 'name');
if (!in_array('reseller_id', $appCols, true)) {
    $pdo->exec("ALTER TABLE apps ADD COLUMN reseller_id INTEGER DEFAULT 0");
}

$defaults = [
    'shop_name' => 'AZ SAT CONNECT',
    'shop_logo' => '',
    'shop_welcome' => 'مرحباً بكم عند AZ SAT CONNECT',
    'shop_whatsapp' => '21626117917',
    'mytv_ver_code' => '1',
    'mytv_ver_name' => '1.0',
    'mytv_apk' => '',
    'mytv_notes' => ''
];
$ins = $pdo->prepare("INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)");
foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

function get_settings(PDO $pdo): array {
    $rows = $pdo->query("SELECT k, v FROM settings")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['k']] = $r['v'];
    return $out;
}

function base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'azsatconnect.alwaysdata.net';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function normalize_wa(string $wa): string {
    $d = preg_replace('/\D+/', '', $wa);
    if (preg_match('/^\d{8}$/', $d)) $d = '216' . $d;
    return $d;
}
