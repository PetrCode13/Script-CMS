<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/version.php';

$auth = new Auth(__DIR__ . '/../config/users.json');

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if ($auth->isPendingTwoFactor()) {
    header('Location: 2fa.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($auth->login($username, $password)) {
        header('Location: 2fa.php');
        exit;
    }

    $error = 'Neplatné přihlašovací údaje, chybí e-mail u účtu nebo se nepodařilo odeslat 2FA kód.';
}
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ScriptCMS ™ — Přihlášení</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;font-family:system-ui,sans-serif;color:#122033}.card{width:min(390px,calc(100% - 30px));background:#fff;border:1px solid #dbe3ee;border-radius:16px;padding:28px;box-shadow:0 12px 40px rgba(15,23,42,.08)}h1{margin:0 0 5px;color:#0878c9;font-size:1.5rem}.sub{color:#64748b;margin-bottom:20px}.error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:9px;padding:10px;margin-bottom:14px}label{font-weight:700;font-size:.9rem}input{display:block;width:100%;padding:11px;margin:6px 0 14px;border:1px solid #dbe3ee;border-radius:9px;font:inherit}button{width:100%;padding:11px;border:0;border-radius:9px;background:#0878c9;color:#fff;font-weight:800;font-size:1rem;cursor:pointer}.forgot{display:block;text-align:center;margin-top:15px;color:#0878c9;text-decoration:none;font-size:.92rem}
</style>
</head>
<body><div class="card"><h1>ScriptCMS ™</h1><div class="sub">Administrace · v<?= htmlspecialchars(SCRIPTCMS_VERSION) ?></div>
<?php if($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post"><label>Uživatelské jméno</label><input name="username" autocomplete="username" required autofocus><label>Heslo</label><input type="password" name="password" autocomplete="current-password" required><button type="submit">Přihlásit se</button></form>
<a class="forgot" href="forgot-password.php">Zapomenuté heslo?</a>
</div></body></html>
