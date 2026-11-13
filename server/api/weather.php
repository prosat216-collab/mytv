<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../db.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$lat = isset($input['lat']) ? round((float)$input['lat'], 2) : 36.80;
$lon = isset($input['lon']) ? round((float)$input['lon'], 2) : 10.18;

$key = $lat . ':' . $lon;
$st = $pdo->prepare("SELECT payload, fetched_at FROM wx_cache WHERE key = ?");
$st->execute([$key]);
$cached = $st->fetch();

if ($cached && (time() - (int)$cached['fetched_at'] < 1800)) {
    echo $cached['payload'];
    exit;
}

$url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current=temperature_2m,weather_code,is_day&daily=temperature_2m_max,temperature_2m_min&timezone=auto&forecast_days=1";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_USERAGENT => 'MyTV/1.0'
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = $raw ? json_decode($raw, true) : null;
if ($code !== 200 || !$data || !isset($data['current'])) {
    echo json_encode(['ok' => false]);
    exit;
}

$wmo = (int)($data['current']['weather_code'] ?? 0);
$isDay = ((int)($data['current']['is_day'] ?? 1)) === 1;
$sfx = $isDay ? '_day' : '_night';

$sym = 'clearsky' . $sfx;
if ($wmo === 1) $sym = 'fair' . $sfx;
elseif ($wmo === 2) $sym = 'partlycloudy' . $sfx;
elseif ($wmo === 3) $sym = 'cloudy';
elseif ($wmo === 45 || $wmo === 48) $sym = 'fog';
elseif (in_array($wmo, [51, 53, 55, 56, 57, 61, 80])) $sym = 'lightrain';
elseif ($wmo === 63 || $wmo === 81) $sym = 'rain';
elseif ($wmo === 65 || $wmo === 82) $sym = 'heavyrain';
elseif (in_array($wmo, [66, 67, 71, 73, 75, 77, 85, 86])) $sym = 'snow';
elseif ($wmo >= 95) $sym = 'thunder';

$out = json_encode([
    'ok' => true,
    'temp' => (int)round((float)$data['current']['temperature_2m']),
    'max' => (int)round((float)($data['daily']['temperature_2m_max'][0] ?? $data['current']['temperature_2m'])),
    'min' => (int)round((float)($data['daily']['temperature_2m_min'][0] ?? $data['current']['temperature_2m'])),
    'symbol' => $sym,
    'credit' => 'Open-Meteo'
]);

$pdo->prepare("INSERT OR REPLACE INTO wx_cache (key, payload, fetched_at) VALUES (?, ?, ?)")->execute([$key, $out, time()]);
echo $out;
