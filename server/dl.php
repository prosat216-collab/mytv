<?php
require_once __DIR__ . '/db.php';
$set = get_settings($pdo);
$apk = trim((string)($set['mytv_apk'] ?? ''));
if ($apk === '') {
    http_response_code(404);
    echo 'No APK URL set in settings yet.';
    exit;
}
header('Location: ' . $apk, true, 302);
exit;
