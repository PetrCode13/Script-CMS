<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/version.php';

$lockFile = __DIR__ . '/installed.lock';
if (file_exists($lockFile)) exit('ScriptCMS ™ již bylo nainstalováno. Pro novou instalaci odstraňte install/installed.lock.');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if ($user !== '' && $pass !== '') {
        $usersFile = __DIR__ . '/../config/users.json';
        $configFile = __DIR__ . '/../config/config.json';
        $users = [$user => ['password'=>password_hash($pass,PASSWORD_DEFAULT),'role'=>'admin']];
        $config = ['site_title'=>'ScriptCMS Web','installed_at'=>date('Y-m-d H:i:s'),'theme'=>'light'];
        file_put_contents($usersFile,json_encode($users,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX);
        file_put_contents($configFile,json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX);
        file_put_contents($lockFile,'INSTALLED',LOCK_EX);
        $message='Instalace byla úspěšně dokončena. <a href="../admin/login.php">Přejít do administrace</a>';
    } else $message='Vyplňte uživatelské jméno i heslo.';
}
?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalace ScriptCMS ™</title><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;font-family:system-ui,sans-serif}.card{width:min(400px,calc(100% - 30px));background:#fff;border:1px solid #dbe3ee;border-radius:15px;padding:28px;box-shadow:0 10px 35px rgba(15,23,42,.08)}h1{margin:0 0 6px;color:#0878c9}.muted{color:#64748b;margin-bottom:20px}input{width:100%;padding:11px;margin:6px 0 14px;border:1px solid #dbe3ee;border-radius:9px;font:inherit}button{width:100%;padding:11px;border:0;border-radius:9px;background:#0878c9;color:#fff;font-weight:800}</style><style id="scriptcms-responsive-images">
/* ScriptCMS responsive image safety */
img { max-width: 100%; height: auto; }
.wysiwyg-editor img, .content img, .article-content img, .post-content img, .page-content img {
    max-width: 100%;
    height: auto;
}
</style>
</head><body><div class="card"><h1>ScriptCMS ™</h1><div class="muted">Instalace · v<?= htmlspecialchars(SCRIPTCMS_VERSION) ?></div><?php if($message): ?><p><?= $message ?></p><?php endif; ?><?php if(!file_exists($lockFile)): ?><form method="post"><label>Uživatelské jméno</label><input name="username" required><label>Heslo</label><input type="password" name="password" minlength="6" required><button>Vytvořit účet a dokončit</button></form><?php endif; ?></div></body></html>
