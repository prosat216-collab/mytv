<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../db.php';

date_default_timezone_set('Africa/Tunis');

// دعم اختيار التاريخ (أمس، اليوم، غداً)
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$reqDate = preg_replace('/\D/', '', (string)($input['date'] ?? $_GET['date'] ?? ''));
if (strlen($reqDate) !== 8) {
    $reqDate = date('Ymd');
}

$cacheKey = 'flash_matches_' . $reqDate;

// فحص الكاش (دقيقتين فقط للمباشر)
$st = $pdo->prepare("SELECT payload, fetched_at FROM wx_cache WHERE key = ?");
$st->execute([$cacheKey]);
$cached = $st->fetch();
if ($cached && (time() - (int)$cached['fetched_at'] < 120) && empty($_GET['force'])) {
    echo $cached['payload'];
    exit;
}

// قائمة البطولات المفضلة بترتيب الأهمية للجمهور التونسي والعربي
$favLeagues = [
    'tun.1' => ['order' => 1, 'ar' => '🇹🇳 البطولة التونسية', 'fr' => '🇹🇳 Ligue 1 Tunisie', 'flag' => '🇹🇳'],
    'caf.nations_qual' => ['order' => 2, 'ar' => '🌍 تصفيات كأس أمم أفريقيا', 'fr' => '🌍 Qualif. CAN', 'flag' => '🌍'],
    'caf.champions' => ['order' => 3, 'ar' => '🏆 دوري أبطال أفريقيا', 'fr' => '🏆 CAF Champions League', 'flag' => '🏆'],
    'caf.confed' => ['order' => 4, 'ar' => '🏆 كأس الكونفيدرالية', 'fr' => '🏆 Coupe de la Confédération', 'flag' => '🏆'],
    'uefa.nations' => ['order' => 5, 'ar' => '🇪🇺 دوري الأمم الأوروبية', 'fr' => '🇪🇺 Ligue des Nations UEFA', 'flag' => '🇪🇺'],
    'uefa.champions' => ['order' => 6, 'ar' => '⭐ دوري أبطال أوروبا', 'fr' => '⭐ Ligue des Champions', 'flag' => '⭐'],
    'eng.1' => ['order' => 7, 'ar' => '🏴󠁧󠁢󠁥󠁮󠁧󠁿 الدوري الإنجليزي الممتاز', 'fr' => '🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League', 'flag' => '🏴󠁧󠁢󠁥󠁮󠁧󠁿'],
    'esp.1' => ['order' => 8, 'ar' => '🇪🇸 الدوري الإسباني', 'fr' => '🇪🇸 LaLiga', 'flag' => '🇪🇸'],
    'ita.1' => ['order' => 9, 'ar' => '🇮🇹 الدوري الإيطالي', 'fr' => '🇮🇹 Serie A', 'flag' => '🇮🇹'],
    'fra.1' => ['order' => 10, 'ar' => '🇫🇷 الدوري الفرنسي', 'fr' => '🇫🇷 Ligue 1', 'flag' => '🇫🇷'],
    'ger.1' => ['order' => 11, 'ar' => '🇩🇪 الدوري الألماني', 'fr' => '🇩🇪 Bundesliga', 'flag' => '🇩🇪'],
    'ksa.1' => ['order' => 12, 'ar' => '🇸🇦 دوري روشن السعودي', 'fr' => '🇸🇦 Saudi Pro League', 'flag' => '🇸🇦'],
    'fifa.friendly' => ['order' => 13, 'ar' => '🌐 مباريات دولية ودية', 'fr' => '🌐 Matchs Amicaux', 'flag' => '🌐']
];

