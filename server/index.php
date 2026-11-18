<?php
session_start();
require_once __DIR__ . '/db.php';

$dataDir = is_dir('/home/azsatconnect/mytv_data') ? '/home/azsatconnect/mytv_data' : (dirname(__DIR__) . '/mytv_data');
$adminFile = $dataDir . '/admin.json';
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$hasPass = file_exists($adminFile);
$loginErr = '';

// إنشاء كلمة مرور الأدمن أول مرة
if (isset($_POST['setup_pass']) && !$hasPass) {
    $p = trim((string)$_POST['new_pass']);
    if (strlen($p) < 4) $loginErr = 'Mot de passe trop court.';
    else {
        file_put_contents($adminFile, json_encode(['hash' => password_hash($p, PASSWORD_DEFAULT)]));
        $_SESSION['is_super_admin'] = true;
        $_SESSION['reseller_id'] = 0;
        header('Location: index.php'); exit;
    }
}

// تسجيل دخول الأدمن أو الموزع
if (isset($_POST['login_user']) && $hasPass) {
    $u = trim((string)$_POST['username']);
    $p = trim((string)$_POST['pass']);

    $adm = json_decode(file_get_contents($adminFile), true);
    // 1. الدخول كـ Super Admin
    if ($u === 'admin' && $adm && password_verify($p, $adm['hash'] ?? '')) {
        $_SESSION['is_super_admin'] = true;
        $_SESSION['reseller_id'] = 0;
        $_SESSION['admin_user'] = 'Super Admin';
        header('Location: index.php'); exit;
    } else {
        // 2. الدخول كـ موزع (Revendeur)
        $st = $pdo->prepare("SELECT * FROM resellers WHERE username = ?");
        $st->execute([$u]);
        $res = $st->fetch();
        if ($res && password_verify($p, $res['password'])) {
            $_SESSION['is_super_admin'] = false;
            $_SESSION['reseller_id'] = (int)$res['id'];
            $_SESSION['admin_user'] = $res['shop_name'];
            header('Location: index.php'); exit;
        } else {
            $loginErr = 'Nom d’utilisateur ou mot de passe incorrect !';
        }
    }
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

if (empty($_SESSION['is_super_admin']) && empty($_SESSION['reseller_id'])):
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BETA Panel - Connexion</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d1117;color:#f0f6fc;font-family:-apple-system,sans-serif;padding:20px}
.box{width:100%;max-width:380px;background:#161b22;border:1px solid #30363d;border-radius:14px;padding:30px;text-align:center}
.logo-tag{display:inline-block;background:#1f6feb;color:#fff;padding:4px 12px;border-radius:6px;font-weight:900;font-size:.85rem;margin-bottom:12px}
h1{margin:0 0 6px;font-size:1.6rem;color:#58a6ff}
input{width:100%;padding:13px;border-radius:8px;border:1px solid #30363d;background:#0d1117;color:#fff;font-size:15px;box-sizing:border-box;margin-bottom:12px;text-align:center}
button{width:100%;padding:14px;border:0;border-radius:8px;background:#238636;color:#fff;font-weight:800;font-size:16px;cursor:pointer}
.err{background:rgba(248,81,73,.15);color:#f85149;padding:10px;border-radius:8px;margin-bottom:12px}
</style>
</head>
<body>
<div class="box">
    <span class="logo-tag">BETA v2.0</span>
    <h1>AZ SAT CONNECT</h1>
    <p style="color:#8b949e;font-size:.9rem;margin-bottom:20px">Portail Revendeurs & Administration</p>
    <?php if ($loginErr): ?><div class="err"><?= htmlspecialchars($loginErr) ?></div><?php endif; ?>
    <?php if (!$hasPass): ?>
        <form method="post">
            <input type="password" name="new_pass" placeholder="Créer mot de passe Super Admin" required>
            <button name="setup_pass">Initialiser</button>
        </form>
    <?php else: ?>
        <form method="post">
            <input type="text" name="username" placeholder="Utilisateur (ex: admin)" required autofocus>
            <input type="password" name="pass" placeholder="Mot de passe" required>
            <button name="login_user">Connexion</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
<?php exit; endif;

$isSuper = !empty($_SESSION['is_super_admin']);
$resId = (int)($_SESSION['reseller_id'] ?? 0);

function save_upload(string $field, array $allowed, string $uploadDir): string {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    $name = uniqid($field . '_', true) . '.' . $ext;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . '/' . $name)) return base_url() . '/uploads/' . $name;
    return '';
}

$msg = ''; $err = '';
$view = $_GET['v'] ?? 'dashboard';

// إضافة موزع جديد (Revendeur)
if ($isSuper && isset($_POST['add_reseller'])) {
    $u = trim((string)$_POST['res_user']);
    $p = trim((string)$_POST['res_pass']);
    $sname = trim((string)$_POST['res_shop']);
    $swa = normalize_wa((string)$_POST['res_wa']);
    $maxD = max(1, (int)$_POST['res_max']);
    $logo = save_upload('res_logo', ['png','jpg','jpeg','webp'], $uploadDir);

    if ($u && $p && $sname && $swa) {
        $st = $pdo->prepare("INSERT INTO resellers (username, password, shop_name, shop_logo, shop_whatsapp, welcome_msg, max_devices, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        try {
            $st->execute([$u, password_hash($p, PASSWORD_DEFAULT), $sname, $logo, $swa, "مرحباً بكم عند $sname", $maxD, date('Y-m-d')]);
            $msg = "Revendeur ($sname) créé avec succès ! Identifiants : $u";
        } catch (Exception $e) {
            $err = "Ce nom d'utilisateur existe déjà !";
        }
    }
}

if ($isSuper && isset($_GET['del_reseller'])) {
    $pdo->prepare("DELETE FROM resellers WHERE id = ?")->execute([(int)$_GET['del_reseller']]);
    header('Location: index.php?v=resellers'); exit;
}

// تفعيل جهاز
if (isset($_POST['pair_device'])) {
    $code = strtoupper(trim((string)$_POST['code']));
    $cname = trim((string)($_POST['client_name'] ?? ''));
    $months = (int)($_POST['months'] ?? 12);
    $assignRes = $isSuper ? (int)($_POST['target_reseller'] ?? 0) : $resId;

    $st = $pdo->prepare("SELECT * FROM devices WHERE code = ?");
    $st->execute([$code]);
    $dev = $st->fetch();
    if (!$dev) {
        $err = "Code ($code) introuvable sur le boîtier !";
    } else {
        $exp = $months > 0 ? date('Y-m-d', strtotime("+{$months} months")) : '';
        $pdo->prepare("UPDATE devices SET paired = 1, active = 1, client_name = ?, expires_at = ?, reseller_id = ? WHERE uid = ?")
            ->execute([$cname !== '' ? $cname : $dev['client_name'], $exp, $assignRes, $dev['uid']]);
        $msg = "Appareil ($code) activé avec succès !";
    }
}

// تخصيص تطبيقات لجهاز
if (isset($_POST['save_dev_apps'])) {
    $duid = (string)$_POST['dev_uid'];
    $chosen = $_POST['app_ids'] ?? [];
    $pdo->prepare("DELETE FROM device_apps WHERE uid = ?")->execute([$duid]);
    $ins = $pdo->prepare("INSERT OR IGNORE INTO device_apps (uid, app_id) VALUES (?, ?)");
    foreach ($chosen as $aid) $ins->execute([$duid, (int)$aid]);
    $msg = "Applications enregistrées !";
}

// تمديد اشتراك
if (isset($_POST['renew_uid'])) {
    $uid = (string)$_POST['renew_uid'];
    $add = (int)$_POST['add_months'];
    $st = $pdo->prepare("SELECT expires_at FROM devices WHERE uid = ?"); $st->execute([$uid]);
    $cur = (string)$st->fetchColumn();
    $base = ($cur !== '' && strtotime($cur) > time()) ? strtotime($cur) : time();
    $newExp = date('Y-m-d', strtotime("+{$add} months", $base));
    $pdo->prepare("UPDATE devices SET expires_at = ?, active = 1, paired = 1 WHERE uid = ?")->execute([$newExp, $uid]);
    $msg = "Prolongé jusqu'au $newExp !";
}

if (isset($_GET['toggle_uid'])) { $pdo->prepare("UPDATE devices SET active = 1 - active WHERE uid = ?")->execute([$_GET['toggle_uid']]); header('Location: index.php?v=subscribers'); exit; }
if (isset($_GET['del_uid'])) { $pdo->prepare("DELETE FROM devices WHERE uid = ?")->execute([$_GET['del_uid']]); header('Location: index.php?v=subscribers'); exit; }

// إضافة تطبيق
if (isset($_POST['add_app'])) {
    $name = trim((string)$_POST['app_name']);
    $pkg = trim((string)$_POST['app_pkg']);
    $ver = max(1, (int)($_POST['app_ver'] ?? 1));
    $isGlobal = (int)($_POST['is_global'] ?? 1);
    $iconUrl = save_upload('icon_file', ['png','jpg','jpeg','webp'], $uploadDir) ?: trim((string)($_POST['icon_url'] ?? ''));
    if ($iconUrl === '' && !empty($_POST['auto_icon_b64'])) {
        if (preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/i', (string)$_POST['auto_icon_b64'], $m)) {
            $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
            $bin = base64_decode($m[2]);
            if ($bin !== false && strlen($bin) < 2097152) {
                $fname = uniqid('icon_', true) . '.' . $ext;
                if (file_put_contents($uploadDir . '/' . $fname, $bin)) $iconUrl = base_url() . '/uploads/' . $fname;
            }
        }
    }
    $apkUrl = save_upload('apk_file', ['apk'], $uploadDir) ?: trim((string)($_POST['apk_url'] ?? ''));
    if ($name && $pkg) {
        $pdo->prepare("INSERT INTO apps (name, package, version_code, apk, icon, is_global, reseller_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, $pkg, $ver, $apkUrl, $iconUrl, $isGlobal, $resId]);
        $msg = "Application ($name) ajoutée !";
    }
}

if (isset($_GET['toggle_global'])) { $pdo->prepare("UPDATE apps SET is_global = 1 - is_global WHERE id = ?")->execute([(int)$_GET['toggle_global']]); header('Location: index.php?v=apps'); exit; }
if (isset($_GET['del_app'])) { $pdo->prepare("DELETE FROM apps WHERE id = ?")->execute([(int)$_GET['del_app']]); header('Location: index.php?v=apps'); exit; }

// رسائل
if (isset($_POST['send_msg'])) {
    $body = trim((string)$_POST['msg_body']);
    $target = strtoupper(trim((string)($_POST['target_uid'] ?? '')));
    if ($body !== '') {
        $pdo->prepare("INSERT INTO messages (body, target_uid, created_at, reseller_id) VALUES (?, ?, ?, ?)")
            ->execute([$body, $target, date('Y-m-d H:i'), $resId]);
        $msg = "Message diffusé !";
    }
}
if (isset($_GET['del_msg'])) { $pdo->prepare("DELETE FROM messages WHERE id = ?")->execute([(int)$_GET['del_msg']]); header('Location: index.php?v=messages'); exit; }

// إعدادات المحل
if (isset($_POST['save_settings'])) {
    if ($isSuper) {
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
    } else {
        $logo = save_upload('logo_file', ['png','jpg','jpeg','webp'], $uploadDir);
        $sname = trim((string)$_POST['shop_name']);
        $swa = normalize_wa((string)$_POST['shop_whatsapp']);
        $swel = trim((string)$_POST['shop_welcome']);
        $sql = "UPDATE resellers SET shop_name = ?, shop_whatsapp = ?, welcome_msg = ?" . ($logo ? ", shop_logo = ?" : "") . " WHERE id = ?";
        $params = $logo ? [$sname, $swa, $swel, $logo, $resId] : [$sname, $swa, $swel, $resId];
        $pdo->prepare($sql)->execute($params);
    }
    $msg = "Paramètres enregistrés !";
}

$set = get_settings($pdo);
$resellers = $pdo->query("SELECT * FROM resellers ORDER BY id DESC")->fetchAll();

// جلب الأجهزة حسب الصلاحية (الأدمن يرى الكل، الموزع يرى أجهزته فقط)
if ($isSuper) {
    $devices = $pdo->query("SELECT d.*, r.shop_name as reseller_name FROM devices d LEFT JOIN resellers r ON r.id = d.reseller_id ORDER BY d.paired ASC, d.last_seen DESC")->fetchAll();
    $apps = $pdo->query("SELECT * FROM apps ORDER BY id DESC")->fetchAll();
    $msgs = $pdo->query("SELECT * FROM messages ORDER BY id DESC")->fetchAll();
} else {
    $stD = $pdo->prepare("SELECT * FROM devices WHERE reseller_id = ? ORDER BY paired ASC, last_seen DESC");
    $stD->execute([$resId]); $devices = $stD->fetchAll();
    $stA = $pdo->prepare("SELECT * FROM apps WHERE reseller_id = ? OR is_global = 1 ORDER BY id DESC");
    $stA->execute([$resId]); $apps = $stA->fetchAll();
    $stM = $pdo->prepare("SELECT * FROM messages WHERE reseller_id = ? ORDER BY id DESC");
    $stM->execute([$resId]); $msgs = $stM->fetchAll();
}

$totalDev = count($devices);
$activeDev = 0; $expiredDev = 0; $pendingDev = 0; $today = date('Y-m-d');
foreach ($devices as $d) {
    if (!$d['paired'] || !$d['active']) $pendingDev++;
    elseif ($d['expires_at'] !== '' && $d['expires_at'] < $today) $expiredDev++;
    else $activeDev++;
}
$appsCount = count($apps);

$editDev = null; $assignedIds = [];
if (!empty($_GET['dev_apps'])) {
    $st = $pdo->prepare("SELECT * FROM devices WHERE uid = ?"); $st->execute([$_GET['dev_apps']]);
    $editDev = $st->fetch();
    if ($editDev) {
        $stA = $pdo->prepare("SELECT app_id FROM device_apps WHERE uid = ?"); $stA->execute([$editDev['uid']]);
        $assignedIds = array_map('intval', array_column($stA->fetchAll(), 'app_id'));
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BETA Panel - <?= htmlspecialchars($_SESSION['admin_user'] ?? $set['shop_name']) ?></title>
<style>
:root{
  --bg:#0b101b; --sidebar:#060a12; --card:#131b2e; --border:#1e293b; --text:#f8fafc; --muted:#94a3b8;
  --blue:#3b82f6; --green:#10b981; --red:#ef4444; --orange:#f97316; --purple:#a855f7;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;min-height:100vh}

aside{width:240px;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0}
.brand{padding:20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border)}
.brand-icon{width:36px;height:36px;background:var(--blue);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
.brand-text b{display:block;font-size:1rem;color:#fff}
.brand-text span{font-size:.72rem;color:var(--blue);font-weight:700}
nav{padding:14px 10px;flex:1;display:flex;flex-direction:column;gap:4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:.9rem;font-weight:600}
.nav-item:hover{background:rgba(255,255,255,.05);color:#fff}
.nav-item.active{background:var(--blue);color:#fff}
.nav-item .icon{font-size:1.1rem;width:20px}

main{flex:1;display:flex;flex-direction:column;min-width:0;overflow-y:auto}
header{padding:16px 26px;background:rgba(6,10,18,.8);backdrop-filter:blur(8px);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
header h2{margin:0;font-size:1.3rem;font-weight:700}
.user-btn{background:var(--card);border:1px solid var(--border);padding:8px 14px;border-radius:8px;color:#fff;text-decoration:none;font-size:.85rem;font-weight:600}

.content{padding:22px 26px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-weight:600}
.alert.ok{background:rgba(16,185,129,.15);border:1px solid var(--green);color:var(--green)}
.alert.err{background:rgba(239,68,68,.15);border:1px solid var(--red);color:var(--red)}

.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px}
.stat-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.stat-icon.blue{background:var(--blue)}
.stat-icon.green{background:var(--green)}
.stat-icon.red{background:var(--red)}
.stat-icon.orange{background:var(--orange)}
.stat-icon.purple{background:var(--purple)}
.stat-info b{display:block;font-size:1.55rem;font-weight:800;color:#fff;line-height:1.2}
.stat-info span{font-size:.78rem;color:var(--muted);font-weight:600}

.panel-card{background:var(--card);border:1px solid var(--border);border-radius:12px;margin-bottom:20px;overflow:hidden}
.panel-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.panel-head h3{margin:0;font-size:1.05rem;font-weight:700}
.panel-body{padding:20px}

/* جدول عريض ومتناسق بدون سحب لليمين */
.table-responsive{width:100%;overflow-x:hidden}
table{width:100%;border-collapse:collapse;font-size:.88rem;table-layout:fixed}
th{background:rgba(6,10,18,.6);color:var(--muted);font-size:.76rem;font-weight:700;padding:11px 12px;border-bottom:1px solid var(--border);text-align:left}
td{padding:11px 12px;border-bottom:1px solid rgba(30,41,59,.5);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
tr:hover td{background:rgba(255,255,255,.02)}

.badge{padding:3px 8px;border-radius:99px;font-size:.72rem;font-weight:800;display:inline-block}
.b-actif{background:rgba(16,185,129,.15);color:var(--green)}
.b-exp{background:rgba(239,68,68,.15);color:var(--red)}
.b-wait{background:rgba(249,115,22,.15);color:var(--orange)}
.code-tag{font-family:monospace;font-size:1rem;font-weight:900;letter-spacing:1px;color:var(--blue)}

/* Forms */
label{display:block;font-size:.82rem;color:var(--muted);font-weight:600;margin-bottom:5px}
input,select,textarea{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:#0b101b;color:#fff;font-size:.92rem;margin-bottom:12px;box-sizing:border-box}
.btn{background:var(--blue);color:#fff;border:0;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.88rem;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.btn-s{padding:4px 8px;font-size:.78rem;border-radius:6px}
.btn-green{background:var(--green)}
.btn-red{background:var(--red)}
.btn-gray{background:#1e293b}

.chk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin:12px 0 18px}
.chk-item{display:flex;align-items:center;gap:8px;background:#0b101b;border:1px solid var(--border);padding:8px 12px;border-radius:8px;cursor:pointer}
.chk-item input{width:16px;height:16px;margin:0}

@media(max-width:850px){
  body{flex-direction:column}
  aside{width:100%;border-right:0;border-bottom:1px solid var(--border)}
  nav{flex-direction:row;flex-wrap:wrap;overflow-x:auto}
  .table-responsive{overflow-x:auto}
  table{table-layout:auto}
}
</style>
</head>
<body>

<aside>
    <div class="brand">
        <div class="brand-icon">📺</div>
        <div class="brand-text">
            <b>BETA PANEL</b>
            <span><?= $isSuper ? 'SUPER ADMIN 👑' : 'REVENDEUR' ?></span>
        </div>
    </div>
    <nav>
        <a href="?v=dashboard" class="nav-item <?= $view==='dashboard'?'active':'' ?>"><span class="icon">📊</span> Dashboard</a>
        <a href="?v=activate" class="nav-item <?= $view==='activate'?'active':'' ?>"><span class="icon">⚡</span> Activer Appareil</a>
        <a href="?v=subscribers" class="nav-item <?= $view==='subscribers'?'active':'' ?>"><span class="icon">👥</span> Abonnés (<?= $totalDev ?>)</a>
        <?php if ($isSuper): ?>
            <a href="?v=resellers" class="nav-item <?= $view==='resellers'?'active':'' ?>" style="color:var(--orange)"><span class="icon">🏬</span> Revendeurs (<?= count($resellers) ?>)</a>
        <?php endif; ?>
        <a href="?v=apps" class="nav-item <?= $view==='apps'?'active':'' ?>"><span class="icon">📦</span> Bibliothèque APK</a>
        <a href="?v=messages" class="nav-item <?= $view==='messages'?'active':'' ?>"><span class="icon">✉️</span> Messages</a>
        <a href="?v=settings" class="nav-item <?= $view==='settings'?'active':'' ?>"><span class="icon">⚙️</span> <?= $isSuper ? 'Boutique & MyTV' : 'Mon Logo & Shop' ?></a>
    </nav>
</aside>

<main>
    <header>
        <h2><?= strtoupper($view) ?> — <?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?></h2>
        <a href="?logout=1" class="user-btn">Déconnexion 🚪</a>
    </header>

    <div class="content">
        <?php if ($msg): ?><div class="alert ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <!-- Stats Cards العلوية الـ 5 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">👥</div>
                <div class="stat-info"><b><?= $totalDev ?></b><span>Total Abonnés</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">🟢</div>
                <div class="stat-info"><b><?= $activeDev ?></b><span>Actifs</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red">🔴</div>
                <div class="stat-info"><b><?= $expiredDev ?></b><span>Expirés</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">⏳</div>
                <div class="stat-info"><b><?= $pendingDev ?></b><span>En attente</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">📦</div>
                <div class="stat-info"><b><?= $appsCount ?></b><span>APKs Disponibles</span></div>
            </div>
        </div>

        <!-- 1. DASHBOARD & SUBSCRIBERS TABLE -->
        <?php if ($view === 'dashboard' || $view === 'subscribers'): ?>

        <?php if ($editDev): ?>
        <div class="panel-card" style="border-color:var(--blue)">
            <div class="panel-head">
                <h3>📱 Applications pour : <span class="code-tag"><?= htmlspecialchars($editDev['code']) ?></span> (<?= htmlspecialchars($editDev['client_name'] ?: 'Sans Nom') ?>)</h3>
            </div>
            <div class="panel-body">
                <form method="post">
                    <input type="hidden" name="dev_uid" value="<?= htmlspecialchars($editDev['uid']) ?>">
                    <div class="chk-grid">
                        <?php foreach ($apps as $a): $isG = ((int)$a['is_global'] === 1); ?>
                        <label class="chk-item">
                            <?php if ($isG): ?><input type="checkbox" checked disabled><?php else: ?>
                            <input type="checkbox" name="app_ids[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $assignedIds, true)?'checked':'' ?>><?php endif; ?>
                            <?php if ($a['icon']): ?><img src="<?= htmlspecialchars($a['icon']) ?>" width="26" height="26" style="border-radius:6px;object-fit:cover"><?php else: ?>📺<?php endif; ?>
                            <div><b><?= htmlspecialchars($a['name']) ?></b><br><small style="color:var(--muted)"><?= $isG?'Global':'Assigné' ?></small></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="save_dev_apps" class="btn btn-green">Enregistrer</button>
                    <a href="?v=<?= $view ?>" class="btn btn-gray">Annuler</a>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel-card">
            <div class="panel-head">
                <h3>Derniers Abonnés (<?= $totalDev ?>)</h3>
                <a href="?v=activate" class="btn btn-s">+ Activer un Code</a>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width:11%">Status</th>
                            <th style="width:13%">Code</th>
                            <th style="width:18%">Client</th>
                            <th style="width:20%">Box / Modèle</th>
                            <?php if ($isSuper): ?><th style="width:14%">Revendeur</th><?php endif; ?>
                            <th style="width:12%">Expire le</th>
                            <th style="width:18%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($devices)): ?>
                            <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:26px">Aucun abonné enregistré.</td></tr>
                        <?php else: ?>
                            <?php foreach ($devices as $d):
                                $exp = $d['expires_at'];
                                $isExp = ($exp !== '' && $exp < $today);
                            ?>
                            <tr>
                                <td>
                                    <?php if (!$d['paired']): ?><span class="badge b-wait">● Attente</span>
                                    <?php elseif (!$d['active']): ?><span class="badge b-exp">● Off</span>
                                    <?php elseif ($isExp): ?><span class="badge b-exp">● Expiré</span>
                                    <?php else: ?><span class="badge b-actif">● Actif</span><?php endif; ?>
                                </td>
                                <td><span class="code-tag"><?= htmlspecialchars($d['code']) ?></span></td>
                                <td><b><?= htmlspecialchars($d['client_name'] ?: '—') ?></b></td>
                                <td><?= htmlspecialchars($d['model'] ?: 'Android') ?></td>
                                <?php if ($isSuper): ?><td><span class="badge" style="background:#1e293b;color:var(--orange)"><?= htmlspecialchars($d['reseller_name'] ?: 'AZ SAT') ?></span></td><?php endif; ?>
                                <td><b><?= $exp ? htmlspecialchars($exp) : 'Illimité' ?></b></td>
                                <td>
                                    <a href="?v=<?= $view ?>&dev_apps=<?= urlencode($d['uid']) ?>" class="btn btn-s" title="Apps">📱</a>
                                    <?php if (!$d['paired']): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="code" value="<?= htmlspecialchars($d['code']) ?>">
                                            <input type="hidden" name="months" value="12">
                                            <button type="submit" name="pair_device" class="btn btn-s btn-green">1 An</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="renew_uid" value="<?= htmlspecialchars($d['uid']) ?>">
                                            <input type="hidden" name="add_months" value="12">
                                            <button type="submit" class="btn btn-s btn-gray">+1An</button>
                                        </form>
                                        <a href="?v=<?= $view ?>&toggle_uid=<?= urlencode($d['uid']) ?>" class="btn btn-s btn-gray"><?= $d['active']?'Off':'On' ?></a>
                                    <?php endif; ?>
                                    <a href="?v=<?= $view ?>&del_uid=<?= urlencode($d['uid']) ?>" class="btn btn-s btn-red" onclick="return confirm('Supprimer ?')">🗑️</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. REVENDEURS (خاص بك أنت كـ Super Admin لبيع النظام لمحلات أخرى) -->
        <?php elseif ($view === 'resellers' && $isSuper): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card">
                <div class="panel-head"><h3>🏬 Créer un Nouveau Revendeur (محل جديد)</h3></div>
                <div class="panel-body">
                    <form method="post" enctype="multipart/form-data">
                        <label>Nom de la Boutique (اسم المحل) :</label>
                        <input type="text" name="res_shop" placeholder="Ex: Electro Star" required>

                        <label>Nom d'utilisateur (اسم الدخول) :</label>
                        <input type="text" name="res_user" placeholder="Ex: electrostar" required>

                        <label>Mot de passe (كلمة المرور) :</label>
                        <input type="password" name="res_pass" placeholder="Mot de passe du revendeur" required>

                        <label>WhatsApp Boutique (Numéro) :</label>
                        <input type="text" name="res_wa" placeholder="Ex: 21698123456" required>

                        <label>Logo de la Boutique :</label>
                        <input type="file" name="res_logo" accept="image/*">

                        <label>Nombre de boîtiers max (الحد الأقصى للأجهزة) :</label>
                        <input type="number" name="res_max" value="100">

                        <button type="submit" name="add_reseller" class="btn btn-green" style="width:100%;padding:13px">Créer le Compte Revendeur</button>
                    </form>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-head"><h3>Liste de vos Revendeurs (المحلات المشتركة عندك)</h3></div>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Logo</th><th>Boutique</th><th>Identifiant</th><th>WhatsApp</th><th>Date</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php if (empty($resellers)): ?>
                                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">Aucun revendeur créé pour le moment.</td></tr>
                            <?php else: ?>
                                <?php foreach ($resellers as $r): ?>
                                <tr>
                                    <td><?php if ($r['shop_logo']): ?><img src="<?= htmlspecialchars($r['shop_logo']) ?>" width="34" height="34" style="border-radius:50%;object-fit:cover"><?php else: ?>🏬<?php endif; ?></td>
                                    <td><b><?= htmlspecialchars($r['shop_name']) ?></b></td>
                                    <td><code><?= htmlspecialchars($r['username']) ?></code></td>
                                    <td><?= htmlspecialchars($r['shop_whatsapp']) ?></td>
                                    <td><small style="color:var(--muted)"><?= htmlspecialchars($r['created_at']) ?></small></td>
                                    <td><a href="?v=resellers&del_reseller=<?= (int)$r['id'] ?>" class="btn btn-s btn-red" onclick="return confirm('Supprimer ce revendeur ?')">Supprimer</a></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3. ACTIVER UN CODE -->
        <?php elseif ($view === 'activate'): ?>
        <div class="panel-card" style="max-width:540px;margin:0 auto">
            <div class="panel-head"><h3>⚡ Activer un Nouveau Boîtier</h3></div>
            <div class="panel-body">
                <form method="post">
                    <label>Code affiché sur la Télé (5 caractères) :</label>
                    <input type="text" name="code" placeholder="Ex: CCHWN" required style="font-size:1.4rem;font-weight:900;letter-spacing:4px;text-transform:uppercase;text-align:center">
                    
                    <label>Nom du Client (optionnel) :</label>
                    <input type="text" name="client_name" placeholder="Ex: Mohamed Salah">

                    <?php if ($isSuper && !empty($resellers)): ?>
                    <label>Assigner à un Revendeur (تابع لمحل) :</label>
                    <select name="target_reseller">
                        <option value="0">AZ SAT CONNECT (Mon Magasin)</option>
                        <?php foreach ($resellers as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['shop_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <label>Durée d'abonnement :</label>
                    <select name="months">
                        <option value="1">1 Mois</option>
                        <option value="3">3 Mois</option>
                        <option value="6">6 Mois</option>
                        <option value="12" selected>12 Mois (1 An)</option>
                        <option value="24">24 Mois (2 Ans)</option>
                        <option value="0">Illimité</option>
                    </select>
                    
                    <button type="submit" name="pair_device" class="btn btn-green" style="width:100%;padding:14px;font-size:1rem">Activer le boîtier maintenant</button>
                </form>
            </div>
        </div>

        <!-- 4. GESTION DES APPLICATIONS -->
        <?php elseif ($view === 'apps'): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card">
                <div class="panel-head"><h3>➕ Ajouter une Application</h3></div>
                <div class="panel-body">
                    <div style="background:rgba(59,130,246,.08);border:1px dashed var(--blue);border-radius:10px;padding:12px;margin-bottom:14px">
                        <label style="color:var(--blue)">⚡ Lecture automatique depuis un APK :</label>
                        <input type="file" id="apkInspector" accept=".apk" style="margin-bottom:4px">
                        <div id="apkScanMsg" style="font-size:.8rem;color:var(--green)"></div>
                        <img id="iconPreview" style="display:none;width:44px;height:44px;border-radius:10px;margin-top:6px;object-fit:cover;border:1px solid var(--blue)">
                    </div>

                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="auto_icon_b64" id="autoIconB64">
                        <label>Nom :</label><input type="text" name="app_name" id="fName" placeholder="Ex: Avatar Pro" required>
                        <label>Package :</label><input type="text" name="app_pkg" id="fPkg" placeholder="Ex: com.avatar.iptv" required>
                        <label>Version :</label><input type="number" name="app_ver" id="fVer" value="1" required>
                        <label>Lien APK direct :</label><input type="url" name="apk_url" placeholder="https://github.com/.../app.apk">
                        <label>Visibilité :</label>
                        <select name="is_global">
                            <option value="0">Client par Client 🎯 (via bouton Apps ☑️)</option>
                            <option value="1">Global 🌐 (apparaît chez tous les clients)</option>
                        </select>
                        <button type="submit" name="add_app" class="btn" style="width:100%;padding:13px">Enregistrer l'application</button>
                    </form>
                    <script src="https://cdn.jsdelivr.net/npm/app-info-parser@1.1.5/dist/app-info-parser.min.js"></script>
                    <script>
                    document.getElementById('apkInspector').addEventListener('change', async function(e) {
                        var file = e.target.files[0]; if (!file) return;
                        var msg = document.getElementById('apkScanMsg'); msg.style.color = '#38bdf8'; msg.textContent = '⏳ Analyse de l’APK...';
                        try {
                            var parser = new AppInfoParser(file); var info = await parser.parse();
                            var lbl = info.application && info.application.label; if (Array.isArray(lbl)) lbl = lbl[0];
                            document.getElementById('fName').value = lbl || file.name.replace(/\.apk$/i, '');
                            document.getElementById('fPkg').value = info.package || '';
                            document.getElementById('fVer').value = parseInt(info.versionCode, 10) || 1;
                            if (info.icon && info.icon.indexOf('data:image/') === 0) {
                                document.getElementById('autoIconB64').value = info.icon;
                                var img = document.getElementById('iconPreview'); img.src = info.icon; img.style.display = 'block';
                            }
                            msg.style.color = '#10b981'; msg.textContent = '✅ Analyse réussie !';
                        } catch(err) { msg.style.color = '#ef4444'; msg.textContent = 'Remplissage manuel nécessaire.'; }
                    });
                    </script>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-head"><h3>Bibliothèque des Applications (<?= $appsCount ?>)</h3></div>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Icône</th><th>Nom</th><th>Package</th><th>Type</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($apps as $a): ?>
                            <tr>
                                <td><?php if ($a['icon']): ?><img src="<?= htmlspecialchars($a['icon']) ?>" width="32" height="32" style="border-radius:50%;object-fit:cover"><?php else: ?>📺<?php endif; ?></td>
                                <td><b><?= htmlspecialchars($a['name']) ?></b></td>
                                <td><code><?= htmlspecialchars($a['package']) ?></code></td>
                                <td><a href="?v=apps&toggle_global=<?= (int)$a['id'] ?>" class="btn btn-s <?= $a['is_global']?'btn-green':'btn-gray' ?>"><?= $a['is_global']?'Global 🌐':'Client 🎯' ?></a></td>
                                <td><a href="?v=apps&del_app=<?= (int)$a['id'] ?>" class="btn btn-s btn-red" onclick="return confirm('Supprimer ?')">🗑️</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 5. MESSAGES -->
        <?php elseif ($view === 'messages'): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card">
                <div class="panel-head"><h3>✉️ Diffuser un Message</h3></div>
                <div class="panel-body">
                    <form method="post">
                        <label>Destinataire :</label>
                        <select name="target_uid">
                            <option value="">Tous les abonnés</option>
                            <?php foreach ($devices as $d): ?>
                                <option value="<?= htmlspecialchars($d['code']) ?>"><?= htmlspecialchars($d['code'] . ($d['client_name'] ? ' - ' . $d['client_name'] : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label>Message :</label>
                        <textarea name="msg_body" rows="4" placeholder="Ex: Bonjour, renouvellement d'abonnement..." required></textarea>
                        <button type="submit" name="send_msg" class="btn" style="width:100%;padding:13px">Diffuser le Message</button>
                    </form>
                </div>
            </div>
            <div class="panel-card">
                <div class="panel-head"><h3>Historique des Messages</h3></div>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Date</th><th>Cible</th><th>Message</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($msgs as $m): ?>
                            <tr>
                                <td><small style="color:var(--muted)"><?= htmlspecialchars($m['created_at']) ?></small></td>
                                <td><span class="badge b-actif"><?= $m['target_uid']?htmlspecialchars($m['target_uid']):'Tous' ?></span></td>
                                <td style="white-space:normal"><?= htmlspecialchars($m['body']) ?></td>
                                <td><a href="?v=messages&del_msg=<?= (int)$m['id'] ?>" class="btn btn-s btn-red">🗑️</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 6. PARAMETRES BOUTIQUE -->
        <?php elseif ($view === 'settings'): ?>
        <div class="panel-card" style="max-width:620px;margin:0 auto">
            <div class="panel-head"><h3>⚙️ <?= $isSuper ? 'Paramètres Boutique Principale & MyTV' : 'Mon Logo & Nom de Boutique' ?></h3></div>
            <div class="panel-body">
                <form method="post" enctype="multipart/form-data">
                    <label>Nom de la Boutique :</label>
                    <input type="text" name="shop_name" value="<?= htmlspecialchars($set['shop_name']) ?>" required>
                    <label>WhatsApp (Ex: 21626117917) :</label>
                    <input type="text" name="shop_whatsapp" value="<?= htmlspecialchars($set['shop_whatsapp']) ?>" required>
                    <label>Message de bienvenue :</label>
                    <input type="text" name="shop_welcome" value="<?= htmlspecialchars($set['shop_welcome']) ?>">
                    <label>Changer le Logo :</label>
                    <input type="file" name="logo_file" accept="image/*">

                    <?php if ($isSuper): ?>
                    <hr style="border:0;border-top:1px solid var(--border);margin:18px 0">
                    <label>Version Code MyTV :</label>
                    <input type="number" name="mytv_ver_code" value="<?= htmlspecialchars($set['mytv_ver_code']) ?>">
                    <label>Version Name :</label>
                    <input type="text" name="mytv_ver_name" value="<?= htmlspecialchars($set['mytv_ver_name']) ?>">
                    <label>Lien APK Mise à jour MyTV :</label>
                    <input type="url" name="mytv_apk" value="<?= htmlspecialchars($set['mytv_apk']) ?>">
                    <label>Notes de mise à jour :</label>
                    <input type="text" name="mytv_notes" value="<?= htmlspecialchars($set['mytv_notes']) ?>">
                    <?php endif; ?>

                    <button type="submit" name="save_settings" class="btn btn-green" style="width:100%;padding:14px">Enregistrer les Modifications</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>

</body>
</html>
