<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
require_once __DIR__ . '/../db.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? 'config';
$uid = trim((string)($input['uid'] ?? ''));

if ($uid === '') { echo json_encode(['ok' => false, 'msg' => 'Missing UID']); exit; }
$now = date('Y-m-d H:i:s');

if ($action === 'seen') {
    $mid = (int)($input['message_id'] ?? 0);
    if ($mid > 0) $pdo->prepare("INSERT OR IGNORE INTO message_seen (uid, message_id) VALUES (?, ?)")->execute([$uid, $mid]);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'event') {
    $pdo->prepare("UPDATE devices SET last_seen = ? WHERE uid = ?")->execute([$now, $uid]);
    echo json_encode(['ok' => true]); exit;
}

$hw = substr(trim((string)($input['hw'] ?? '')), 0, 120);
$stmt = $pdo->prepare("SELECT * FROM devices WHERE uid = ?");
$stmt->execute([$uid]);
$dev = $stmt->fetch();

if (!$dev) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 5; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $chk = $pdo->prepare("SELECT 1 FROM devices WHERE code = ?"); $chk->execute([$code]);
    } while ($chk->fetch());

    $pdo->prepare("INSERT INTO devices (uid, code, paired, active, type, model, app_ver, app_code, last_seen, hw_sig) VALUES (?, ?, 0, 1, ?, ?, ?, ?, ?, ?)")
        ->execute([$uid, $code, substr((string)($input['type'] ?? 'tv'), 0, 20), substr((string)($input['model'] ?? 'Android TV'), 0, 100), substr((string)($input['ver'] ?? '1.8'), 0, 20), (int)($input['code'] ?? 18), $now, $hw]);

    echo json_encode(['ok' => true, 'paired' => false, 'code' => $code]);
    exit;
}

// تحديث البصمة عند أول تفعيل إذا كانت فارغة، أو فحص الحماية ضد السرقة والاستنساخ
$savedHw = trim((string)($dev['hw_sig'] ?? ''));
$cloned = ($savedHw !== '' && $hw !== '' && $savedHw !== $hw);
if ($savedHw === '' && $hw !== '') {
    $pdo->prepare("UPDATE devices SET hw_sig = ? WHERE uid = ?")->execute([$hw, $uid]);
}

$pdo->prepare("UPDATE devices SET type = ?, model = ?, app_ver = ?, app_code = ?, last_seen = ? WHERE uid = ?")
    ->execute([substr((string)($input['type'] ?? $dev['type']), 0, 20), substr((string)($input['model'] ?? $dev['model']), 0, 100), substr((string)($input['ver'] ?? $dev['app_ver']), 0, 20), (int)($input['code'] ?? $dev['app_code']), $now, $uid]);

if ((int)$dev['paired'] !== 1) {
    echo json_encode(['ok' => true, 'paired' => false, 'code' => $dev['code']]);
    exit;
}

$set = get_settings($pdo);
$shopName = $set['shop_name'] ?? 'CONNECT TV';
$shopLogo = $set['shop_logo'] ?? '';
$shopWelcome = $set['shop_welcome'] ?? '';
$shopWa = normalize_wa($set['shop_whatsapp'] ?? '21626117917');
$resellerId = (int)($dev['reseller_id'] ?? 0);

if ($resellerId > 0) {
    $stRes = $pdo->prepare("SELECT * FROM resellers WHERE id = ?"); $stRes->execute([$resellerId]);
    $resInfo = $stRes->fetch();
    if ($resInfo) {
        $shopName = $resInfo['shop_name'];
        if (!empty($resInfo['shop_logo'])) $shopLogo = $resInfo['shop_logo'];
        if (!empty($resInfo['welcome_msg'])) $shopWelcome = $resInfo['welcome_msg'];
        if (!empty($resInfo['shop_whatsapp'])) $shopWa = normalize_wa($resInfo['shop_whatsapp']);
    }
}

