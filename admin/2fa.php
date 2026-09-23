<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/version.php';

$auth = new Auth(__DIR__ . '/../config/users.json');

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if (!$auth->isPendingTwoFactor()) {
    header('Location: login.php');
    exit;
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'verify') {
        if ($auth->verifyTwoFactor(trim((string)($_POST['code'] ?? '')))) {
            header('Location: index.php');
            exit;
        }
        $error = 'Neplatný kód, kód vypršel nebo byl překročen počet pokusů.';
    } elseif ($action === 'resend') {
        if ($auth->sendTwoFactorCode($auth->pendingTwoFactorUsername())) {
            $message = 'Nový kód byl odeslán na e-mail účtu.';
        } else {
            $error = 'Nový kód lze odeslat nejdříve za 60 sekund.';
        }
    } elseif ($action === 'cancel') {
        $auth->clearTwoFactor();
        header('Location: login.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ScriptCMS ™ — Ověření</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;font-family:system-ui,sans-serif;color:#122033}.card{width:min(390px,calc(100% - 30px));background:#fff;border:1px solid #dbe3ee;border-radius:16px;padding:28px;box-shadow:0 12px 40px rgba(15,23,42,.08)}h1{margin:0 0 5px;color:#0878c9;font-size:1.5rem}.sub{color:#64748b;margin-bottom:20px}.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:9px;padding:11px;margin-bottom:14px}.error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:9px;padding:10px;margin-bottom:14px}.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#166534;border-radius:9px;padding:10px;margin-bottom:14px}label{font-weight:700;font-size:.9rem}input{display:block;width:100%;padding:13px;margin:6px 0 14px;border:1px solid #dbe3ee;border-radius:9px;font:inherit;text-align:center;letter-spacing:8px;font-size:1.3rem}button{width:100%;padding:11px;border:0;border-radius:9px;background:#0878c9;color:#fff;font-weight:800;font-size:1rem;cursor:pointer}.secondary{background:#64748b;margin-top:9px}
</style>
</head>
<body><div class="card"><h1>Ověření přihlášení</h1><div class="sub">ScriptCMS ™ · v<?= htmlspecialchars(SCRIPTCMS_VERSION) ?></div>
<div class="info">Na e-mail vašeho účtu jsme poslali jednorázový <strong>6místný kód</strong>. Platí 10 minut.</div>
<?php if($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="action" value="verify"><label>Kód z e-mailu</label><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus><button type="submit">Ověřit a přihlásit</button></form>
<form method="post"><input type="hidden" name="action" value="resend"><button class="secondary" type="submit">Poslat nový kód</button></form>
<form method="post"><input type="hidden" name="action" value="cancel"><button class="secondary" type="submit">Zrušit</button></form>
</div></body></html>
