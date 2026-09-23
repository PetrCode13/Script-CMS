<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/version.php';
require_once __DIR__ . '/../core/Updater.php';

$auth = new Auth(__DIR__ . '/../config/users.json');
if (!$auth->isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? 'editor') !== 'admin') { http_response_code(403); exit('Přístup odepřen.'); }

$csrfToken = $auth->generateCsrfToken();
$updater = new Updater(SCRIPTCMS_VERSION);
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->verifyCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Neplatný CSRF token.'); }

    if (($_POST['action'] ?? '') === 'install') {
        $result = $updater->installVersion((string)($_POST['version'] ?? ''));
        $message = $result['message'] ?? 'Aktualizace nebyla provedena.';
        $messageType = !empty($result['success']) ? 'success' : 'error';
        if (!empty($result['success'])) {
            header('Location: update.php?updated=1');
            exit;
        }
    }
}

if (isset($_GET['updated'])) {
    $message = 'Aktualizace byla úspěšně dokončena. Nyní používáš ScriptCMS ™ v' . SCRIPTCMS_VERSION . '.';
    $messageType = 'success';
}

$newestVersion = $updater->getNewestAvailable();
$manifest = $updater->getManifest();
$available = [];
$changelog = '';

if ($newestVersion !== null) {
    $available[$newestVersion] = $newestVersion . '.zip';
    if (!empty($manifest['changelog'])) {
        $changelog = (string)$manifest['changelog'];
    }
}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ScriptCMS ™ — Aktualizace</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#122033;font-family:system-ui,sans-serif;padding:20px}.wrap{max-width:800px;margin:auto}.top,.card{background:#fff;border:1px solid #dbe3ee;border-radius:14px;box-shadow:0 8px 28px rgba(15,23,42,.06)}.top{padding:18px 20px;display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:18px}h1{margin:0;font-size:1.4rem}.muted{color:#64748b}.card{padding:22px}.msg{padding:13px 15px;border-radius:10px;margin-bottom:18px}.success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}.error{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}.versions{display:grid;gap:10px;margin-top:18px}.row{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;border:1px solid #dbe3ee;border-radius:10px;padding:16px}.v{font-size:1.1rem;font-weight:800}.btn{border:0;border-radius:9px;padding:10px 14px;background:#16803c;color:#fff;font-weight:800;cursor:pointer}.back{color:#075b9a;text-decoration:none;font-weight:700}.changelog{margin-top:10px;padding:10px 12px;background:#f8fafc;border-left:3px solid #0878c9;border-radius:6px;font-size:.9rem;line-height:1.5;white-space:pre-wrap}.changelog-title{font-weight:700;margin-bottom:4px;color:#075b9a;font-size:.85rem;text-transform:uppercase;letter-spacing:.5px}@media(max-width:600px){body{padding:12px}.top,.row{align-items:flex-start;flex-direction:column}.row .btn{width:100%}}
</style>
</head><body><div class="wrap">
<div class="top"><div><h1>ScriptCMS ™ — Aktualizace</h1><div class="muted">Nainstalovaná verze: <strong>v<?= htmlspecialchars(SCRIPTCMS_VERSION) ?></strong></div></div><a class="back" href="index.php">← Administrace</a></div>
<?php if($message): ?><div class="msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="card"><h2>Aktualizace systému</h2><p class="muted">Dostupné ZIP balíčky se načítají ze vzdáleného serveru.</p>
<?php if($available): ?><div class="versions"><?php foreach($available as $version=>$fileName): ?>
<div class="row">
  <div style="flex:1">
    <div class="v">ScriptCMS ™ v<?= htmlspecialchars($version) ?></div>
    <div class="muted"><?= htmlspecialchars($fileName) ?></div>
    <?php if ($changelog !== ''): ?>
      <div class="changelog">
        <div class="changelog-title">Co je nového:</div>
        <?= nl2br(htmlspecialchars($changelog)) ?>
      </div>
    <?php endif; ?>
  </div>
  <form method="post" onsubmit="return confirm('Aktualizovat ScriptCMS ™ na verzi <?= htmlspecialchars($version,ENT_QUOTES) ?>?')">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="install">
    <input type="hidden" name="version" value="<?= htmlspecialchars($version) ?>">
    <button class="btn">Aktualizovat</button>
  </form>
</div>
<?php endforeach; ?></div>
<?php else: ?><div class="muted" style="padding:14px;background:#f8fafc;border-radius:9px">Žádná novější verze není dostupná.</div><?php endif; ?>
</div></div></body></html>