function fetch_api(string $url): ?array {
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

$categories = [];
$totalCount = 0;
$liveCount = 0;

$processEvent = function($ev, $leagueSlug = '', $leagueName = '') use (&$categories, $favLeagues, &$totalCount, &$liveCount) {
    $comp = $ev['competitions'][0] ?? null;
    if (!$comp || count($comp['competitors'] ?? []) < 2) return;

    $home = $comp['competitors'][0];
    $away = $comp['competitors'][1];
    $hName = $home['team']['shortDisplayName'] ?? ($home['team']['name'] ?? '');
    $aName = $away['team']['shortDisplayName'] ?? ($away['team']['name'] ?? '');
    if (!$hName || !$aName) return;

    $rawSlug = strtolower($ev['season']['slug'] ?? $leagueSlug);
    $rawName = $ev['league']['name'] ?? $leagueName;

    // تمييز تونس والأندية التونسية
    $isTunisia = false;
    $checkStr = mb_strtolower($hName . ' ' . $aName . ' ' . ($home['team']['name'] ?? '') . ' ' . ($away['team']['name'] ?? ''));
    if (strpos($checkStr, 'tunis') !== false || strpos($checkStr, 'تونس') !== false || strpos($checkStr, 'taraji') !== false || strpos($checkStr, 'esperance') !== false || strpos($checkStr, 'etoile') !== false || strpos($checkStr, 'club africain') !== false || strpos($checkStr, 'css') !== false) {
        $isTunisia = true;
    }

    $catKey = $rawSlug ?: md5($rawName);
    $isFav = false;
    $order = 99;
    $flag = '⚽';
    $titleAr = $rawName ?: 'دوري عام';
    $titleFr = $rawName ?: 'Autre Ligue';

    if ($isTunisia) {
        $catKey = 'tun_special';
        $order = 0;
        $isFav = true;
        $flag = '🇹🇳';
        $titleAr = '🇹🇳 تونس (مباريات اليوم)';
        $titleFr = '🇹🇳 Tunisie (Matchs du jour)';
    } elseif (isset($favLeagues[$rawSlug])) {
        $meta = $favLeagues[$rawSlug];
        $order = $meta['order'];
        $isFav = true;
        $flag = $meta['flag'];
        $titleAr = $meta['ar'];
        $titleFr = $meta['fr'];
    }

    if (!isset($categories[$catKey])) {
        $categories[$catKey] = [
            'id' => $catKey,
            'is_fav' => $isFav,
            'order' => $order,
            'flag' => $flag,
            'title_ar' => $titleAr,
            'title_fr' => $titleFr,
            'matches' => [],
            'live_in_league' => 0
        ];
    }

    $dt = new DateTime($ev['date'] ?? 'now', new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Africa/Tunis'));

    $state = $ev['status']['type']['state'] ?? 'pre'; // pre, in, post
    $detail = $ev['status']['type']['shortDetail'] ?? '';
    
    $isLive = ($state === 'in');
    if ($isLive) {
        $liveCount++;
        $categories[$catKey]['live_in_league']++;
    }
    $totalCount++;

    $categories[$catKey]['matches'][] = [
        'id' => $ev['id'] ?? uniqid(),
        'time' => $dt->format('H:i'),
        'state' => $state,
        'is_live' => $isLive,
        'detail' => $detail,
        'home_name' => $hName,
        'home_logo' => $home['team']['logo'] ?? '',
        'home_score' => (string)($home['score'] ?? ''),
        'away_name' => $aName,
        'away_logo' => $away['team']['logo'] ?? '',
        'away_score' => (string)($away['score'] ?? '')
    ];
};

// جلب شامل للأحداث
$sources = [
    "https://site.api.espn.com/apis/site/v2/sports/soccer/scoreboards?dates={$reqDate}",
    "https://site.api.espn.com/apis/site/v2/sports/soccer/all/scoreboard?dates={$reqDate}",
    "https://site.api.espn.com/apis/site/v2/sports/soccer/uefa.nations/scoreboard?dates={$reqDate}"
];

foreach ($sources as $u) {
    $res = fetch_api($u);
    if (!$res) continue;
    if (!empty($res['events'])) {
        foreach ($res['events'] as $ev) $processEvent($ev);
    }
    if (!empty($res['leagues'])) {
        foreach ($res['leagues'] as $lg) {
            foreach ($lg['events'] ?? [] as $ev) $processEvent($ev, $lg['slug'] ?? '', $lg['name'] ?? '');
        }
    }
}

// فرز البطولات: المفضلة أولاً
$sorted = array_values(array_filter($categories, fn($c) => !empty($c['matches'])));
usort($sorted, fn($a, $b) => $a['order'] <=> $b['order']);

foreach ($sorted as &$c) {
    usort($c['matches'], fn($a, $b) => strcmp($a['time'], $b['time']));
}

$output = [
    'ok' => true,
    'date_ymd' => $reqDate,
    'total_count' => $totalCount,
    'live_count' => $liveCount,
    'categories' => $sorted
];

$payload = json_encode($output, JSON_UNESCAPED_UNICODE);
$pdo->prepare("INSERT OR REPLACE INTO wx_cache (key, payload, fetched_at) VALUES (?, ?, ?)")->execute([$cacheKey, $payload, time()]);
echo $payload;
