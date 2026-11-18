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
    if (strlen($p) < 4) $loginErr = 'اختر كلمة مرور من 4 أحرف على الأقل.';
    else {
        file_put_contents($adminFile, json_encode(['hash' => password_hash($p, PASSWORD_DEFAULT)]));
        $_SESSION['mytv_admin'] = true;
        header('Location: index.php'); exit;
    }
}

if (isset($_POST['login_pass']) && $hasPass) {
    $adm = json_decode(file_get_contents($adminFile), true);
    if ($adm && password_verify(trim((string)$_POST['pass']), $adm['hash'] ?? '')) {
        $_SESSION['mytv_admin'] = true;
        header('Location: index.php'); exit;
    } else $loginErr = 'Mot de passe incorrect!';
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

if (empty($_SESSION['mytv_admin'])):
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BETA Panel - AZ SAT CONNECT</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d1117;color:#f0f6fc;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:20px}
.box{width:100%;max-width:380px;background:#161b22;border:1px solid #30363d;border-radius:14px;padding:30px;text-align:center;box-shadow:0 12px 30px rgba(0,0,0,.6)}
.logo-tag{display:inline-block;background:#1f6feb;color:#fff;padding:4px 12px;border-radius:6px;font-weight:900;font-size:.85rem;margin-bottom:12px;letter-spacing:1px}
h1{margin:0 0 6px;font-size:1.6rem;color:#58a6ff}
p{color:#8b949e;font-size:.9rem;margin-bottom:24px}
input{width:100%;padding:14px;border-radius:8px;border:1px solid #30363d;background:#0d1117;color:#fff;font-size:16px;box-sizing:border-box;margin-bottom:14px;text-align:center}
button{width:100%;padding:14px;border:0;border-radius:8px;background:#238636;color:#fff;font-weight:800;font-size:16px;cursor:pointer;transition:.2s}
button:hover{background:#2ea043}
.err{background:rgba(248,81,73,.15);color:#f85149;padding:10px;border-radius:8px;margin-bottom:14px;font-size:.9rem}
</style>
</head>
<body>
<div class="box">
    <span class="logo-tag">BETA v2.0</span>
    <h1>AZ SAT CONNECT</h1>
    <p>Gestionnaire d'Abonnements & Box</p>
    <?php if ($loginErr): ?><div class="err"><?= htmlspecialchars($loginErr) ?></div><?php endif; ?>
    <?php if (!$hasPass): ?>
        <form method="post">
            <input type="password" name="new_pass" placeholder="Créer mot de passe" required>
            <button name="setup_pass">Initialiser le Panel</button>
        </form>
    <?php else: ?>
        <form method="post">
            <input type="password" name="pass" placeholder="Mot de passe" required autofocus>
            <button name="login_pass">Se connecter</button>
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
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . '/' . $name)) return base_url() . '/uploads/' . $name;
    return '';
}

$msg = ''; $err = '';
$view = $_GET['v'] ?? 'dashboard';

// تفعيل جهاز
if (isset($_POST['pair_device'])) {
    $code = strtoupper(trim((string)$_POST['code']));
    $cname = trim((string)($_POST['client_name'] ?? ''));
    $months = (int)($_POST['months'] ?? 12);
    $st = $pdo->prepare("SELECT * FROM devices WHERE code = ?");
    $st->execute([$code]);
    $dev = $st->fetch();
    if (!$dev) {
        $err = "Code ($code) introuvable ! Ouvrez l'application sur la box pour afficher le code.";
    } else {
        $exp = $months > 0 ? date('Y-m-d', strtotime("+{$months} months")) : '';
        $pdo->prepare("UPDATE devices SET paired = 1, active = 1, client_name = ?, expires_at = ? WHERE uid = ?")
            ->execute([$cname !== '' ? $cname : $dev['client_name'], $exp, $dev['uid']]);
        $msg = "Appareil ($code) activé avec succès jusqu'au " . ($exp ?: 'Illimité') . " !";
    }
}

// تخصيص تطبيقات لجهاز
if (isset($_POST['save_dev_apps'])) {
    $duid = (string)$_POST['dev_uid'];
    $chosen = $_POST['app_ids'] ?? [];
    $pdo->prepare("DELETE FROM device_apps WHERE uid = ?")->execute([$duid]);
    $ins = $pdo->prepare("INSERT OR IGNORE INTO device_apps (uid, app_id) VALUES (?, ?)");
    foreach ($chosen as $aid) $ins->execute([$duid, (int)$aid]);
    $msg = "Applications enregistrées avec succès pour ce client !";
}

// تمديد
if (isset($_POST['renew_uid'])) {
    $uid = (string)$_POST['renew_uid'];
    $add = (int)$_POST['add_months'];
    $st = $pdo->prepare("SELECT expires_at FROM devices WHERE uid = ?"); $st->execute([$uid]);
    $cur = (string)$st->fetchColumn();
    $base = ($cur !== '' && strtotime($cur) > time()) ? strtotime($cur) : time();
    $newExp = date('Y-m-d', strtotime("+{$add} months", $base));
    $pdo->prepare("UPDATE devices SET expires_at = ?, active = 1, paired = 1 WHERE uid = ?")->execute([$newExp, $uid]);
    $msg = "Abonnement prolongé jusqu'au $newExp !";
}

if (isset($_GET['toggle_uid'])) { $pdo->prepare("UPDATE devices SET active = 1 - active WHERE uid = ?")->execute([$_GET['toggle_uid']]); header('Location: index.php?v=subscribers'); exit; }
if (isset($_GET['del_uid'])) { $pdo->prepare("DELETE FROM devices WHERE uid = ?")->execute([$_GET['del_uid']]); header('Location: index.php?v=subscribers'); exit; }

// إضافة تطبيق
if (isset($_POST['add_app'])) {
    $name = trim((string)$_POST['app_name']);
    $pkg = trim((string)$_POST['app_pkg']);
    $ver = max(1, (int)($_POST['app_ver'] ?? 1));
    $isGlobal = (int)($_POST['is_global'] ?? 0);
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
        $pdo->prepare("INSERT INTO apps (name, package, version_code, apk, icon, target_uid, is_global) VALUES (?, ?, ?, ?, ?, '', ?)")
            ->execute([$name, $pkg, $ver, $apkUrl, $iconUrl, $isGlobal]);
        $msg = "Application ($name) ajoutée à la bibliothèque !";
    } else $err = "Veuillez renseigner le nom et le package !";
}

if (isset($_GET['toggle_global'])) { $pdo->prepare("UPDATE apps SET is_global = 1 - is_global, target_uid = '' WHERE id = ?")->execute([(int)$_GET['toggle_global']]); header('Location: index.php?v=apps'); exit; }
if (isset($_GET['del_app'])) { $pdo->prepare("DELETE FROM apps WHERE id = ?")->execute([(int)$_GET['del_app']]); $pdo->prepare("DELETE FROM device_apps WHERE app_id = ?")->execute([(int)$_GET['del_app']]); header('Location: index.php?v=apps'); exit; }

// رسائل
if (isset($_POST['send_msg'])) {
    $body = trim((string)$_POST['msg_body']);
    $target = strtoupper(trim((string)($_POST['target_uid'] ?? '')));
    if ($body !== '') {
        $pdo->prepare("INSERT INTO messages (body, target_uid, created_at) VALUES (?, ?, ?)")->execute([$body, $target, date('Y-m-d H:i')]);
        $msg = "Message diffusé aux écrans !";
    }
}
if (isset($_GET['del_msg'])) { $pdo->prepare("DELETE FROM messages WHERE id = ?")->execute([(int)$_GET['del_msg']]); header('Location: index.php?v=messages'); exit; }

// متجر
if (isset($_POST['add_photo'])) {
    $url = save_upload('photo_file', ['png','jpg','jpeg','webp'], $uploadDir) ?: trim((string)($_POST['photo_url'] ?? ''));
    if ($url !== '') { $pdo->prepare("INSERT INTO store (url, thumb, created_at) VALUES (?, ?, ?)")->execute([$url, $url, date('Y-m-d H:i')]); $msg = "Photo ajoutée à la boutique !"; }
}
if (isset($_GET['del_photo'])) { $pdo->prepare("DELETE FROM store WHERE id = ?")->execute([(int)$_GET['del_photo']]); header('Location: index.php?v=store'); exit; }

// إعدادات المحل
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
    $msg = "Paramètres de la boutique enregistrés !";
}

$set = get_settings($pdo);
$devices = $pdo->query("SELECT * FROM devices ORDER BY paired ASC, last_seen DESC")->fetchAll();
$apps = $pdo->query("SELECT * FROM apps ORDER BY id DESC")->fetchAll();
$msgs = $pdo->query("SELECT * FROM messages ORDER BY id DESC")->fetchAll();
$photos = $pdo->query("SELECT * FROM store ORDER BY id DESC")->fetchAll();

// حساب الإحصائيات الـ 5 للبطاقات العلوية الملونة
$totalDev = count($devices);
$activeDev = 0;
$expiredDev = 0;
$pendingDev = 0;
$today = date('Y-m-d');
foreach ($devices as $d) {
    if (!$d['paired']) $pendingDev++;
    elseif (!$d['active']) $pendingDev++;
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
<title>BETA Panel v2.0 - <?= htmlspecialchars($set['shop_name']) ?></title>
<style>
:root{
  --bg:#0f172a; --sidebar:#0a0f1d; --card:#1e293b; --border:#334155; --text:#f8fafc; --muted:#94a3b8;
  --blue:#3b82f6; --green:#10b981; --red:#ef4444; --orange:#f97316; --purple:#a855f7;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;min-height:100vh}

/* Sidebar مثل BETA Panel */
aside{width:250px;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0}
.brand{padding:22px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border)}
.brand-icon{width:38px;height:38px;background:var(--blue);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px}
.brand-text b{display:block;font-size:1.05rem;letter-spacing:1px;color:#fff}
.brand-text span{font-size:.75rem;color:var(--blue);font-weight:700}
nav{padding:18px 12px;flex:1;display:flex;flex-direction:column;gap:5px}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:.92rem;font-weight:600;transition:.15s}
.nav-item:hover{background:rgba(255,255,255,.05);color:#fff}
.nav-item.active{background:var(--blue);color:#fff}
.nav-item .icon{font-size:1.15rem;width:22px;text-align:center}

/* Content Area */
main{flex:1;display:flex;flex-direction:column;min-width:0;overflow-y:auto}
header{padding:18px 30px;background:rgba(10,15,29,.7);backdrop-filter:blur(8px);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
header h2{margin:0;font-size:1.4rem;font-weight:700}
.user-btn{display:flex;align-items:center;gap:10px;background:var(--card);border:1px solid var(--border);padding:8px 16px;border-radius:8px;color:#fff;text-decoration:none;font-size:.85rem;font-weight:600}

.content{padding:26px 30px}
.alert{padding:12px 18px;border-radius:8px;margin-bottom:20px;font-weight:600;font-size:.92rem}
.alert.ok{background:rgba(16,185,129,.15);border:1px solid var(--green);color:var(--green)}
.alert.err{background:rgba(239,68,68,.15);border:1px solid var(--red);color:var(--red)}

/* Stats Cards الخمسة الملونة المتطابقة تماماً مع الصورة */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:18px;margin-bottom:26px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px 20px;display:flex;align-items:center;gap:16px}
.stat-icon{width:50px;height:50px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0}
.stat-icon.blue{background:var(--blue)}
.stat-icon.green{background:var(--green)}
.stat-icon.red{background:var(--red)}
.stat-icon.orange{background:var(--orange)}
.stat-icon.purple{background:var(--purple)}
.stat-info b{display:block;font-size:1.65rem;font-weight:800;color:#fff;line-height:1.2}
.stat-info span{font-size:.82rem;color:var(--muted);font-weight:600}

/* Section Cards & Tables */
.panel-card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:24px}
.panel-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.panel-head h3{margin:0;font-size:1.1rem;font-weight:700}
.panel-body{padding:22px}

.table-responsive{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.9rem;text-align:left}
th{background:rgba(10,15,29,.5);color:var(--muted);font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:12px 18px;border-bottom:1px solid var(--border)}
td{padding:14px 18px;border-bottom:1px solid rgba(51,65,85,.5);white-space:nowrap}
tr:hover td{background:rgba(255,255,255,.02)}

.badge{padding:4px 10px;border-radius:99px;font-size:.75rem;font-weight:800;display:inline-flex;align-items:center;gap:4px}
.b-actif{background:rgba(16,185,129,.15);color:var(--green)}
.b-exp{background:rgba(239,68,68,.15);color:var(--red)}
.b-wait{background:rgba(249,115,22,.15);color:var(--orange)}
.code-tag{font-family:monospace;font-size:1.05rem;font-weight:900;letter-spacing:1px;color:var(--blue)}

/* Forms */
label{display:block;font-size:.85rem;color:var(--muted);font-weight:600;margin-bottom:6px}
input,select,textarea{width:100%;padding:11px 14px;border-radius:8px;border:1px solid var(--border);background:#0f172a;color:#fff;font-size:.95rem;margin-bottom:14px;box-sizing:border-box}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--blue)}
.btn{background:var(--blue);color:#fff;border:0;padding:10px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn:hover{opacity:.9}
.btn-s{padding:5px 10px;font-size:.8rem;border-radius:6px}
.btn-green{background:var(--green)}
.btn-red{background:var(--red)}
.btn-gray{background:#334155}

/* Checkbox Grid for Device Apps */
.chk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin:14px 0 20px}
.chk-item{display:flex;align-items:center;gap:10px;background:#0f172a;border:1px solid var(--border);padding:10px 14px;border-radius:8px;cursor:pointer}
.chk-item input{width:18px;height:18px;margin:0}

@media(max-width:850px){
  body{flex-direction:column}
  aside{width:100%;border-right:0;border-bottom:1px solid var(--border)}
  nav{flex-direction:row;flex-wrap:wrap;overflow-x:auto}
  .content{padding:16px}
}
</style>
</head>
<body>

<!-- Sidebar القائمة الجانبية المماثلة لـ BETA Panel -->
<aside>
    <div class="brand">
        <div class="brand-icon">📺</div>
        <div class="brand-text">
            <b>BETA PANEL</b>
            <span>AZ SAT v2.0</span>
        </div>
    </div>
    <nav>
        <a href="?v=dashboard" class="nav-item <?= $view==='dashboard'?'active':'' ?>"><span class="icon">📊</span> Dashboard</a>
        <a href="?v=activate" class="nav-item <?= $view==='activate'?'active':'' ?>"><span class="icon">⚡</span> Activer Appareil</a>
        <a href="?v=subscribers" class="nav-item <?= $view==='subscribers'?'active':'' ?>"><span class="icon">👥</span> Mes Abonnés (<?= $totalDev ?>)</a>
        <a href="?v=apps" class="nav-item <?= $view==='apps'?'active':'' ?>"><span class="icon">📦</span> Télécharger APK (<?= $appsCount ?>)</a>
        <a href="?v=messages" class="nav-item <?= $view==='messages'?'active':'' ?>"><span class="icon">✉️</span> Messages (<?= count($msgs) ?>)</a>
        <a href="?v=store" class="nav-item <?= $view==='store'?'active':'' ?>"><span class="icon">🛍️</span> Boutique (<?= count($photos) ?>)</a>
        <a href="?v=settings" class="nav-item <?= $view==='settings'?'active':'' ?>"><span class="icon">⚙️</span> Paramètres</a>
    </nav>
</aside>

<!-- Main Container -->
<main>
    <header>
        <h2><?= strtoupper($view) ?></h2>
        <a href="?logout=1" class="user-btn">Déconnexion 🚪</a>
    </header>

    <div class="content">
        <?php if ($msg): ?><div class="alert ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <!-- بطاقات الإحصائيات الـ 5 الملونة (تظهر دائماً في الأعلى كالصورة) -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">👥</div>
                <div class="stat-info">
                    <b><?= $totalDev ?></b>
                    <span>Total Abonnés</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">🟢</div>
                <div class="stat-info">
                    <b><?= $activeDev ?></b>
                    <span>Actifs</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red">🔴</div>
                <div class="stat-info">
                    <b><?= $expiredDev ?></b>
                    <span>Expirés</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">⏳</div>
                <div class="stat-info">
                    <b><?= $pendingDev ?></b>
                    <span>En attente / Off</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">📦</div>
                <div class="stat-info">
                    <b><?= $appsCount ?></b>
                    <span>APKs Disponibles</span>
                </div>
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
                            <?php if ($a['icon']): ?><img src="<?= htmlspecialchars($a['icon']) ?>" width="28" height="28" style="border-radius:6px;object-fit:cover"><?php else: ?>📺<?php endif; ?>
                            <div>
                                <b><?= htmlspecialchars($a['name']) ?></b><br>
                                <small style="color:var(--muted)"><?= $isG?'Global (Tous)':'Personnalisé' ?></small>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="save_dev_apps" class="btn btn-green">Enregistrer les applications</button>
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
                            <th>Status</th>
                            <th>Code</th>
                            <th>Client</th>
                            <th>Modèle Box</th>
                            <th>Dernière vue</th>
                            <th>Expire le</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($devices)): ?>
                            <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">Aucun appareil connecté pour le moment.</td></tr>
                        <?php else: ?>
                            <?php foreach ($devices as $d):
                                $exp = $d['expires_at'];
                                $isExp = ($exp !== '' && $exp < $today);
                            ?>
                            <tr>
                                <td>
                                    <?php if (!$d['paired']): ?>
                                        <span class="badge b-wait">● Attente</span>
                                    <?php elseif (!$d['active']): ?>
                                        <span class="badge b-exp">● Inactif</span>
                                    <?php elseif ($isExp): ?>
                                        <span class="badge b-exp">● Expiré</span>
                                    <?php else: ?>
                                        <span class="badge b-actif">● Actif</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="code-tag"><?= htmlspecialchars($d['code']) ?></span></td>
                                <td><b><?= htmlspecialchars($d['client_name'] ?: '—') ?></b></td>
                                <td><?= htmlspecialchars($d['model'] ?: 'Android') ?> <small style="color:var(--muted)">(v<?= htmlspecialchars($d['app_ver']) ?>)</small></td>
                                <td><small style="color:var(--muted)"><?= htmlspecialchars($d['last_seen'] ?: '—') ?></small></td>
                                <td><b><?= $exp ? htmlspecialchars($exp) : 'Illimité' ?></b></td>
                                <td>
                                    <a href="?v=<?= $view ?>&dev_apps=<?= urlencode($d['uid']) ?>" class="btn btn-s">📱 Apps</a>
                                    <?php if (!$d['paired']): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="code" value="<?= htmlspecialchars($d['code']) ?>">
                                            <input type="hidden" name="months" value="12">
                                            <button type="submit" name="pair_device" class="btn btn-s btn-green">Activer (1 an)</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="renew_uid" value="<?= htmlspecialchars($d['uid']) ?>">
                                            <input type="hidden" name="add_months" value="12">
                                            <button type="submit" class="btn btn-s btn-gray">+1 An</button>
                                        </form>
                                        <a href="?v=<?= $view ?>&toggle_uid=<?= urlencode($d['uid']) ?>" class="btn btn-s btn-gray"><?= $d['active']?'Bloquer':'Débloquer' ?></a>
                                    <?php endif; ?>
                                    <a href="?v=<?= $view ?>&del_uid=<?= urlencode($d['uid']) ?>" class="btn btn-s btn-red" onclick="return confirm('Supprimer cet appareil ?')">🗑️</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. ACTIVER UN CODE -->
        <?php elseif ($view === 'activate'): ?>
        <div class="panel-card" style="max-width:550px;margin:0 auto">
            <div class="panel-head">
                <h3>⚡ Activer un Nouveau Code Client</h3>
            </div>
            <div class="panel-body">
                <form method="post">
                    <label>Code affiché sur la Box / Télé (5 caractères) :</label>
                    <input type="text" name="code" placeholder="Ex: CCHWN" required style="font-size:1.4rem;font-weight:900;letter-spacing:4px;text-transform:uppercase;text-align:center">
                    
                    <label>Nom du Client (optionnel) :</label>
                    <input type="text" name="client_name" placeholder="Ex: Mohamed Salah">
                    
                    <label>Durée d'abonnement :</label>
                    <select name="months">
                        <option value="1">1 Mois</option>
                        <option value="3">3 Mois</option>
                        <option value="6">6 Mois</option>
                        <option value="12" selected>12 Mois (1 An)</option>
                        <option value="24">24 Mois (2 Ans)</option>
                        <option value="0">Illimité (Permanent)</option>
                    </select>
                    
                    <button type="submit" name="pair_device" class="btn btn-green" style="width:100%;padding:14px;font-size:1rem">Activer le boîtier maintenant</button>
                </form>
            </div>
        </div>

        <!-- 3. GESTION DES APPLICATIONS (APK) -->
        <?php elseif ($view === 'apps'): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card">
                <div class="panel-head"><h3>➕ Ajouter une Application</h3></div>
                <div class="panel-body">
                    <div style="background:rgba(59,130,246,.08);border:1px dashed var(--blue);border-radius:10px;padding:12px;margin-bottom:16px">
                        <label style="color:var(--blue)">⚡ Remplissage automatique depuis un fichier APK :</label>
                        <input type="file" id="apkInspector" accept=".apk" style="margin-bottom:4px">
                        <div id="apkScanMsg" style="font-size:.8rem;color:var(--green)"></div>
                        <img id="iconPreview" style="display:none;width:44px;height:44px;border-radius:10px;margin-top:6px;object-fit:cover;border:1px solid var(--blue)">
                    </div>

                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="auto_icon_b64" id="autoIconB64">
                        <label>Nom de l'application :</label><input type="text" name="app_name" id="fName" placeholder="Ex: Avatar Pro" required>
                        <label>Package Name :</label><input type="text" name="app_pkg" id="fPkg" placeholder="Ex: com.avatar.iptv" required>
                        <label>Version Code :</label><input type="number" name="app_ver" id="fVer" value="1" required>
                        <label>Icône personnalisée (optionnel) :</label><input type="file" name="icon_file" accept="image/*"><input type="url" name="icon_url" placeholder="Ou lien de l'image (https://...)">
                        <label>Lien APK de téléchargement direct (GitHub Release) :</label><input type="url" name="apk_url" placeholder="https://github.com/.../app.apk">
                        <label>Visibilité :</label>
                        <select name="is_global">
                            <option value="0">Bibliothèque فقط (assigné manuellement par client ☑️)</option>
                            <option value="1">Global (apparaît chez tous les clients)</option>
                        </select>
                        <button type="submit" name="add_app" class="btn" style="width:100%;padding:13px">Enregistrer l'application</button>
                    </form>
                    <script src="https://cdn.jsdelivr.net/npm/app-info-parser@1.1.5/dist/app-info-parser.min.js"></script>
                    <script>
                    document.getElementById('apkInspector').addEventListener('change', async function(e) {
                        var file = e.target.files[0]; if (!file) return;
                        var msg = document.getElementById('apkScanMsg'); msg.style.color = '#38bdf8'; msg.textContent = '⏳ Analyse du fichier APK...';
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
                            msg.style.color = '#10b981'; msg.textContent = '✅ Nom, package et icône extraits avec succès !';
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
                                <td><?php if ($a['icon']): ?><img src="<?= htmlspecialchars($a['icon']) ?>" width="34" height="34" style="border-radius:50%;object-fit:cover"><?php else: ?>📺<?php endif; ?></td>
                                <td><b><?= htmlspecialchars($a['name']) ?></b> <small style="color:var(--muted)">v<?= (int)$a['version_code'] ?></small></td>
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

        <!-- 4. MESSAGES -->
        <?php elseif ($view === 'messages'): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card">
                <div class="panel-head"><h3>✉️ Diffuser un Message</h3></div>
                <div class="panel-body">
                    <form method="post">
                        <label>Destinataire :</label>
                        <select name="target_uid">
                            <option value="">Tous les clients (Général)</option>
                            <?php foreach ($devices as $d): ?>
                                <option value="<?= htmlspecialchars($d['code']) ?>"><?= htmlspecialchars($d['code'] . ($d['client_name'] ? ' - ' . $d['client_name'] : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label>Texte du message :</label>
                        <textarea name="msg_body" rows="4" placeholder="Ex: Bonjour, votre abonnement expire bientôt..." required></textarea>
                        <button type="submit" name="send_msg" class="btn" style="width:100%;padding:13px">Envoyer le Message</button>
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

        <!-- 5. BOUTIQUE -->
        <?php elseif ($view === 'store'): ?>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
            <div class="panel-card">
                <div class="panel-head"><h3>🛍️ Ajouter un Produit</h3></div>
                <div class="panel-body">
                    <form method="post" enctype="multipart/form-data">
                        <label>Image du produit :</label>
                        <input type="file" name="photo_file" accept="image/*">
                        <label>Ou lien d'image direct :</label>
                        <input type="url" name="photo_url" placeholder="https://...">
                        <button type="submit" name="add_photo" class="btn" style="width:100%;padding:13px">Ajouter à la Boutique</button>
                    </form>
                </div>
            </div>
            <div class="panel-card">
                <div class="panel-head"><h3>Catalogue des Produits</h3></div>
                <div class="panel-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px">
                        <?php foreach ($photos as $p): ?>
                        <div style="background:#0f172a;border:1px solid var(--border);border-radius:10px;overflow:hidden;text-align:center;padding-bottom:10px">
                            <img src="<?= htmlspecialchars($p['url']) ?>" style="width:100%;height:110px;object-fit:cover;margin-bottom:8px">
                            <a href="?v=store&del_photo=<?= (int)$p['id'] ?>" class="btn btn-s btn-red">Supprimer</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 6. PARAMETRES BOUTIQUE -->
        <?php elseif ($view === 'settings'): ?>
        <div class="panel-card" style="max-width:650px;margin:0 auto">
            <div class="panel-head"><h3>⚙️ Paramètres Boutique & Mise à jour MyTV</h3></div>
            <div class="panel-body">
                <form method="post" enctype="multipart/form-data">
                    <label>Nom de la Boutique :</label>
                    <input type="text" name="shop_name" value="<?= htmlspecialchars($set['shop_name']) ?>" required>
                    <label>Numéro WhatsApp (Ex: 21626117917) :</label>
                    <input type="text" name="shop_whatsapp" value="<?= htmlspecialchars($set['shop_whatsapp']) ?>" required>
                    <label>Message de bienvenue :</label>
                    <input type="text" name="shop_welcome" value="<?= htmlspecialchars($set['shop_welcome']) ?>">
                    <label>Logo de la Boutique :</label>
                    <input type="file" name="logo_file" accept="image/*">
                    <input type="url" name="shop_logo" value="<?= htmlspecialchars($set['shop_logo']) ?>" placeholder="Ou lien du logo">
                    <hr style="border:0;border-top:1px solid var(--border);margin:20px 0">
                    <label>Mise à jour Auto de MyTV (Version Code) :</label>
                    <input type="number" name="mytv_ver_code" value="<?= htmlspecialchars($set['mytv_ver_code']) ?>">
                    <label>Version Name :</label>
                    <input type="text" name="mytv_ver_name" value="<?= htmlspecialchars($set['mytv_ver_name']) ?>">
                    <label>Lien APK de mise à jour MyTV :</label>
                    <input type="url" name="mytv_apk" value="<?= htmlspecialchars($set['mytv_apk']) ?>">
                    <label>Notes de mise à jour :</label>
                    <input type="text" name="mytv_notes" value="<?= htmlspecialchars($set['mytv_notes']) ?>">
                    <button type="submit" name="save_settings" class="btn btn-green" style="width:100%;padding:14px">Enregistrer les Paramètres</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>

</body>
</html>
