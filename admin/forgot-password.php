<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/version.php';

$auth = new Auth(__DIR__ . '/../config/users.json');
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->requestPasswordReset((string)($_POST['email'] ?? ''));
    $message = 'Pokud je tento e-mail přiřazen k účtu, byl na něj odeslán odkaz pro obnovení hesla.';
}
?>
<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Zapomenuté heslo — ScriptCMS ™</title>
<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;font-family:system-ui,sans-serif;color:#122033}.card{width:min(430px,calc(100% - 30px));background:#fff;border:1px solid #dbe3ee;border-radius:16px;padding:28px;box-shadow:0 12px 40px rgba(15,23,42,.08)}h1{margin:0 0 8px;color:#0878c9;font-size:1.5rem}.sub{color:#64748b;margin-bottom:20px}.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#166534;border-radius:9px;padding:11px;margin-bottom:14px}label{font-weight:700;font-size:.9rem}input{display:block;width:100%;padding:11px;margin:6px 0 14px;border:1px solid #dbe3ee;border-radius:9px;font:inherit}button{width:100%;padding:11px;border:0;border-radius:9px;background:#0878c9;color:#fff;font-weight:800;font-size:1rem;cursor:pointer}.back{display:block;text-align:center;margin-top:15px;color:#0878c9;text-decoration:none}</style></head>
<body><div class="card"><h1>Zapomenuté heslo</h1><div class="sub">Obnovení přístupu do administrace</div>
<?php if($message): ?><div class="ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<form method="post"><label>E-mail účtu</label><input type="email" name="email" autocomplete="email" required autofocus><button type="submit">Odeslat odkaz pro obnovení</button></form>
<a class="back" href="login.php">← Zpět na přihlášení</a></div></body></html>
