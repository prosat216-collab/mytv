<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../db.php';

date_default_timezone_set('Africa/Tunis');
$todayYmd = date('Ymd');
$cacheKey = 'matches_all_' . $todayYmd;

// كاش لمدة 3 دقائق فقط لتحديث النتائج الحية
$st = $pdo->prepare("SELECT payload, fetched_at FROM wx_cache WHERE key = ?");
$st->execute([$cacheKey]);
$cached = $st->fetch();
if ($cached && (time() - (int)$cached['fetched_at'] < 180) && empty($_GET['force'])) {
    echo $cached['payload'];
    exit;
}

$leagueMap = [
    'tun' => [1, '🇹🇳 تونس / شمال أفريقيا', '🇹🇳 Tunisie / UNAF', '🇹🇳 Tunisia / UNAF'],
    'caf' => [2, '🏆 بطولات أفريقيا (CAF)', '🏆 CAF / Afrique', '🏆 CAF Africa'],
    'uefa.nations' => [3, '🇪🇺 دوري الأمم الأوروبية', '🇪🇺 Ligue des Nations', '🇪🇺 UEFA Nations League'],
    'uefa.champions' => [4, '⭐ دوري أبطال أوروبا', '⭐ Ligue des Champions', '⭐ UEFA Champions League'],
    'fifa' => [5, '🌍 مباريات وتصفيات دولية', '🌍 Matchs Internationaux', '🌍 International Matches'],
    'eng.1' => [6, '🏴󠁧󠁢󠁥󠁮󠁧󠁿 الدوري الإنجليزي', '🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League', '🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League'],
    'esp.1' => [7, '🇪🇸 الدوري الإسباني', '🇪🇸 LaLiga', '🇪🇸 LaLiga'],
    'ita.1' => [8, '🇮🇹 الدوري الإيطالي', '🇮🇹 Serie A', '🇮🇹 Serie A'],
    'fra.1' => [9, '🇫🇷 الدوري الفرنسي', '🇫🇷 Ligue 1', '🇫🇷 Ligue 1'],
    'ger.1' => [10, '🇩🇪 الدوري الألماني', '🇩🇪 Bundesliga', '🇩🇪 Bundesliga'],
    'ksa.1' => [11, '🇸🇦 دوري روشن السعودي', '🇸🇦 Saudi League', '🇸🇦 Saudi League'],
];

function fetch_url(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $raw) ? json_decode($raw, true) : null;
}

$groups = [];
$addEvent = function($ev, $defSlug = '', $defName = '') use (&$groups, $leagueMap) {
    $comp = $ev['competitions'][0] ?? null;
    if (!$comp || count($comp['competitors'] ?? []) < 2) return;
    
    $home = $comp['competitors'][0];
    $away = $comp['competitors'][1];
    
    $hName = $home['team']['shortDisplayName'] ?? ($home['team']['name'] ?? '');
    $aName = $away['team']['shortDisplayName'] ?? ($away['team']['name'] ?? '');
    if (!$hName || !$aName) return;

    $leagueSlug = strtolower($ev['season']['slug'] ?? $defSlug);
    $rawLgName = $ev['league']['name'] ?? $defName;
    
    // تصنيف خاص لتونس وشمال أفريقيا
    $hCheck = mb_strtolower($hName . ' ' . ($home['team']['name'] ?? ''));
    $aCheck = mb_strtolower($aName . ' ' . ($away['team']['name'] ?? ''));
    if (strpos($hCheck, 'tunis') !== false || strpos($aCheck, 'tunis') !== false || strpos($hCheck, 'تونس') !== false || strpos($aCheck, 'تونس') !== false) {
        $groupKey = 'tun';
    } elseif (strpos($leagueSlug, 'uefa.nations') !== false) {
        $groupKey = 'uefa.nations';
    } elseif (strpos($leagueSlug, 'caf') !== false || strpos(strtolower($rawLgName), 'caf') !== false) {
        $groupKey = 'caf';
    } elseif (strpos($leagueSlug, 'fifa') !== false || strpos($leagueSlug, 'friendly') !== false) {
        $groupKey = 'fifa';
    } elseif (isset($leagueMap[$leagueSlug])) {
        $groupKey = $leagueSlug;
    } else {
        $groupKey = 'other_' . substr(md5($rawLgName ?: $leagueSlug), 0, 6);
        $leagueMap[$groupKey] = [30, '⚽ ' . ($rawLgName ?: 'مباريات أخرى'), '⚽ ' . ($rawLgName ?: 'Autres Matchs'), '⚽ ' . ($rawLgName ?: 'Other Matches')];
    }

    $meta = $leagueMap[$groupKey] ?? [20, '⚽ ' . $rawLgName, '⚽ ' . $rawLgName, '⚽ ' . $rawLgName];
    if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = ['rank' => $meta[0], 'ar' => $meta[1], 'fr' => $meta[2], 'en' => $meta[3], 'matches' => []];
    }

    $dt = new DateTime($ev['date'] ?? 'now', new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Africa/Tunis'));

    $state = $ev['status']['type']['state'] ?? 'pre';
    $detail = $ev['status']['type']['shortDetail'] ?? '';

    $groups[$groupKey]['matches'][] = [
        'time' => $dt->format('H:i'),
        'state' => $state,
        'detail' => $detail,
        'home' => $hName,
        'home_logo' => $home['team']['logo'] ?? '',
        'home_score' => (string)($home['score'] ?? ''),
        'away' => $aName,
        'away_logo' => $away['team']['logo'] ?? '',
        'away_score' => (string)($away['score'] ?? ''),
    ];
};

// 1. جلب شامل من كل سكوربوردات كرة القدم لليوم
$sources = [
    "https://site.api.espn.com/apis/site/v2/sports/soccer/all/scoreboard?dates={$todayYmd}",
    "https://site.api.espn.com/apis/site/v2/sports/soccer/scoreboards?dates={$todayYmd}",
    "https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.friendly/scoreboard?dates={$todayYmd}",
    "https://site.api.espn.com/apis/site/v2/sports/soccer/uefa.nations/scoreboard?dates={$todayYmd}"
];

foreach ($sources as $url) {
    $data = fetch_url($url);
    if (!$data) continue;
    if (!empty($data['events'])) {
        foreach ($data['events'] as $ev) $addEvent($ev);
    }
    if (!empty($data['leagues'])) {
        foreach ($data['leagues'] as $lg) {
            foreach ($lg['events'] ?? [] as $ev) $addEvent($ev, $lg['slug'] ?? '', $lg['name'] ?? '');
        }
    }
}

$outGroups = array_values(array_filter($groups, fn($g) => !empty($g['matches'])));
usort($outGroups, fn($a, $b) => $a['rank'] <=> $b['rank']);
foreach ($outGroups as &$g) {
    usort($g['matches'], fn($a, $b) => strcmp($a['time'], $b['time']));
}

$out = json_encode(['ok' => true, 'date' => date('d/m/Y'), 'groups' => $outGroups], JSON_UNESCAPED_UNICODE);
$pdo->prepare("INSERT OR REPLACE INTO wx_cache (key, payload, fetched_at) VALUES (?, ?, ?)")->execute([$cacheKey, $out, time()]);
echo $out;
