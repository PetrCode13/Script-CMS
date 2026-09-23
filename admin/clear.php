<?php
declare(strict_types=1);

// Zapnutí zobrazení chyb pro případné ladění
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../core/Auth.php';

$auth = new Auth(__DIR__ . '/../config/users.json');
if (!$auth->isLoggedIn()) { 
    http_response_code(403); 
    exit('Přístup odepřen: Musíte být přihlášeni.'); 
}
if (($_SESSION['role'] ?? 'editor') !== 'admin') { 
    http_response_code(403); 
    exit('Přístup odepřen: Vyžadována role admin.'); 
}

$results = [];

// 1. Promazání PHP OPcache
if (function_exists('opcache_reset')) {
    try {
        if (@opcache_reset()) {
            $results[] = ['success' => true, 'msg' => 'PHP OPcache byla úspěšně vyčištěna.'];
        } else {
            $results[] = ['success' => false, 'msg' => 'PHP OPcache se nepodařilo vyčistit (může být zakázáno v php.ini).'];
        }
    } catch (\Throwable $e) {
        $results[] = ['success' => false, 'msg' => 'Chyba při mazání OPcache: ' . $e->getMessage()];
    }
} else {
    $results[] = ['success' => false, 'msg' => 'PHP OPcache není na tomto serveru dostupná.'];
}

// 2. Promazání APCu cache
if (function_exists('apcu_clear_cache')) {
    try {
        if (@apcu_clear_cache()) {
            $results[] = ['success' => true, 'msg' => 'APCu datová cache byla úspěšně vyčištěna.'];
        }
    } catch (\Throwable $e) {
        // Ignorujeme případnou chybu APCu
    }
}

// 3. Promazání složky cache/ (pouze pokud fyzicky existuje)
$cacheDir = __DIR__ . '/../cache';
if (is_dir($cacheDir)) {
    $files = glob($cacheDir . '/*');
    $deletedCount = 0;
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_file($file)) {
                $basename = basename($file);
                if ($basename !== '.htaccess' && $basename !== 'index.html') {
                    if (@unlink($file)) {
                        $deletedCount++;
                    }
                }
            }
        }
    }
    $results[] = ['success' => true, 'msg' => "Složka cache/ promazána (smazáno {$deletedCount} souborů)."];
}
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vyčištění cache — ScriptCMS ™</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f7fb; color: #122033; font-family: system-ui, sans-serif; padding: 20px; }
        .wrap { max-width: 600px; margin: auto; }
        .card { background: #fff; border: 1px solid #dbe3ee; border-radius: 14px; padding: 22px; box-shadow: 0 8px 28px rgba(15,23,42,.06); }
        h1 { margin-top: 0; font-size: 1.3rem; }
        .item { padding: 12px 14px; border-radius: 8px; margin-bottom: 10px; font-size: 0.95rem; }
        .ok { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .warn { background: #fff8e6; color: #854d0e; border: 1px solid #fef3c7; }
        .btn { display: inline-block; margin-top: 15px; padding: 10px 16px; background: #075b9a; color: #fff; text-decoration: none; font-weight: bold; border-radius: 8px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>⚡ Vyčištění vyrovnávací paměti</h1>
        
        <?php foreach ($results as $res): ?>
            <div class="item <?= $res['success'] ? 'ok' : 'warn' ?>">
                <?= htmlspecialchars($res['msg']) ?>
            </div>
        <?php endforeach; ?>

        <a class="btn" href="update.php">← Zpět na Aktualizace</a>
        <a class="btn" style="background:#64748b;" href="index.php">Administrace</a>
    </div>
</div>
</body>
</html>