$expires = trim((string)$dev['expires_at']);
$daysLeft = 9999; $expired = false; $warn = false;
if ($expires !== '') {
    $today = new DateTimeImmutable('today');
    $expDt = DateTimeImmutable::createFromFormat('Y-m-d', $expires);
    if ($expDt) {
        $daysLeft = (int)$today->diff($expDt)->format('%r%a');
        $expired = ($daysLeft < 0);
        $warn = ($daysLeft >= 0 && $daysLeft <= 7);
    }
}

$active = ((int)$dev['active'] === 1) && !$expired && !$cloned;
$reason = $cloned ? 'cloned_device' : (((int)$dev['active'] !== 1) ? 'suspended' : ($expired ? 'client_expired' : ''));

$apps = []; $store = []; $msgs = [];
if ($active) {
    $stApps = $pdo->prepare("
        SELECT DISTINCT a.id, a.name, a.package, a.version_code, a.apk, a.icon, a.is_locked
        FROM apps a
        LEFT JOIN device_apps da ON da.app_id = a.id AND da.uid = ?
        WHERE (a.is_global = 1 AND (a.reseller_id = 0 OR a.reseller_id = ?))
           OR da.uid IS NOT NULL
           OR a.target_uid = ? OR a.target_uid = ?
        ORDER BY a.sort_order ASC, a.id ASC
    ");
    $stApps->execute([$uid, $resellerId, $uid, $dev['code']]);
    foreach ($stApps->fetchAll() as $a) {
        $apps[] = [
            'id' => (int)$a['id'], 'name' => $a['name'], 'package' => $a['package'],
            'version_code' => (int)$a['version_code'], 'apk' => $a['apk'], 'icon' => $a['icon'],
            'locked' => ((int)($a['is_locked'] ?? 0) === 1)
        ];
    }

    $stStore = $pdo->prepare("SELECT id, url, thumb FROM store WHERE reseller_id = 0 OR reseller_id = ? ORDER BY id DESC");
    $stStore->execute([$resellerId]);
    foreach ($stStore->fetchAll() as $p) {
        $store[] = ['id' => (int)$p['id'], 'url' => $p['url'], 'thumb' => $p['thumb'] !== '' ? $p['thumb'] : $p['url']];
    }

    $stMsgs = $pdo->prepare("
        SELECT m.id, m.body FROM messages m
        LEFT JOIN message_seen s ON s.message_id = m.id AND s.uid = ?
        WHERE s.message_id IS NULL AND (m.reseller_id = 0 OR m.reseller_id = ?) AND (m.target_uid = '' OR m.target_uid = ? OR m.target_uid = ?)
        ORDER BY m.id ASC
    ");
    $stMsgs->execute([$uid, $resellerId, $uid, $dev['code']]);
    foreach ($stMsgs->fetchAll() as $m) $msgs[] = ['id' => (int)$m['id'], 'body' => $m['body']];
}

echo json_encode([
    'ok' => true, 'paired' => true, 'active' => $active, 'reason' => $reason, 'code' => $dev['code'],
    'xtream_on' => ($active && (int)($dev['xtream_on'] ?? 0) === 1 && trim((string)($dev['xtream_url'] ?? '')) !== ''),
    'shop' => [
        'name' => $shopName, 'logo' => $shopLogo, 'welcome' => $shopWelcome,
        'whatsapp' => $shopWa, 'whatsapp_display' => $shopWa ? ('+' . $shopWa) : '',
        'whatsapp_link' => $shopWa ? ('https://wa.me/' . $shopWa) : ''
    ],
    'client' => ['expires' => $expires, 'days_left' => $daysLeft, 'warn' => $warn, 'expired' => $expired],
    'apps' => $apps, 'store' => $store, 'messages' => $msgs,
    'mytv' => [
        'version_code' => (int)($set['mytv_ver_code'] ?? 18),
        'version_name' => $set['mytv_ver_name'] ?? '1.8',
        'apk' => $set['mytv_apk'] ?? '', 'notes' => $set['mytv_notes'] ?? ''
    ]
], JSON_UNESCAPED_UNICODE);
