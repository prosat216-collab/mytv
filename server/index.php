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
    if (strlen($p) < 4) $loginErr = 'Mot de passe trop court.';
    else {
        file_put_contents($adminFile, json_encode(['hash' => password_hash($p, PASSWORD_DEFAULT)]));
        $_SESSION['is_super_admin'] = true; $_SESSION['reseller_id'] = 0; $_SESSION['admin_user'] = 'Super Admin';
        header('Location: index.php'); exit;
    }
}

if (isset($_POST['login_user']) && $hasPass) {
    $u = trim((string)$_POST['username']); $p = trim((string)$_POST['pass']);
    $adm = json_decode(file_get_contents($adminFile), true);
    if ($u === 'admin' && $adm && password_verify($p, $adm['hash'] ?? '')) {
        $_SESSION['is_super_admin'] = true; $_SESSION['reseller_id'] = 0; $_SESSION['admin_user'] = 'Super Admin';
        header('Location: index.php'); exit;
    } else {
        $st = $pdo->prepare("SELECT * FROM resellers WHERE username = ?"); $st->execute([$u]);
        $res = $st->fetch();
        if ($res && password_verify($p, $res['password'])) {
            $_SESSION['is_super_admin'] = false; $_SESSION['reseller_id'] = (int)$res['id']; $_SESSION['admin_user'] = $res['shop_name'];
            header('Location: index.php'); exit;
        } else $loginErr = 'Identifiants incorrects !';
    }
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

if (empty($_SESSION['is_super_admin']) && empty($_SESSION['reseller_id'])):
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CONNECT TV - Connexion</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d1117;color:#f0f6fc;font-family:-apple-system,sans-serif;padding:20px}
.box{width:100%;max-width:380px;background:#161b22;border:1px solid #30363d;border-radius:14px;padding:30px;text-align:center}
.tag{display:inline-block;background:#1f6feb;color:#fff;padding:4px 12px;border-radius:6px;font-weight:900;font-size:.82rem;margin-bottom:10px}
h1{margin:0 0 6px;font-size:1.6rem;color:#58a6ff}
input{width:100%;padding:13px;border-radius:8px;border:1px solid #30363d;background:#0d1117;color:#fff;font-size:15px;box-sizing:border-box;margin-bottom:12px;text-align:center}
button{width:100%;padding:14px;border:0;border-radius:8px;background:#238636;color:#fff;font-weight:800;font-size:16px;cursor:pointer}
.err{background:rgba(248,81,73,.15);color:#f85149;padding:10px;border-radius:8px;margin-bottom:12px}
</style></head><body><div class="box">
<span class="tag">CONNECT TV</span><h1>BETA PANEL v2.5</h1><p style="color:#8b949e;font-size:.88rem;margin-bottom:20px">Administration & Revendeurs</p>
<?php if ($loginErr): ?><div class="err"><?= htmlspecialchars($loginErr) ?></div><?php endif; ?>
<?php if (!$hasPass): ?><form method="post"><input type="password" name="new_pass" placeholder="Mot de passe Super Admin" required><button name="setup_pass">Initialiser</button></form>
<?php else: ?><form method="post"><input type="text" name="username" placeholder="Utilisateur (ex: admin)" required autofocus><input type="password" name="pass" placeholder="Mot de passe" required><button name="login_user">Connexion</button></form><?php endif; ?>
</div></body></html>
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

$msg = ''; $err = ''; $view = $_GET['v'] ?? 'dashboard';

if ($isSuper && isset($_POST['add_reseller'])) {
    $u = trim((string)$_POST['res_user']); $p = trim((string)$_POST['res_pass']);
    $sname = trim((string)$_POST['res_shop']); $swa = normalize_wa((string)$_POST['res_wa']);
    $maxD = max(1, (int)$_POST['res_max']); $logo = save_upload('res_logo', ['png','jpg','jpeg','webp'], $uploadDir);
    if ($u && $p && $sname && $swa) {
        try {
            $pdo->prepare("INSERT INTO resellers (username,password,shop_name,shop_logo,shop_whatsapp,welcome_msg,max_devices,created_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$u, password_hash($p, PASSWORD_DEFAULT), $sname, $logo, $swa, "مرحباً بكم في $sname", $maxD, date('Y-m-d')]);
            $msg = "Revendeur ($sname) créé avec succès !";
        } catch (Exception $e) { $err = "Nom d'utilisateur déjà utilisé !"; }
    }
}
if ($isSuper && isset($_GET['del_reseller'])) { $pdo->prepare("DELETE FROM resellers WHERE id=?")->execute([(int)$_GET['del_reseller']]); header('Location: index.php?v=resellers'); exit; }

if (isset($_POST['pair_device'])) {
    $code = strtoupper(trim((string)$_POST['code'])); $cname = trim((string)($_POST['client_name'] ?? ''));
    $months = (int)($_POST['months'] ?? 12); $assignRes = $isSuper ? (int)($_POST['target_reseller'] ?? 0) : $resId;
    $st = $pdo->prepare("SELECT * FROM devices WHERE code=?"); $st->execute([$code]); $dev = $st->fetch();
    if (!$dev) $err = "Code ($code) introuvable sur le boîtier !";
    else {
        $exp = $months > 0 ? date('Y-m-d', strtotime("+{$months} months")) : '';
        $pdo->prepare("UPDATE devices SET paired=1, active=1, client_name=?, expires_at=?, reseller_id=? WHERE uid=?")
            ->execute([$cname !== '' ? $cname : $dev['client_name'], $exp, $assignRes, $dev['uid']]);
        $msg = "Appareil ($code) activé !";
    }
}

if (isset($_POST['save_dev_apps'])) {
    $duid = (string)$_POST['dev_uid']; $chosen = $_POST['app_ids'] ?? [];
    $pdo->prepare("DELETE FROM device_apps WHERE uid=?")->execute([$duid]);
    $ins = $pdo->prepare("INSERT OR IGNORE INTO device_apps (uid, app_id) VALUES (?,?)");
    foreach ($chosen as $aid) $ins->execute([$duid, (int)$aid]);
    $msg = "Applications enregistrées !";
}

if (isset($_POST['save_xtream'])) {
    $xuid = (string)$_POST['x_uid']; $xon = isset($_POST['x_on']) ? 1 : 0;
    $xurl = rtrim(trim((string)$_POST['x_url']), '/'); $xuser = trim((string)$_POST['x_user']); $xpass = trim((string)$_POST['x_pass']);
    $pdo->prepare("UPDATE devices SET xtream_on=?, xtream_url=?, xtream_user=?, xtream_pass=? WHERE uid=?")->execute([$xon, $xurl, $xuser, $xpass, $xuid]);
    $msg = "Xtream mis à jour pour cet abonné !";
}

if (isset($_POST['renew_uid'])) {
    $uid = (string)$_POST['renew_uid']; $add = (int)$_POST['add_months'];
    $st = $pdo->prepare("SELECT expires_at FROM devices WHERE uid=?"); $st->execute([$uid]);
    $cur = (string)$st->fetchColumn(); $base = ($cur !== '' && strtotime($cur) > time()) ? strtotime($cur) : time();
    $newExp = date('Y-m-d', strtotime("+{$add} months", $base));
    $pdo->prepare("UPDATE devices SET expires_at=?, active=1, paired=1 WHERE uid=?")->execute([$newExp, $uid]);
    $msg = "Prolongé jusqu'au $newExp !";
}

if (isset($_GET['toggle_uid'])) { $pdo->prepare("UPDATE devices SET active=1-active WHERE uid=?")->execute([$_GET['toggle_uid']]); header('Location: index.php?v=subscribers'); exit; }
if (isset($_GET['reset_hw'])) { $pdo->prepare("UPDATE devices SET hw_sig='' WHERE uid=?")->execute([$_GET['reset_hw']]); $msg = "Verrou matériel réinitialisé !"; }
if (isset($_GET['del_uid'])) { $pdo->prepare("DELETE FROM devices WHERE uid=?")->execute([$_GET['del_uid']]); header('Location: index.php?v=subscribers'); exit; }
if (isset($_GET['del_expired'])) {
    if ($isSuper) $pdo->prepare("DELETE FROM devices WHERE expires_at != '' AND expires_at < ?")->execute([date('Y-m-d')]);
    else $pdo->prepare("DELETE FROM devices WHERE reseller_id=? AND expires_at != '' AND expires_at < ?")->execute([$resId, date('Y-m-d')]);
    $msg = "Abonnés expirés supprimés !";
}

if (isset($_POST['add_app'])) {
    $name = trim((string)$_POST['app_name']); $pkg = trim((string)$_POST['app_pkg']); $ver = max(1, (int)($_POST['app_ver'] ?? 1));
    $isGlobal = (int)($_POST['is_global'] ?? 1); $isLocked = isset($_POST['is_locked']) ? 1 : 0;
    $iconUrl = save_upload('icon_file', ['png','jpg','jpeg','webp'], $uploadDir) ?: trim((string)($_POST['icon_url'] ?? ''));
    if ($iconUrl === '' && !empty($_POST['auto_icon_b64']) && preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/i', (string)$_POST['auto_icon_b64'], $m)) {
        $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]); $bin = base64_decode($m[2]);
        if ($bin !== false && strlen($bin) < 2097152) { $fn = uniqid('icon_', true) . '.' . $ext; if (file_put_contents($uploadDir . '/' . $fn, $bin)) $iconUrl = base_url() . '/uploads/' . $fn; }
    }
    $apkUrl = save_upload('apk_file', ['apk'], $uploadDir) ?: trim((string)($_POST['apk_url'] ?? ''));
    if ($name && $pkg) {
        $pdo->prepare("INSERT INTO apps (name,package,version_code,apk,icon,is_global,is_locked,reseller_id) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$name, $pkg, $ver, $apkUrl, $iconUrl, $isGlobal, $isLocked, $resId]);
        $msg = "Application ($name) ajoutée !";
    }
}

if (isset($_GET['toggle_global'])) { $pdo->prepare("UPDATE apps SET is_global=1-is_global WHERE id=?")->execute([(int)$_GET['toggle_global']]); header('Location: index.php?v=apps'); exit; }
if (isset($_GET['toggle_lock'])) { $pdo->prepare("UPDATE apps SET is_locked=1-is_locked WHERE id=?")->execute([(int)$_GET['toggle_lock']]); header('Location: index.php?v=apps'); exit; }
if (isset($_GET['del_app'])) { $pdo->prepare("DELETE FROM apps WHERE id=?")->execute([(int)$_GET['del_app']]); header('Location: index.php?v=apps'); exit; }

if (isset($_POST['send_msg'])) {
    $body = trim((string)$_POST['msg_body']); $target = strtoupper(trim((string)($_POST['target_uid'] ?? '')));
    if ($body !== '') { $pdo->prepare("INSERT INTO messages (body,target_uid,created_at,reseller_id) VALUES (?,?,?,?)")->execute([$body, $target, date('Y-m-d H:i'), $resId]); $msg = "Message diffusé !"; }
}
if (isset($_GET['del_msg'])) { $pdo->prepare("DELETE FROM messages WHERE id=?")->execute([(int)$_GET['del_msg']]); header('Location: index.php?v=messages'); exit; }

if (isset($_POST['add_photo'])) {
    $url = save_upload('photo_file', ['png','jpg','jpeg','webp'], $uploadDir) ?: trim((string)($_POST['photo_url'] ?? ''));
    if ($url !== '') { $pdo->prepare("INSERT INTO store (url,thumb,created_at,reseller_id) VALUES (?,?,?,?)")->execute([$url, $url, date('Y-m-d H:i'), $resId]); $msg = "Photo ajoutée à la boutique !"; }
}
if (isset($_GET['del_photo'])) { $pdo->prepare("DELETE FROM store WHERE id=?")->execute([(int)$_GET['del_photo']]); header('Location: index.php?v=store'); exit; }

if (isset($_POST['save_settings'])) {
    $logo = save_upload('logo_file', ['png','jpg','jpeg','webp'], $uploadDir);
    if ($isSuper) {
        $pairs = [
            'shop_name' => trim((string)$_POST['shop_name']), 'shop_whatsapp' => normalize_wa((string)$_POST['shop_whatsapp']),
            'shop_welcome' => trim((string)$_POST['shop_welcome']), 'shop_logo' => $logo !== '' ? $logo : trim((string)$_POST['shop_logo']),
            'mytv_ver_code' => (string)max(1, (int)$_POST['mytv_ver_code']), 'mytv_ver_name' => trim((string)$_POST['mytv_ver_name']),
            'mytv_apk' => trim((string)$_POST['mytv_apk']), 'mytv_notes' => trim((string)$_POST['mytv_notes']),
        ];
        $up = $pdo->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES (?,?)");
        foreach ($pairs as $k => $v) $up->execute([$k, $v]);
    } else {
        $sname = trim((string)$_POST['shop_name']); $swa = normalize_wa((string)$_POST['shop_whatsapp']); $swel = trim((string)$_POST['shop_welcome']);
        $sql = "UPDATE resellers SET shop_name=?, shop_whatsapp=?, welcome_msg=?" . ($logo ? ", shop_logo=?" : "") . " WHERE id=?";
        $pdo->prepare($sql)->execute($logo ? [$sname, $swa, $swel, $logo, $resId] : [$sname, $swa, $swel, $resId]);
    }
    $msg = "Paramètres enregistrés !";
}

$set = get_settings($pdo);
$resellers = $pdo->query("SELECT * FROM resellers ORDER BY id DESC")->fetchAll();
if ($isSuper) {
    $devices = $pdo->query("SELECT d.*, r.shop_name as reseller_name FROM devices d LEFT JOIN resellers r ON r.id=d.reseller_id ORDER BY d.paired ASC, d.last_seen DESC")->fetchAll();
    $apps = $pdo->query("SELECT * FROM apps ORDER BY id DESC")->fetchAll();
    $msgs = $pdo->query("SELECT * FROM messages ORDER BY id DESC")->fetchAll();
    $photos = $pdo->query("SELECT * FROM store ORDER BY id DESC")->fetchAll();
} else {
    $stD = $pdo->prepare("SELECT * FROM devices WHERE reseller_id=? ORDER BY paired ASC, last_seen DESC"); $stD->execute([$resId]); $devices = $stD->fetchAll();
    $stA = $pdo->prepare("SELECT * FROM apps WHERE reseller_id=? OR is_global=1 ORDER BY id DESC"); $stA->execute([$resId]); $apps = $stA->fetchAll();
    $stM = $pdo->prepare("SELECT * FROM messages WHERE reseller_id=? ORDER BY id DESC"); $stM->execute([$resId]); $msgs = $stM->fetchAll();
    $stS = $pdo->prepare("SELECT * FROM store WHERE reseller_id=? ORDER BY id DESC"); $stS->execute([$resId]); $photos = $stS->fetchAll();
}

$totalDev = count($devices); $activeDev = 0; $expiredDev = 0; $pendingDev = 0; $today = date('Y-m-d');
foreach ($devices as $d) {
    if (!$d['paired'] || !$d['active']) $pendingDev++;
    elseif ($d['expires_at'] !== '' && $d['expires_at'] < $today) $expiredDev++;
    else $activeDev++;
}
$appsCount = count($apps);

$editDev = null; $assignedIds = [];
if (!empty($_GET['dev_apps'])) {
    $st = $pdo->prepare("SELECT * FROM devices WHERE uid=?"); $st->execute([$_GET['dev_apps']]); $editDev = $st->fetch();
    if ($editDev) { $stA = $pdo->prepare("SELECT app_id FROM device_apps WHERE uid=?"); $stA->execute([$editDev['uid']]); $assignedIds = array_map('intval', array_column($stA->fetchAll(), 'app_id')); }
}
$editXtream = null;
if (!empty($_GET['dev_xtream'])) {
    $st = $pdo->prepare("SELECT * FROM devices WHERE uid=?"); $st->execute([$_GET['dev_xtream']]); $editXtream = $st->fetch();
}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CONNECT TV Panel - <?= htmlspecialchars($_SESSION['admin_user'] ?? $set['shop_name']) ?></title>
<style>
:root{--bg:#0b101b;--sidebar:#060a12;--card:#131b2e;--border:#1e293b;--text:#f8fafc;--muted:#94a3b8;--blue:#3b82f6;--green:#10b981;--red:#ef4444;--orange:#f97316;--purple:#a855f7}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,sans-serif;display:flex;min-height:100vh}
aside{width:235px;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0}
.brand{padding:20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border)}
.brand-icon{width:36px;height:36px;background:var(--blue);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
.brand-text b{display:block;font-size:1rem;color:#fff}.brand-text span{font-size:.72rem;color:var(--blue);font-weight:700}
nav{padding:14px 10px;flex:1;display:flex;flex-direction:column;gap:4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:.9rem;font-weight:600}
.nav-item:hover{background:rgba(255,255,255,.05);color:#fff}.nav-item.active{background:var(--blue);color:#fff}.nav-item .icon{font-size:1.1rem;width:20px}
main{flex:1;display:flex;flex-direction:column;min-width:0;overflow-y:auto}
header{padding:16px 26px;background:rgba(6,10,18,.8);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
header h2{margin:0;font-size:1.25rem}
.user-btn{background:var(--card);border:1px solid var(--border);padding:8px 14px;border-radius:8px;color:#fff;text-decoration:none;font-size:.85rem;font-weight:600}
.content{padding:22px 26px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-weight:600}.alert.ok{background:rgba(16,185,129,.15);border:1px solid var(--green);color:var(--green)}.alert.err{background:rgba(239,68,68,.15);border:1px solid var(--red);color:var(--red)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:14px;margin-bottom:22px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:15px 18px;display:flex;align-items:center;gap:14px}
.stat-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.stat-icon.blue{background:var(--blue)}.stat-icon.green{background:var(--green)}.stat-icon.red{background:var(--red)}.stat-icon.orange{background:var(--orange)}.stat-icon.purple{background:var(--purple)}
.stat-info b{display:block;font-size:1.5rem;font-weight:800}.stat-info span{font-size:.78rem;color:var(--muted);font-weight:600}
.panel-card{background:var(--card);border:1px solid var(--border);border-radius:12px;margin-bottom:20px;overflow:hidden}
.panel-head{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.panel-head h3{margin:0;font-size:1.05rem}.panel-body{padding:20px}
.table-responsive{width:100%;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.86rem}
th{background:rgba(6,10,18,.6);color:var(--muted);font-size:.75rem;font-weight:700;padding:10px 12px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
td{padding:10px 12px;border-bottom:1px solid rgba(30,41,59,.5);white-space:nowrap}
.badge{padding:3px 8px;border-radius:99px;font-size:.72rem;font-weight:800;display:inline-block}
.b-actif{background:rgba(16,185,129,.15);color:var(--green)}.b-exp{background:rgba(239,68,68,.15);color:var(--red)}.b-wait{background:rgba(249,115,22,.15);color:var(--orange)}
.code-tag{font-family:monospace;font-size:1rem;font-weight:900;letter-spacing:1px;color:var(--blue)}
label{display:block;font-size:.82rem;color:var(--muted);font-weight:600;margin-bottom:5px}
input,select,textarea{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:#0b101b;color:#fff;font-size:.92rem;margin-bottom:12px;box-sizing:border-box}
.btn{background:var(--blue);color:#fff;border:0;padding:9px 15px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.86rem;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.btn-s{padding:5px 8px;font-size:.76rem;border-radius:6px}.btn-green{background:var(--green)}.btn-red{background:var(--red)}.btn-gray{background:#1e293b}
.chk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin:12px 0 18px}
.chk-item{display:flex;align-items:center;gap:8px;background:#0b101b;border:1px solid var(--border);padding:8px 12px;border-radius:8px;cursor:pointer}.chk-item input{width:16px;height:16px;margin:0}
@media(max-width:850px){body{flex-direction:column}aside{width:100%;border-right:0;border-bottom:1px solid var(--border)}nav{flex-direction:row;flex-wrap:wrap}}
</style></head><body>
<aside>
    <div class="brand"><div class="brand-icon">📺</div><div class="brand-text"><b>CONNECT TV</b><span><?= $isSuper ? 'SUPER ADMIN 👑' : 'REVENDEUR' ?></span></div></div>
    <nav>
        <a href="?v=dashboard" class="nav-item <?= $view==='dashboard'?'active':'' ?>"><span class="icon">📊</span> Dashboard</a>
        <a href="?v=activate" class="nav-item <?= $view==='activate'?'active':'' ?>"><span class="icon">⚡</span> Activer Appareil</a>
        <a href="?v=subscribers" class="nav-item <?= $view==='subscribers'?'active':'' ?>"><span class="icon">👥</span> Abonnés (<?= $totalDev ?>)</a>
        <?php if ($isSuper): ?><a href="?v=resellers" class="nav-item <?= $view==='resellers'?'active':'' ?>" style="color:var(--orange)"><span class="icon">🏬</span> Revendeurs (<?= count($resellers) ?>)</a><?php endif; ?>
        <a href="?v=apps" class="nav-item <?= $view==='apps'?'active':'' ?>"><span class="icon">📦</span> Bibliothèque APK</a>
        <a href="?v=store" class="nav-item <?= $view==='store'?'active':'' ?>"><span class="icon">🛍️</span> Boutique (<?= count($photos) ?>)</a>
        <a href="?v=messages" class="nav-item <?= $view==='messages'?'active':'' ?>"><span class="icon">✉️</span> Messages</a>
        <a href="?v=settings" class="nav-item <?= $view==='settings'?'active':'' ?>"><span class="icon">⚙️</span> Paramètres</a>
    </nav>
</aside>
<main>
    <header><h2><?= strtoupper($view) ?> — <?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?></h2><a href="?logout=1" class="user-btn">Déconnexion 🚪</a></header>
    <div class="content">
        <?php if ($msg): ?><div class="alert ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon blue">👥</div><div class="stat-info"><b><?= $totalDev ?></b><span>Total Abonnés</span></div></div>
            <div class="stat-card"><div class="stat-icon green">🟢</div><div class="stat-info"><b><?= $activeDev ?></b><span>Actifs</span></div></div>
            <div class="stat-card"><div class="stat-icon red">🔴</div><div class="stat-info"><b><?= $expiredDev ?></b><span>Expirés</span></div></div>
            <div class="stat-card"><div class="stat-icon orange">⏳</div><div class="stat-info"><b><?= $pendingDev ?></b><span>En attente / Off</span></div></div>
            <div class="stat-card"><div class="stat-icon purple">📦</div><div class="stat-info"><b><?= $appsCount ?></b><span>APKs</span></div></div>
        </div>

        <?php if ($view === 'dashboard' || $view === 'subscribers'): ?>
        <?php if ($editDev): ?>
        <div class="panel-card" style="border-color:var(--blue)"><div class="panel-head"><h3>📱 Apps pour <span class="code-tag"><?= htmlspecialchars($editDev['code']) ?></span></h3></div><div class="panel-body"><form method="post"><input type="hidden" name="dev_uid" value="<?= htmlspecialchars($editDev['uid']) ?>"><div class="chk-grid">
        <?php foreach ($apps as $a): $isG = ((int)$a['is_global'] === 1); ?>
        <label class="chk-item"><?php if ($isG): ?><input type="checkbox" checked disabled><?php else: ?><input type="checkbox" name="app_ids[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $assignedIds, true)?'checked':'' ?>><?php endif; ?>
        <?php if ($a['icon']): ?><img src="<?= htmlspecialchars($a['icon']) ?>" width="26" height="26" style="border-radius:6px;object-fit:cover"><?php else: ?>📺<?php endif; ?>
        <div><b><?= htmlspecialchars($a['name']) ?></b><br><small style="color:var(--muted)"><?= $isG?'Global':'Assigné' ?></small></div></label>
        <?php endforeach; ?>
        </div><button type="submit" name="save_dev_apps" class="btn btn-green">Enregistrer</button> <a href="?v=<?= $view ?>" class="btn btn-gray">Annuler</a></form></div></div>
        <?php endif; ?>

        <?php if ($editXtream): ?>
        <div class="panel-card" style="border-color:var(--green)"><div class="panel-head"><h3>📺 Lecteur Xtream : <span class="code-tag"><?= htmlspecialchars($editXtream['code']) ?></span></h3></div><div class="panel-body"><form method="post">
        <input type="hidden" name="x_uid" value="<?= htmlspecialchars($editXtream['uid']) ?>">
        <label style="display:flex;align-items:center;gap:10px;color:#fff;font-size:1rem;margin-bottom:14px;cursor:pointer"><input type="checkbox" name="x_on" value="1" <?= !empty($editXtream['xtream_on'])?'checked':'' ?> style="width:20px;height:20px;margin:0"> Activer le Lecteur Xtream intégré (محمي داخل التطبيق فقط)</label>
        <label>URL Serveur (Ex: http://domain.com:8080) :</label><input type="url" name="x_url" value="<?= htmlspecialchars($editXtream['xtream_url'] ?? '') ?>" placeholder="http://domain.com:8080">
        <label>Username :</label><input type="text" name="x_user" value="<?= htmlspecialchars($editXtream['xtream_user'] ?? '') ?>">
        <label>Password :</label><input type="text" name="x_pass" value="<?= htmlspecialchars($editXtream['xtream_pass'] ?? '') ?>">
        <button type="submit" name="save_xtream" class="btn btn-green">Enregistrer Xtream</button> <a href="?v=<?= $view ?>" class="btn btn-gray">Annuler</a>
        </form></div></div>
        <?php endif; ?>

        <div class="panel-card"><div class="panel-head"><h3>Liste des Abonnés (<?= $totalDev ?>)</h3><div style="display:flex;gap:8px"><a href="?v=<?= $view ?>&del_expired=1" class="btn btn-s btn-red" onclick="return confirm('Supprimer tous les expirés ?')">🗑️ Supprimer Expirés</a><a href="?v=activate" class="btn btn-s">+ Activer</a></div></div>
        <div class="table-responsive"><table><thead><tr><th>Status</th><th>Code</th><th>Client</th><th>Box</th><?php if ($isSuper): ?><th>Revendeur</th><?php endif; ?><th>Expire le</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($devices as $d): $exp = $d['expires_at']; $isExp = ($exp !== '' && $exp < $today); ?>
        <tr>
            <td><?php if (!$d['paired']): ?><span class="badge b-wait">● Attente</span><?php elseif (!$d['active']): ?><span class="badge b-exp">● Bloqué</span><?php elseif ($isExp): ?><span class="badge b-exp">● Expiré</span><?php else: ?><span class="badge b-actif">● Actif</span><?php endif; ?></td>
            <td><span class="code-tag"><?= htmlspecialchars($d['code']) ?></span></td>
            <td><b><?= htmlspecialchars($d['client_name'] ?: '—') ?></b></td>
            <td><?= htmlspecialchars($d['model'] ?: 'Android') ?></td>
            <?php if ($isSuper): ?><td><span class="badge" style="background:#1e293b;color:var(--orange)"><?= htmlspecialchars($d['reseller_name'] ?: 'CONNECT TV') ?></span></td><?php endif; ?>
            <td><b><?= $exp ? htmlspecialchars($exp) : 'Illimité' ?></b></td>
            <td>
                <a href="?v=<?= $view ?>&dev_xtream=<?= urlencode($d['uid']) ?>" class="btn btn-s" style="background:<?= !empty($d['xtream_on'])?'var(--green)':'#334155' ?>">📺 Xtream</a>
                <a href="?v=<?= $view ?>&dev_apps=<?= urlencode($d['uid']) ?>" class="btn btn-s">📱 Apps</a>
                <?php if (!$d['paired']): ?><form method="post" style="display:inline"><input type="hidden" name="code" value="<?= htmlspecialchars($d['code']) ?>"><input type="hidden" name="months" value="12"><button type="submit" name="pair_device" class="btn btn-s btn-green">Activer</button></form>
                <?php else: ?><form method="post" style="display:inline"><input type="hidden" name="renew_uid" value="<?= urlencode($d['uid']) ?>"><input type="hidden" name="add_months" value="12"><button type="submit" class="btn btn-s btn-gray">+1An</button></form>
                <a href="?v=<?= $view ?>&toggle_uid=<?= urlencode($d['uid']) ?>" class="btn btn-s btn-gray"><?= $d['active']?'Bloquer':'Activer' ?></a><?php endif; ?>
                <?php if (!empty($d['hw_sig'])): ?><a href="?v=<?= $view ?>&reset_hw=<?= urlencode($d['uid']) ?>" class="btn btn-s btn-gray" title="Reset Anti-Clone">🔓 HW</a><?php endif; ?>
                <a href="?v=<?= $view ?>&del_uid=<?= urlencode($d['uid']) ?>" class="btn btn-s btn-red" onclick="return confirm('Supprimer cet abonné ?')">🗑️ Supprimer</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

        <?php elseif ($view === 'resellers' && $isSuper): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card"><div class="panel-head"><h3>🏬 Nouveau Revendeur</h3></div><div class="panel-body"><form method="post" enctype="multipart/form-data">
            <label>Nom Boutique :</label><input type="text" name="res_shop" required><label>Utilisateur :</label><input type="text" name="res_user" required>
            <label>Mot de passe :</label><input type="password" name="res_pass" required><label>WhatsApp :</label><input type="text" name="res_wa" required>
            <label>Logo :</label><input type="file" name="res_logo" accept="image/*"><label>Max Boîtiers :</label><input type="number" name="res_max" value="100">
            <button type="submit" name="add_reseller" class="btn btn-green" style="width:100%">Créer</button></form></div></div>
            <div class="panel-card"><div class="panel-head"><h3>Revendeurs</h3></div><div class="table-responsive"><table><thead><tr><th>Logo</th><th>Boutique</th><th>User</th><th>WhatsApp</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($resellers as $r): ?><tr><td><?php if ($r['shop_logo']): ?><img src="<?= htmlspecialchars($r['shop_logo']) ?>" width="32" height="32" style="border-radius:50%"><?php else: ?>🏬<?php endif; ?></td><td><b><?= htmlspecialchars($r['shop_name']) ?></b></td><td><code><?= htmlspecialchars($r['username']) ?></code></td><td><?= htmlspecialchars($r['shop_whatsapp']) ?></td><td><a href="?v=resellers&del_reseller=<?= (int)$r['id'] ?>" class="btn btn-s btn-red">🗑️ Supprimer</a></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
        </div>

        <?php elseif ($view === 'activate'): ?>
        <div class="panel-card" style="max-width:520px;margin:0 auto"><div class="panel-head"><h3>⚡ Activer un Boîtier</h3></div><div class="panel-body"><form method="post">
        <label>Code (5 caractères) :</label><input type="text" name="code" required style="font-size:1.4rem;font-weight:900;letter-spacing:4px;text-transform:uppercase;text-align:center">
        <label>Nom Client :</label><input type="text" name="client_name">
        <?php if ($isSuper && !empty($resellers)): ?><label>Revendeur :</label><select name="target_reseller"><option value="0">CONNECT TV</option><?php foreach ($resellers as $r): ?><option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['shop_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <label>Durée :</label><select name="months"><option value="1">1 Mois</option><option value="3">3 Mois</option><option value="6">6 Mois</option><option value="12" selected>1 An</option><option value="24">2 Ans</option><option value="0">Illimité</option></select>
        <button type="submit" name="pair_device" class="btn btn-green" style="width:100%">Activer</button></form></div></div>

        <?php elseif ($view === 'apps'): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card"><div class="panel-head"><h3>➕ Ajouter APK</h3></div><div class="panel-body">
                <div style="background:rgba(59,130,246,.08);border:1px dashed var(--blue);border-radius:10px;padding:10px;margin-bottom:12px">
                    <label style="color:var(--blue)">⚡ Lecture auto APK :</label><input type="file" id="apkInspector" accept=".apk" style="margin-bottom:4px">
                    <div id="apkScanMsg" style="font-size:.8rem;color:var(--green)"></div><img id="iconPreview" style="display:none;width:40px;height:40px;border-radius:8px;margin-top:6px">
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="auto_icon_b64" id="autoIconB64">
                    <label>Nom :</label><input type="text" name="app_name" id="fName" required>
                    <label>Package :</label><input type="text" name="app_pkg" id="fPkg" required>
                    <label>Version :</label><input type="number" name="app_ver" id="fVer" value="1" required>
                    <label>Icône (optionnel) :</label><input type="file" name="icon_file" accept="image/*">
                    <label>Lien APK :</label><input type="url" name="apk_url" placeholder="https://github.com/.../app.apk">
                    <label>Visibilité :</label><select name="is_global"><option value="0">Client 🎯</option><option value="1">Global 🌐</option></select>
                    <label style="display:flex;align-items:center;gap:8px;color:#fff;cursor:pointer"><input type="checkbox" name="is_locked" value="1" style="width:18px;height:18px;margin:0"> 🔐 Protéger par Code PIN (Contrôle Parental)</label>
                    <button type="submit" name="add_app" class="btn" style="width:100%">Enregistrer</button>
                </form>
                <script src="https://cdn.jsdelivr.net/npm/app-info-parser@1.1.5/dist/app-info-parser.min.js"></script>
                <script>document.getElementById('apkInspector').addEventListener('change',async function(e){var f=e.target.files[0];if(!f)return;var m=document.getElementById('apkScanMsg');m.textContent='⏳ Analyse...';try{var p=new AppInfoParser(f),i=await p.parse(),l=i.application&&i.application.label;if(Array.isArray(l))l=l[0];document.getElementById('fName').value=l||f.name.replace(/\.apk$/i,'');document.getElementById('fPkg').value=i.package||'';document.getElementById('fVer').value=parseInt(i.versionCode,10)||1;if(i.icon&&i.icon.indexOf('data:image/')===0){document.getElementById('autoIconB64').value=i.icon;var im=document.getElementById('iconPreview');im.src=i.icon;im.style.display='block';}m.textContent='✅ Extrait !';}catch(err){m.textContent='Remplissage manuel.';}});</script>
            </div></div>
            <div class="panel-card"><div class="panel-head"><h3>Bibliothèque APK (<?= $appsCount ?>)</h3></div><div class="table-responsive"><table><thead><tr><th>Icône</th><th>Nom</th><th>Package</th><th>Type</th><th>PIN</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($apps as $a): ?><tr>
                <td><?php if ($a['icon']): ?><img src="<?= htmlspecialchars($a['icon']) ?>" width="30" height="30" style="border-radius:50%"><?php else: ?>📺<?php endif; ?></td>
                <td><b><?= htmlspecialchars($a['name']) ?></b></td><td><code><?= htmlspecialchars($a['package']) ?></code></td>
                <td><a href="?v=apps&toggle_global=<?= (int)$a['id'] ?>" class="btn btn-s <?= $a['is_global']?'btn-green':'btn-gray' ?>"><?= $a['is_global']?'Global 🌐':'Client 🎯' ?></a></td>
                <td><a href="?v=apps&toggle_lock=<?= (int)$a['id'] ?>" class="btn btn-s <?= !empty($a['is_locked'])?'btn-red':'btn-gray' ?>"><?= !empty($a['is_locked'])?'🔐 PIN':'🔓 Libre' ?></a></td>
                <td><a href="?v=apps&del_app=<?= (int)$a['id'] ?>" class="btn btn-s btn-red">🗑️</a></td>
            </tr><?php endforeach; ?>
            </tbody></table></div></div>
        </div>

        <?php elseif ($view === 'store'): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card"><div class="panel-head"><h3>🛍️ Ajouter Photo Boutique</h3></div><div class="panel-body"><form method="post" enctype="multipart/form-data">
            <label>Image :</label><input type="file" name="photo_file" accept="image/*"><label>Ou lien :</label><input type="url" name="photo_url" placeholder="https://...">
            <button type="submit" name="add_photo" class="btn" style="width:100%">Ajouter</button></form></div></div>
            <div class="panel-card"><div class="panel-head"><h3>Catalogue Boutique (<?= count($photos) ?>)</h3></div><div class="panel-body"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px">
            <?php foreach ($photos as $p): ?><div style="background:#0b101b;border:1px solid var(--border);border-radius:10px;overflow:hidden;text-align:center;padding-bottom:8px"><img src="<?= htmlspecialchars($p['url']) ?>" style="width:100%;height:110px;object-fit:cover;margin-bottom:8px"><a href="?v=store&del_photo=<?= (int)$p['id'] ?>" class="btn btn-s btn-red">Supprimer</a></div><?php endforeach; ?>
            </div></div></div>
        </div>

        <?php elseif ($view === 'messages'): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card"><div class="panel-head"><h3>✉️ Envoyer Message</h3></div><div class="panel-body"><form method="post">
            <label>Cible :</label><select name="target_uid"><option value="">Tous</option><?php foreach ($devices as $d): ?><option value="<?= htmlspecialchars($d['code']) ?>"><?= htmlspecialchars($d['code']) ?></option><?php endforeach; ?></select>
            <label>Message :</label><textarea name="msg_body" rows="4" required></textarea><button type="submit" name="send_msg" class="btn" style="width:100%">Envoyer</button></form></div></div>
            <div class="panel-card"><div class="panel-head"><h3>Historique</h3></div><div class="table-responsive"><table><thead><tr><th>Date</th><th>Cible</th><th>Message</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($msgs as $m): ?><tr><td><?= htmlspecialchars($m['created_at']) ?></td><td><?= $m['target_uid']?htmlspecialchars($m['target_uid']):'Tous' ?></td><td style="white-space:normal"><?= htmlspecialchars($m['body']) ?></td><td><a href="?v=messages&del_msg=<?= (int)$m['id'] ?>" class="btn btn-s btn-red">🗑️</a></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
        </div>

        <?php elseif ($view === 'settings'): ?>
        <div class="panel-card" style="max-width:600px;margin:0 auto"><div class="panel-head"><h3>⚙️ Paramètres CONNECT TV</h3></div><div class="panel-body"><form method="post" enctype="multipart/form-data">
        <label>Nom Boutique :</label><input type="text" name="shop_name" value="<?= htmlspecialchars($set['shop_name']) ?>" required>
        <label>WhatsApp :</label><input type="text" name="shop_whatsapp" value="<?= htmlspecialchars($set['shop_whatsapp']) ?>" required>
        <label>Bienvenue :</label><input type="text" name="shop_welcome" value="<?= htmlspecialchars($set['shop_welcome']) ?>">
        <label>Logo :</label><input type="file" name="logo_file" accept="image/*"><input type="url" name="shop_logo" value="<?= htmlspecialchars($set['shop_logo']) ?>">
        <?php if ($isSuper): ?><hr style="border:0;border-top:1px solid var(--border);margin:14px 0">
        <label>Version Code (Auto-Update) :</label><input type="number" name="mytv_ver_code" value="<?= htmlspecialchars($set['mytv_ver_code']) ?>">
        <label>Version Name :</label><input type="text" name="mytv_ver_name" value="<?= htmlspecialchars($set['mytv_ver_name']) ?>">
        <label>Lien APK Mise à jour :</label><input type="url" name="mytv_apk" value="<?= htmlspecialchars($set['mytv_apk']) ?>">
        <label>Notes :</label><input type="text" name="mytv_notes" value="<?= htmlspecialchars($set['mytv_notes']) ?>"><?php endif; ?>
        <button type="submit" name="save_settings" class="btn btn-green" style="width:100%">Enregistrer</button></form></div></div>
        <?php endif; ?>
    </div>
</main></body></html>
