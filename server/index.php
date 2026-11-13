<?php
session_start();
require_once __DIR__ . '/db.php';

$dataDir = is_dir('/home/azsatconnect/mytv_data') ? '/home/azsatconnect/mytv_data' : (dirname(__DIR__) . '/mytv_data');
$adminFile = $dataDir . '/admin.json';
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$hasPass = file_exists($adminFile);
$loginErr = '';

if (isset($_POST['setup_pass']) && !$hasPass) {
    $p = trim((string)$_POST['new_pass']);
    if (strlen($p) < 4) {
        $loginErr = 'اختر كلمة مرور من 4 أحرف على الأقل.';
    } else {
        file_put_contents($adminFile, json_encode(['hash' => password_hash($p, PASSWORD_DEFAULT)]));
        $_SESSION['mytv_admin'] = true;
        header('Location: index.php');
        exit;
    }
}

if (isset($_POST['login_pass']) && $hasPass) {
    $adm = json_decode(file_get_contents($adminFile), true);
    if ($adm && password_verify(trim((string)$_POST['pass']), $adm['hash'] ?? '')) {
        $_SESSION['mytv_admin'] = true;
        header('Location: index.php');
        exit;
    } else {
        $loginErr = 'كلمة المرور غير صحيحة!';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['mytv_admin'])):
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AZ SAT CONNECT - دخول اللوحة</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0c11;color:#f6efe0;font-family:system-ui,sans-serif;padding:20px}
.box{width:100%;max-width:400px;background:#161b22;border:1px solid rgba(255,178,30,.4);border-radius:14px;padding:28px;text-align:center}
h1{color:#ffb21e;margin:0 0 10px;font-size:1.7rem}
p{color:#a59f92;font-size:.95rem;margin-bottom:20px}
input{width:100%;padding:14px;border-radius:8px;border:1px solid #30363d;background:#0d1117;color:#fff;font-size:16px;box-sizing:border-box;margin-bottom:14px;text-align:center}
button{width:100%;padding:14px;border:0;border-radius:8px;background:linear-gradient(135deg,#ffb21e,#ff5a36);color:#000;font-weight:800;font-size:16px;cursor:pointer}
.err{background:rgba(248,81,73,.15);color:#f85149;padding:10px;border-radius:8px;margin-bottom:14px;font-weight:bold}
</style>
</head>
<body>
<div class="box">
    <h1>AZ SAT CONNECT 📺</h1>
    <?php if ($loginErr): ?><div class="err"><?= htmlspecialchars($loginErr) ?></div><?php endif; ?>
    <?php if (!$hasPass): ?>
        <p>أهلاً بك! أنشئ كلمة مرور لحماية لوحة تحكم محلك (مرة واحدة فقط):</p>
        <form method="post">
            <input type="password" name="new_pass" placeholder="اكتب كلمة المرور الجديدة" required>
            <button type="submit" name="setup_pass">حفظ ودخول اللوحة</button>
        </form>
    <?php else: ?>
        <p>أدخل كلمة مرور لوحة التحكم:</p>
        <form method="post">
            <input type="password" name="pass" placeholder="كلمة المرور" required>
            <button type="submit" name="login_pass">دخول</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
<?php exit; endif;

function save_upload(string $field, array $allowed, string $uploadDir): string {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    $name = uniqid($field . '_', true) . '.' . $ext;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . '/' . $name)) {
        return base_url() . '/uploads/' . $name;
    }
    return '';
}

$msg = '';
$err = '';
$tab = $_GET['tab'] ?? 'devices';

if (isset($_POST['pair_device'])) {
    $code = strtoupper(trim((string)$_POST['code']));
    $cname = trim((string)($_POST['client_name'] ?? ''));
    $months = (int)($_POST['months'] ?? 12);
    $st = $pdo->prepare("SELECT * FROM devices WHERE code = ?");
    $st->execute([$code]);
    $dev = $st->fetch();
    if (!$dev) {
        $err = "الكود ($code) غير موجود بعد! افتح التطبيق على التلفاز أولاً حتى يظهر الكود.";
    } else {
        $exp = $months > 0 ? date('Y-m-d', strtotime("+{$months} months")) : '';
        $pdo->prepare("UPDATE devices SET paired = 1, active = 1, client_name = ?, expires_at = ? WHERE uid = ?")
            ->execute([$cname !== '' ? $cname : $dev['client_name'], $exp, $dev['uid']]);
        $msg = "تم تفعيل الجهاز ($code) بنجاح" . ($exp ? " حتى $exp" : " (بلا حدود)");
    }
    $tab = 'devices';
}

if (isset($_POST['renew_uid'])) {
    $uid = (string)$_POST['renew_uid'];
    $add = (int)$_POST['add_months'];
    $st = $pdo->prepare("SELECT expires_at FROM devices WHERE uid = ?");
    $st->execute([$uid]);
    $cur = (string)$st->fetchColumn();
    $base = ($cur !== '' && strtotime($cur) > time()) ? strtotime($cur) : time();
    $newExp = date('Y-m-d', strtotime("+{$add} months", $base));
    $pdo->prepare("UPDATE devices SET expires_at = ?, active = 1, paired = 1 WHERE uid = ?")->execute([$newExp, $uid]);
    $msg = "تم تمديد الاشتراك حتى $newExp";
    $tab = 'devices';
}

if (isset($_GET['toggle_uid'])) {
    $pdo->prepare("UPDATE devices SET active = 1 - active WHERE uid = ?")->execute([$_GET['toggle_uid']]);
    header('Location: index.php?tab=devices'); exit;
}

if (isset($_GET['del_uid'])) {
    $pdo->prepare("DELETE FROM devices WHERE uid = ?")->execute([$_GET['del_uid']]);
    header('Location: index.php?tab=devices'); exit;
}

if (isset($_POST['add_app'])) {
    $name = trim((string)$_POST['app_name']);
    $pkg = trim((string)$_POST['app_pkg']);
    $ver = max(1, (int)($_POST['app_ver'] ?? 1));
    $target = strtoupper(trim((string)($_POST['target_uid'] ?? '')));
    $iconUrl = save_upload('icon_file', ['png','jpg','jpeg','webp'], $uploadDir) ?: trim((string)($_POST['icon_url'] ?? ''));
    $apkUrl = save_upload('apk_file', ['apk'], $uploadDir) ?: trim((string)($_POST['apk_url'] ?? ''));
    if ($name && $pkg) {
        $pdo->prepare("INSERT INTO apps (name, package, version_code, apk, icon, target_uid) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$name, $pkg, $ver, $apkUrl, $iconUrl, $target]);
        $msg = "تمت إضافة التطبيق ($name) بنجاح!";
    } else {
        $err = "أدخل اسم التطبيق واسم الحزمة (Package)!";
    }
    $tab = 'apps';
}

if (isset($_GET['del_app'])) {
    $pdo->prepare("DELETE FROM apps WHERE id = ?")->execute([(int)$_GET['del_app']]);
    header('Location: index.php?tab=apps'); exit;
}

if (isset($_POST['send_msg'])) {
    $body = trim((string)$_POST['msg_body']);
    $target = strtoupper(trim((string)$_POST['target_uid'] ?? ''));
    if ($body !== '') {
        $pdo->prepare("INSERT INTO messages (body, target_uid, created_at) VALUES (?, ?, ?)")
            ->execute([$body, $target, date('Y-m-d H:i')]);
        $msg = "تم إرسال الرسالة بنجاح!";
    }
    $tab = 'messages';
}

if (isset($_GET['del_msg'])) {
    $pdo->prepare("DELETE FROM messages WHERE id = ?")->execute([(int)$_GET['del_msg']]);
    header('Location: index.php?tab=messages'); exit;
}

if (isset($_POST['add_photo'])) {
    $url = save_upload('photo_file', ['png','jpg','jpeg','webp'], $uploadDir) ?: trim((string)($_POST['photo_url'] ?? ''));
    if ($url !== '') {
        $pdo->prepare("INSERT INTO store (url, thumb, created_at) VALUES (?, ?, ?)")->execute([$url, $url, date('Y-m-d H:i')]);
        $msg = "تمت إضافة الصورة للمتجر!";
    }
    $tab = 'store';
}

if (isset($_GET['del_photo'])) {
    $pdo->prepare("DELETE FROM store WHERE id = ?")->execute([(int)$_GET['del_photo']]);
    header('Location: index.php?tab=store'); exit;
}

if (isset($_POST['save_settings'])) {
    $logo = save_upload('logo_file', ['png','jpg','jpeg','webp'], $uploadDir);
    $pairs = [
        'shop_name' => trim((string)$_POST['shop_name']),
        'shop_whatsapp' => normalize_wa((string)$_POST['shop_whatsapp']),
        'shop_welcome' => trim((string)$_POST['shop_welcome']),
        'shop_logo' => $logo !== '' ? $logo : trim((string)$_POST['shop_logo']),
        'mytv_ver_code' => (string)max(1, (int)$_POST['mytv_ver_code']),
        'mytv_ver_name' => trim((string)$_POST['mytv_ver_name']),
        'mytv_apk' => trim((string)$_POST['mytv_apk']),
        'mytv_notes' => trim((string)$_POST['mytv_notes']),
    ];
    $up = $pdo->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)");
    foreach ($pairs as $k => $v) $up->execute([$k, $v]);
    $msg = "تم حفظ إعدادات المحل!";
    $tab = 'settings';
}

$set = get_settings($pdo);
$devices = $pdo->query("SELECT * FROM devices ORDER BY paired ASC, last_seen DESC")->fetchAll();
$apps = $pdo->query("SELECT * FROM apps ORDER BY id DESC")->fetchAll();
$msgs = $pdo->query("SELECT * FROM messages ORDER BY id DESC")->fetchAll();
$photos = $pdo->query("SELECT * FROM store ORDER BY id DESC")->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($set['shop_name']) ?> - لوحة التحكم</title>
<style>
:root{--bg:#0a0c11;--card:#141821;--line:#2a3245;--gold:#ffb21e;--text:#f6efe0;--muted:#9aa3b2;--ok:#3fd38a;--bad:#f85149}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;padding:16px}
.wrap{max-width:1100px;margin:0 auto}
header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding-bottom:14px;border-bottom:1px solid var(--line);margin-bottom:16px}
header h1{margin:0;font-size:1.4rem;color:var(--gold)}
.nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.nav a{padding:10px 16px;background:var(--card);border:1px solid var(--line);border-radius:8px;color:var(--text);text-decoration:none;font-weight:700;font-size:.95rem}
.nav a.on{background:var(--gold);color:#000;border-color:var(--gold)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px}
.card h2{margin:0 0 14px;font-size:1.1rem;color:var(--gold)}
label{display:block;font-size:.85rem;color:var(--muted);margin-bottom:5px}
input,select,textarea{width:100%;padding:11px;border-radius:8px;border:1px solid var(--line);background:#0a0c11;color:#fff;font-size:.95rem;margin-bottom:12px}
button,.btn{background:linear-gradient(135deg,#ffb21e,#ff5a36);color:#000;border:0;padding:11px 18px;border-radius:8px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block;font-size:.95rem}
.btn-s{padding:6px 10px;font-size:.8rem;border-radius:6px}
.btn-g{background:#232a3b;color:#fff}
.btn-r{background:#f85149;color:#fff}
.alert{padding:12px;border-radius:8px;margin-bottom:14px;font-weight:700}
.alert.ok{background:rgba(63,211,138,.15);border:1px solid var(--ok);color:var(--ok)}
.alert.err{background:rgba(248,81,73,.15);border:1px solid var(--bad);color:var(--bad)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{padding:10px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}
th{color:var(--muted);font-size:.8rem}
.code{font-family:monospace;font-size:1.15rem;font-weight:900;color:var(--gold);letter-spacing:2px}
.badge{padding:3px 8px;border-radius:99px;font-size:.75rem;font-weight:800}
.b-ok{background:rgba(63,211,138,.2);color:var(--ok)}
.b-wait{background:rgba(255,178,30,.2);color:var(--gold)}
.b-bad{background:rgba(248,81,73,.2);color:var(--bad)}
.photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
.ph{background:#0a0c11;border:1px solid var(--line);border-radius:10px;overflow:hidden;text-align:center;padding-bottom:8px}
.ph img{width:100%;height:120px;object-fit:cover;margin-bottom:8px}
</style>
</head>
<body>
<div class="wrap">
<header>
    <h1>📺 <?= htmlspecialchars($set['shop_name']) ?> — لوحة إدارة MyTV</h1>
    <a href="?logout=1" class="btn btn-s btn-g">تسجيل الخروج</a>
</header>

<?php if ($msg): ?><div class="alert ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<nav class="nav">
    <a href="?tab=devices" class="<?= $tab==='devices'?'on':'' ?>">الأجهزة (<?= count($devices) ?>)</a>
    <a href="?tab=apps" class="<?= $tab==='apps'?'on':'' ?>">التطبيقات (<?= count($apps) ?>)</a>
    <a href="?tab=messages" class="<?= $tab==='messages'?'on':'' ?>">الرسائل (<?= count($msgs) ?>)</a>
    <a href="?tab=store" class="<?= $tab==='store'?'on':'' ?>">المتجر (<?= count($photos) ?>)</a>
    <a href="?tab=settings" class="<?= $tab==='settings'?'on':'' ?>">إعدادات المحل</a>
</nav>

<?php if ($tab === 'devices'): ?>
<div class="grid">
    <div class="card">
        <h2>⚡ تفعيل جهاز جديد بالكود</h2>
        <form method="post">
            <label>كود الشاشة (5 أحرف):</label>
            <input type="text" name="code" placeholder="مثال: CCHWN" required style="text-align:center;font-size:1.3rem;letter-spacing:3px;text-transform:uppercase">
            <label>اسم الزبون (اختياري):</label>
            <input type="text" name="client_name" placeholder="مثال: علي بن صالح">
            <label>مدة الاشتراك:</label>
            <select name="months">
                <option value="1">شهر واحد</option>
                <option value="3">3 أشهر</option>
                <option value="6">6 أشهر</option>
                <option value="12" selected>سنة كاملة (12 شهر)</option>
                <option value="24">سنتان</option>
                <option value="0">مفتوح (بلا تاريخ انتهاء)</option>
            </select>
            <button type="submit" name="pair_device">تفعيل الجهاز الآن</button>
        </form>
    </div>

    <div class="card" style="grid-column:span 2">
        <h2>📺 قائمة الأجهزة المسجلة</h2>
        <div class="table-wrap">
        <table>
            <tr><th>الكود</th><th>الزبون</th><th>الجهاز</th><th>الحالة</th><th>الانتهاء</th><th>إجراءات</th></tr>
            <?php foreach ($devices as $d):
                $exp = $d['expires_at'];
                $expired = ($exp !== '' && $exp < date('Y-m-d'));
            ?>
            <tr>
                <td class="code"><?= htmlspecialchars($d['code']) ?></td>
                <td><?= htmlspecialchars($d['client_name'] ?: '—') ?></td>
                <td><?= htmlspecialchars($d['model']) ?> <small style="color:var(--muted)">(v<?= htmlspecialchars($d['app_ver']) ?>)</small></td>
                <td>
                    <?php if (!$d['paired']): ?><span class="badge b-wait">ينتظر التفعيل</span>
                    <?php elseif (!$d['active']): ?><span class="badge b-bad">موقوف</span>
                    <?php elseif ($expired): ?><span class="badge b-bad">منتهي</span>
                    <?php else: ?><span class="badge b-ok">شغال</span><?php endif; ?>
                </td>
                <td><?= $exp ? htmlspecialchars($exp) : 'مفتوح' ?></td>
                <td>
                    <?php if (!$d['paired']): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="code" value="<?= htmlspecialchars($d['code']) ?>">
                            <input type="hidden" name="months" value="12">
                            <button class="btn-s" name="pair_device">تفعيل سنة</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="renew_uid" value="<?= htmlspecialchars($d['uid']) ?>">
                            <input type="hidden" name="add_months" value="12">
                            <button class="btn-s">+سنة</button>
                        </form>
                        <a class="btn btn-s btn-g" href="?toggle_uid=<?= urlencode($d['uid']) ?>"><?= $d['active']?'إيقاف':'تشغيل' ?></a>
                    <?php endif; ?>
                    <a class="btn btn-s btn-r" href="?del_uid=<?= urlencode($d['uid']) ?>" onclick="return confirm('حذف الجهاز؟')">حذف</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'apps'): ?>
<div class="grid">
    <div class="card">
        <h2>➕ إضافة تطبيق جديد</h2>
        <form method="post" enctype="multipart/form-data">
            <label>اسم التطبيق:</label>
            <input type="text" name="app_name" placeholder="مثال: IPTV Smarters" required>
            <label>اسم الحزمة (Package Name):</label>
            <input type="text" name="app_pkg" placeholder="مثال: com.nst.iptvsmarterstvbox" required>
            <label>رقم الإصدار (Version Code):</label>
            <input type="number" name="app_ver" value="1" required>
            <label>أيقونة التطبيق (رفع صورة أو رابط):</label>
            <input type="file" name="icon_file" accept="image/*">
            <input type="url" name="icon_url" placeholder="أو رابط صورة الأيقونة (https://...)">
            <label>ملف APK للتحميل التلقائي (رابط مباشر أو رفع):</label>
            <input type="url" name="apk_url" placeholder="رابط ملف الـ APK (https://...)">
            <input type="file" name="apk_file" accept=".apk">
            <label>إرسال إلى:</label>
            <select name="target_uid">
                <option value="">جميع الأجهزة (الكل)</option>
                <?php foreach ($devices as $d): ?>
                    <option value="<?= htmlspecialchars($d['code']) ?>"><?= htmlspecialchars($d['code'] . ($d['client_name'] ? ' - ' . $d['client_name'] : '')) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="add_app">حفظ ونشر التطبيق</button>
        </form>
    </div>
    <div class="card" style="grid-column:span 2">
        <h2>📱 التطبيقات المضافة</h2>
        <div class="table-wrap">
        <table>
            <tr><th>الشعار</th><th>الاسم</th><th>الحزمة</th><th>الإصدار</th><th>الهدف</th><th>حذف</th></tr>
            <?php foreach ($apps as $a): ?>
            <tr>
                <td><?php if ($a['icon']): ?><img src="<?= htmlspecialchars($a['icon']) ?>" width="36" height="36" style="border-radius:50%;object-fit:cover"><?php else: ?>📺<?php endif; ?></td>
                <td><b><?= htmlspecialchars($a['name']) ?></b></td>
                <td><code><?= htmlspecialchars($a['package']) ?></code></td>
                <td>v<?= (int)$a['version_code'] ?></td>
                <td><?= $a['target_uid'] ? htmlspecialchars($a['target_uid']) : 'الكل' ?></td>
                <td><a class="btn btn-s btn-r" href="?del_app=<?= (int)$a['id'] ?>" onclick="return confirm('حذف التطبيق؟')">حذف</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'messages'): ?>
<div class="grid">
    <div class="card">
        <h2>✉️ إرسال رسالة للحرفاء</h2>
        <form method="post">
            <label>إرسال إلى:</label>
            <select name="target_uid">
                <option value="">جميع الأجهزة (الكل)</option>
                <?php foreach ($devices as $d): ?>
                    <option value="<?= htmlspecialchars($d['code']) ?>"><?= htmlspecialchars($d['code'] . ($d['client_name'] ? ' - ' . $d['client_name'] : '')) ?></option>
                <?php endforeach; ?>
            </select>
            <label>نص الرسالة:</label>
            <textarea name="msg_body" rows="4" placeholder="اكتب رسالتك هنا..." required></textarea>
            <button type="submit" name="send_msg">إرسال الرسالة</button>
        </form>
    </div>
    <div class="card" style="grid-column:span 2">
        <h2>📨 سجل الرسائل</h2>
        <table>
            <tr><th>التاريخ</th><th>الهدف</th><th>الرسالة</th><th>حذف</th></tr>
            <?php foreach ($msgs as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['created_at']) ?></td>
                <td><?= $m['target_uid'] ? htmlspecialchars($m['target_uid']) : 'الكل' ?></td>
                <td style="white-space:normal"><?= htmlspecialchars($m['body']) ?></td>
                <td><a class="btn btn-s btn-r" href="?del_msg=<?= (int)$m['id'] ?>">حذف</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php elseif ($tab === 'store'): ?>
<div class="grid">
    <div class="card">
        <h2>🛍️ إضافة صورة لمتجر المحل</h2>
        <form method="post" enctype="multipart/form-data">
            <label>اختر صورة من جهازك:</label>
            <input type="file" name="photo_file" accept="image/*">
            <label>أو رابط صورة مباشر:</label>
            <input type="url" name="photo_url" placeholder="https://...">
            <button type="submit" name="add_photo">إضافة للمتجر</button>
        </form>
    </div>
    <div class="card" style="grid-column:span 2">
        <h2>🖼️ صور المتجر الحالية</h2>
        <div class="photos">
            <?php foreach ($photos as $p): ?>
            <div class="ph">
                <img src="<?= htmlspecialchars($p['url']) ?>" alt="">
                <a class="btn btn-s btn-r" href="?del_photo=<?= (int)$p['id'] ?>">حذف</a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php elseif ($tab === 'settings'): ?>
<div class="card" style="max-width:650px">
    <h2>⚙️ إعدادات المحل وتحديث MyTV</h2>
    <form method="post" enctype="multipart/form-data">
        <label>اسم المحل:</label>
        <input type="text" name="shop_name" value="<?= htmlspecialchars($set['shop_name']) ?>" required>
        <label>رقم الواتساب (مثال: 21626117917):</label>
        <input type="text" name="shop_whatsapp" value="<?= htmlspecialchars($set['shop_whatsapp']) ?>" required>
        <label>الرسالة الترحيبية:</label>
        <input type="text" name="shop_welcome" value="<?= htmlspecialchars($set['shop_welcome']) ?>">
        <label>شعار المحل (رفع صورة أو رابط):</label>
        <input type="file" name="logo_file" accept="image/*">
        <input type="url" name="shop_logo" value="<?= htmlspecialchars($set['shop_logo']) ?>" placeholder="أو رابط الشعار">
        <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
        <label>تحديث تطبيق MyTV (Version Code):</label>
        <input type="number" name="mytv_ver_code" value="<?= htmlspecialchars($set['mytv_ver_code']) ?>">
        <label>اسم النسخة (Version Name):</label>
        <input type="text" name="mytv_ver_name" value="<?= htmlspecialchars($set['mytv_ver_name']) ?>">
        <label>رابط ملف APK لتحديث MyTV:</label>
        <input type="url" name="mytv_apk" value="<?= htmlspecialchars($set['mytv_apk']) ?>">
        <label>ملاحظات التحديث:</label>
        <input type="text" name="mytv_notes" value="<?= htmlspecialchars($set['mytv_notes']) ?>">
        <button type="submit" name="save_settings">حفظ الإعدادات</button>
    </form>
</div>
<?php endif; ?>
</div>
</body>
</html>
