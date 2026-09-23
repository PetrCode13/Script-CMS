<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/version.php';

$auth = new Auth(__DIR__ . '/../config/users.json');
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';

if ($token === '') {
    $error = 'Chybí resetovací token.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($password !== $password2) {
        $error = 'Hesla se neshodují.';
    } else {
        [$ok, $result] = $auth->resetPassword($token, $password);
        if ($ok) {
            $success = $result;
        } else {
            $error = $result;
        }
    }
}
?>
<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nové heslo — ScriptCMS ™</title>
<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;font-family:system-ui,sans-serif;color:#122033}.card{width:min(430px,calc(100% - 30px));background:#fff;border:1px solid #dbe3ee;border-radius:16px;padding:28px;box-shadow:0 12px 40px rgba(15,23,42,.08)}h1{margin:0 0 8px;color:#0878c9;font-size:1.5rem}.sub{color:#64748b;margin-bottom:20px}.error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:9px;padding:11px;margin-bottom:14px}.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#166534;border-radius:9px;padding:11px;margin-bottom:14px}label{font-weight:700;font-size:.9rem}input{display:block;width:100%;padding:11px;margin:6px 0 14px;border:1px solid #dbe3ee;border-radius:9px;font:inherit}button{width:100%;padding:11px;border:0;border-radius:9px;background:#0878c9;color:#fff;font-weight:800;font-size:1rem;cursor:pointer}.back{display:block;text-align:center;margin-top:15px;color:#0878c9;text-decoration:none}</style></head>
<body><div class="card"><h1>Nové heslo</h1><div class="sub">ScriptCMS ™ · v<?= htmlspecialchars(SCRIPTCMS_VERSION) ?></div>
<?php if($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="ok"><?= htmlspecialchars($success) ?></div><a class="back" href="login.php">Přejít na přihlášení</a><?php else: ?>
<form method="post"><input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>"><label>Nové heslo</label><input type="password" name="password" minlength="8" autocomplete="new-password" required><label>Nové heslo znovu</label><input type="password" name="password2" minlength="8" autocomplete="new-password" required><button type="submit">Nastavit nové heslo</button></form><a class="back" href="login.php">← Zpět na přihlášení</a><?php endif; ?>
</div></body></html>
