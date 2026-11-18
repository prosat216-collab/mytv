<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
require_once __DIR__ . '/../db.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$uid = trim((string)($input['uid'] ?? ''));
$hw = substr(trim((string)($input['hw'] ?? '')), 0, 120);
$action = trim((string)($input['action'] ?? 'live_cats'));

if ($uid === '') { echo json_encode(['ok' => false, 'msg' => 'Missing UID']); exit; }

$st = $pdo->prepare("SELECT * FROM devices WHERE uid = ?");
$st->execute([$uid]);
$dev = $st->fetch();

$expired = ($dev && $dev['expires_at'] !== '' && $dev['expires_at'] < date('Y-m-d'));
$cloned = ($dev && $dev['hw_sig'] !== '' && $hw !== '' && $dev['hw_sig'] !== $hw);
if (!$dev || (int)$dev['paired'] !== 1 || (int)$dev['active'] !== 1 || $expired || $cloned || (int)($dev['xtream_on'] ?? 0) !== 1) {
    echo json_encode(['ok' => false, 'msg' => 'Xtream غير مفعل']); exit;
}

$base = rtrim(trim((string)$dev['xtream_url']), '/');
$u = trim((string)$dev['xtream_user']);
$p = trim((string)$dev['xtream_pass']);
if ($base === '' || $u === '' || $p === '') { echo json_encode(['ok' => false, 'msg' => 'بيانات غير مكتملة']); exit; }

if ($action === 'play') {
    $sid = (int)($input['stream_id'] ?? 0);
    $kind = ($input['kind'] ?? 'live') === 'vod' ? 'movie' : 'live';
    $ext = preg_replace('/[^a-z0-9]/i', '', (string)($input['ext'] ?? ($kind === 'movie' ? 'mp4' : 'ts')));
    echo json_encode(['ok' => true, 'url' => "{$base}/{$kind}/{$u}/{$p}/{$sid}.{$ext}"]);
    exit;
}

$apiMap = ['live_cats' => 'get_live_categories', 'live_streams' => 'get_live_streams', 'vod_cats' => 'get_vod_categories', 'vod_streams' => 'get_vod_streams'];
$xcAction = $apiMap[$action] ?? 'get_live_categories';
$catId = trim((string)($input['cat_id'] ?? ''));

$cacheKey = 'xc_' . md5($base . '|' . $u . '|' . $xcAction . '|' . $catId);
$cst = $pdo->prepare("SELECT payload, fetched_at FROM wx_cache WHERE key = ?");
$cst->execute([$cacheKey]);
$cached = $cst->fetch();
if ($cached && (time() - (int)$cached['fetched_at'] < 600)) { echo $cached['payload']; exit; }

$url = $base . '/player_api.php?username=' . urlencode($u) . '&password=' . urlencode($p) . '&action=' . $xcAction;
if ($catId !== '') $url .= '&category_id=' . urlencode($catId);

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'IPTVSmartersPlayer', CURLOPT_FOLLOWLOCATION => true]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = ($code === 200 && $raw) ? json_decode($raw, true) : null;
if (!is_array($data)) { echo json_encode(['ok' => false, 'msg' => 'تعذر الاتصال بسيرفر Xtream']); exit; }

$items = [];
if ($action === 'live_cats' || $action === 'vod_cats') {
    foreach ($data as $row) $items[] = ['id' => (string)($row['category_id'] ?? ''), 'name' => (string)($row['category_name'] ?? '')];
} elseif ($action === 'live_streams') {
    foreach (array_slice($data, 0, 200) as $row) $items[] = ['id' => (int)($row['stream_id'] ?? 0), 'name' => (string)($row['name'] ?? ''), 'icon' => (string)($row['stream_icon'] ?? '')];
} elseif ($action === 'vod_streams') {
    foreach (array_slice($data, 0, 150) as $row) $items[] = ['id' => (int)($row['stream_id'] ?? 0), 'name' => (string)($row['name'] ?? ''), 'icon' => (string)($row['stream_icon'] ?? ''), 'ext' => (string)($row['container_extension'] ?? 'mp4')];
}

$out = json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
$pdo->prepare("INSERT OR REPLACE INTO wx_cache (key, payload, fetched_at) VALUES (?, ?, ?)")->execute([$cacheKey, $out, time()]);
echo $out;
