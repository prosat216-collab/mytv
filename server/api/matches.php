<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../db.php';

date_default_timezone_set('Africa/Tunis');
$todayYmd = date('Ymd');
$cacheKey = 'matches_v2_' . $todayYmd;

$st = $pdo->prepare("SELECT payload, fetched_at FROM wx_cache WHERE key = ?");
$st->execute([$cacheKey]);
$cached = $st->fetch();
if ($cached && (time() - (int)$cached['fetched_at'] < 300)) {
    echo $cached['payload'];
    exit;
}

$leagueMap = [
    'tun.1' => [1, '🇹🇳 البطولة التونسية', '🇹🇳 Ligue 1 Tunisie', '🇹🇳 Tunisian Ligue 1'],
    'caf.champions' => [2, '🏆 دوري أبطال أفريقيا', '🏆 CAF Champions League', '🏆 CAF Champions League'],
    'caf.confed' => [3, '🏆 كأس الكونفيدرالية الأفريقية', '🏆 CAF Confederation Cup', '🏆 CAF Confederation Cup'],
    'caf.nations' => [4, '🌍 كأس أمم أفريقيا', '🌍 CAN', '🌍 AFCON'],
    'uefa.champions' => [5, '⭐ دوري أبطال أوروبا', '⭐ Ligue des Champions', '⭐ UEFA Champions League'],
    'uefa.europa' => [6, '🏆 الدوري الأوروبي', '🏆 Europa League', '🏆 Europa League'],
    'eng.1' => [7, '🏴󠁧󠁢󠁥󠁮󠁧󠁿 الدوري الإنجليزي الممتاز', '🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League', '🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League'],
    'esp.1' => [8, '🇪🇸 الدوري الإسباني', '🇪🇸 LaLiga', '🇪🇸 LaLiga'],
    'ita.1' => [9, '🇮🇹 الدوري الإيطالي', '🇮🇹 Serie A', '🇮🇹 Serie A'],
    'fra.1' => [10, '🇫🇷 الدوري الفرنسي', '🇫🇷 Ligue 1', '🇫🇷 Ligue 1'],
    'ger.1' => [11, '🇩🇪 الدوري الألماني', '🇩🇪 Bundesliga', '🇩🇪 Bundesliga'],
    'ksa.1' => [12, '🇸🇦 دوري روشن السعودي', '🇸🇦 Saudi Pro League', '🇸🇦 Saudi Pro League'],
    'fifa.world' => [13, '🌍 تصفيات كأس العالم', '🌍 Qualif. Coupe du Monde', '🌍 World Cup Qualifiers'],
];

function fetch_espn(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 7, CURLOPT_USERAGENT => 'AZSatConnect/1.0']);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $raw) ? json_decode($raw, true) : null;
}

$groups = [];
$addEvents = function(array $events, string $slug, string $rawName) use (&$groups, $leagueMap) {
    if (empty($events)) return;
    $meta = $leagueMap[$slug] ?? [50, '⚽ ' . ($rawName ?: $slug), '⚽ ' . ($rawName ?: $slug), '⚽ ' . ($rawName ?: $slug)];
    $key = $slug ?: $rawName;
    if (!isset($groups[$key])) {
        $groups[$key] = ['rank' => $meta[0], 'ar' => $meta[1], 'fr' => $meta[2], 'en' => $meta[3], 'matches' => []];
    }
    foreach ($events as $ev) {
        $comp = $ev['competitions'][0] ?? null;
        if (!$comp || count($comp['competitors'] ?? []) < 2) continue;
        $home = $comp['competitors'][0];
        $away = $comp['competitors'][1];
        $dt = new DateTime($ev['date'] ?? 'now', new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Africa/Tunis'));
        $groups[$key]['matches'][] = [
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

$main = fetch_espn("https://site.api.espn.com/apis/site/v2/sports/soccer/scoreboards?dates={$todayYmd}");
if ($main && !empty($main['leagues'])) {
    foreach ($main['leagues'] as $lg) {
        $addEvents($lg['events'] ?? [], $lg['slug'] ?? '', $lg['name'] ?? '');
    }
}

// فحص البطولة التونسية مباشرة إن وجدت مباريات اليوم
if (empty($groups['tun.1'])) {
    $tun = fetch_espn("https://site.api.espn.com/apis/site/v2/sports/soccer/tun.1/scoreboard?dates={$todayYmd}");
    if ($tun && !empty($tun['events'])) {
        $addEvents($tun['events'], 'tun.1', 'Tunisian Ligue 1');
    }
}

$outGroups = array_values(array_filter($groups, fn($g) => !empty($g['matches'])));
usort($outGroups, fn($a, $b) => $a['rank'] <=> $b['rank']);
foreach ($outGroups as &$g) {
    usort($g['matches'], fn($a, $b) => strcmp($a['time'], $b['time']));
}
$outGroups = array_slice($outGroups, 0, 12);

$out = json_encode(['ok' => true, 'date' => date('d/m/Y'), 'groups' => $outGroups], JSON_UNESCAPED_UNICODE);
$pdo->prepare("INSERT OR REPLACE INTO wx_cache (key, payload, fetched_at) VALUES (?, ?, ?)")->execute([$cacheKey, $out, time()]);
echo $out;
