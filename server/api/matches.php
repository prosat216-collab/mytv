<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../db.php';

date_default_timezone_set('Africa/Tunis');
$todayYmd = date('Ymd');
$cacheKey = 'matches_' . $todayYmd;

$st = $pdo->prepare("SELECT payload, fetched_at FROM wx_cache WHERE key = ?");
$st->execute([$cacheKey]);
$cached = $st->fetch();
if ($cached && (time() - (int)$cached['fetched_at'] < 300)) {
    echo $cached['payload'];
    exit;
}

$urls = [
    "https://site.api.espn.com/apis/site/v2/sports/soccer/all/scoreboard?dates={$todayYmd}",
    "https://site.api.espn.com/apis/site/v2/sports/soccer/scoreboards?dates={$todayYmd}"
];

$data = null;
foreach ($urls as $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'AZSatConnect/1.0'
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $raw) {
        $j = json_decode($raw, true);
        if (!empty($j['events']) || !empty($j['leagues'])) {
            $data = $j;
            break;
        }
    }
}

$matches = [];
$collect = function(array $events, string $leagueName) use (&$matches) {
    foreach ($events as $ev) {
        $comp = $ev['competitions'][0] ?? null;
        if (!$comp || count($comp['competitors'] ?? []) < 2) continue;
        $home = $comp['competitors'][0];
        $away = $comp['competitors'][1];
        $dt = new DateTime($ev['date'] ?? 'now', new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Africa/Tunis'));

        $matches[] = [
            'league' => $leagueName ?: ($ev['season']['slug'] ?? 'Football'),
            'time' => $dt->format('H:i'),
            'state' => $ev['status']['type']['state'] ?? 'pre',
            'detail' => $ev['status']['type']['shortDetail'] ?? '',
            'home' => $home['team']['shortDisplayName'] ?? ($home['team']['name'] ?? ''),
            'home_logo' => $home['team']['logo'] ?? '',
            'home_score' => (string)($home['score'] ?? ''),
            'away' => $away['team']['shortDisplayName'] ?? ($away['team']['name'] ?? ''),
            'away_logo' => $away['team']['logo'] ?? '',
            'away_score' => (string)($away['score'] ?? ''),
        ];
    }
};

if ($data) {
    if (!empty($data['events'])) {
        $collect($data['events'], $data['leagues'][0]['name'] ?? '');
    } elseif (!empty($data['leagues'])) {
        foreach ($data['leagues'] as $lg) {
            $collect($lg['events'] ?? [], $lg['name'] ?? '');
        }
    }
}

usort($matches, fn($a, $b) => strcmp($a['time'], $b['time']));
$matches = array_slice($matches, 0, 40);

$out = json_encode(['ok' => true, 'date' => date('d/m/Y'), 'matches' => $matches], JSON_UNESCAPED_UNICODE);
$pdo->prepare("INSERT OR REPLACE INTO wx_cache (key, payload, fetched_at) VALUES (?, ?, ?)")->execute([$cacheKey, $out, time()]);
echo $out;
