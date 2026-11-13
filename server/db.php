<?php
// server/db.php
$dataDir = dirname(__DIR__, 1) . '/mytv_data';
if (!is_dir($dataDir)) {
    $dataDir = '/home/azsatconnect/mytv_data';
}
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

$dbPath = $dataDir . '/mytv.db';
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("
CREATE TABLE IF NOT EXISTS settings (
    k TEXT PRIMARY KEY,
    v TEXT
);
CREATE TABLE IF NOT EXISTS devices (
    uid TEXT PRIMARY KEY,
    code TEXT UNIQUE,
    client_name TEXT DEFAULT '',
    paired INTEGER DEFAULT 0,
    active INTEGER DEFAULT 1,
    expires_at TEXT DEFAULT '',
    type TEXT DEFAULT 'tv',
    model TEXT DEFAULT '',
    app_ver TEXT DEFAULT '1.0',
    app_code INTEGER DEFAULT 1,
    last_seen TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS apps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    package TEXT NOT NULL,
    version_code INTEGER DEFAULT 1,
    apk TEXT DEFAULT '',
    icon TEXT DEFAULT '',
    target_uid TEXT DEFAULT '',
    sort_order INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS store (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT NOT NULL,
    thumb TEXT DEFAULT '',
    created_at TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    body TEXT NOT NULL,
    target_uid TEXT DEFAULT '',
    created_at TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS message_seen (
    uid TEXT NOT NULL,
    message_id INTEGER NOT NULL,
    PRIMARY KEY (uid, message_id)
);
CREATE TABLE IF NOT EXISTS wx_cache (
    key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    fetched_at INTEGER NOT NULL
);
");

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
foreach ($defaults as $k => $v) {
    $ins->execute([$k, $v]);
}

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
