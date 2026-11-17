<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../db.php';

$cacheKey = 'radio_tn_v1';
$st = $pdo->prepare("SELECT payload, fetched_at FROM wx_cache WHERE key = ?");
$st->execute([$cacheKey]);
$cached = $st->fetch();
if ($cached && (time() - (int)$cached['fetched_at'] < 21600)) {
    echo $cached['payload'];
    exit;
}

$url = 'https://de1.api.radio-browser.info/json/stations/bycountrycodeexact/TN?limit=30&order=clickcount&reverse=true&hidebroken=true';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 7,
    CURLOPT_USERAGENT => 'AZSatConnect/1.0'
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$stations = [];
if ($code === 200 && $raw) {
    $list = json_decode($raw, true) ?: [];
    $seen = [];
    foreach ($list as $r) {
        if (!empty($r['hls'])) continue;
        $stream = trim((string)($r['url_resolved'] ?: $r['url'] ?? ''));
        $name = trim((string)($r['name'] ?? ''));
        if ($stream === '' || $name === '') continue;
        $key = strtolower($name);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $stations[] = [
            'name' => $name,
            'url' => $stream,
            'icon' => trim((string)($r['favicon'] ?? ''))
        ];
        if (count($stations) >= 20) break;
    }
}

if (empty($stations)) {
    $stations = [
        ['name' => 'Mosaique FM', 'url' => 'https://radio.mosaiquefm.net/mosalive', 'icon' => ''],
        ['name' => 'Jawhara FM', 'url' => 'https://streaming2.toutech.net:8000/jawharafm', 'icon' => ''],
        ['name' => 'IFM 100.6', 'url' => 'https://live.ifm.tn/radio/8000/ifmlive', 'icon' => ''],
        ['name' => 'Radio Zitouna', 'url' => 'https://stream.radiozitouna.tn/zitouna', 'icon' => '']
    ];
}

$out = json_encode(['ok' => true, 'stations' => $stations], JSON_UNESCAPED_UNICODE);
$pdo->prepare("INSERT OR REPLACE INTO wx_cache (key, payload, fetched_at) VALUES (?, ?, ?)")->execute([$cacheKey, $out, time()]);
echo $out;
