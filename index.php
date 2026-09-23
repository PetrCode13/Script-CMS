<?php
declare(strict_types=1);

require_once __DIR__ . '/core/version.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Content.php';
require_once __DIR__ . '/core/Security.php';

$configFile = __DIR__ . '/config/config.json';
$configData = file_exists($configFile) ? (json_decode((string) file_get_contents($configFile), true) ?: []) : [];

$security = new Security(__DIR__);
$security->bootstrap();

$siteTitle = $configData['site_title'] ?? 'ScriptCMS Web';
$theme = $configData['theme'] ?? 'light';

$router = new Router(__DIR__ . '/content/pages');
$pagePath = $router->getPagePath();

$is404 = !is_file($pagePath);
if ($is404) {
    $security->logError(404, (string)($_SERVER['REQUEST_URI'] ?? '/'), 'Požadovaná stránka nebo soubor nebyl nalezen.', $pagePath, 'medium');
}

// ==========================================
// POČÍTADLO NÁVŠTĚV A BOTŮ (POUZE NE-404)
// ==========================================
if (!$is404) {
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $isBot = (bool)preg_match('/(bot|crawl|slurp|spider|mediapartners|curl|wget)/i', $userAgent);

    $counterFile = __DIR__ . '/config/counter.json';
    
    // Zajištění existence souboru
    if (!file_exists($counterFile)) {
        @mkdir(dirname($counterFile), 0755, true);
        @file_put_contents($counterFile, json_encode([
            'ips' => [],
            'bots_ips' => [],
            'all_time_total' => 0,
            'all_time_bots' => 0,
            'total' => 0,
            'bots_total' => 0
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] 
        ?? $_SERVER['HTTP_CLIENT_IP'] 
        ?? $_SERVER['REMOTE_ADDR'] 
        ?? '0.0.0.0';
    $clientIp = trim(explode(',', $clientIp)[0]);

    $nowTime = time();

    $fp = @fopen($counterFile, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $fileSize = filesize($counterFile);
        $rawJson = ($fileSize > 0) ? fread($fp, $fileSize) : '';
        
        $counterData = json_decode((string)$rawJson, true);
        if (!is_array($counterData)) {
            $counterData = [];
        }

        // Zajištění výchozích hodnot
        $counterData['ips'] = $counterData['ips'] ?? [];
        $counterData['bots_ips'] = $counterData['bots_ips'] ?? [];
        $counterData['all_time_total'] = (int)($counterData['all_time_total'] ?? 0);
        $counterData['all_time_bots'] = (int)($counterData['all_time_bots'] ?? 0);

        // Promazání starých IP (nad 24h / 86400 sekund)
        foreach ($counterData['ips'] as $ip => $ts) {
            if ($nowTime - $ts > 86400) unset($counterData['ips'][$ip]);
        }
        foreach ($counterData['bots_ips'] as $ip => $ts) {
            if ($nowTime - $ts > 86400) unset($counterData['bots_ips'][$ip]);
        }

        $updated = false;

        if ($isBot) {
            if (!isset($counterData['bots_ips'][$clientIp])) {
                $counterData['bots_ips'][$clientIp] = $nowTime;
                $counterData['all_time_bots']++;
                $updated = true;
            }
        } else {
            if (!isset($counterData['ips'][$clientIp])) {
                $counterData['ips'][$clientIp] = $nowTime;
                $counterData['all_time_total']++; // Celkově stoupá jen při nové IP
                $updated = true;
            }
        }

        // Aktualizace aktuálního denního počtu podle aktivních IP v okně 24h
        $counterData['total'] = count($counterData['ips']);
        $counterData['bots_total'] = count($counterData['bots_ips']);

        // Uložení dat, pokud proběhla změna
        if ($updated) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($counterData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Načtení obsahu stránky
$content = !$is404
    ? (string) file_get_contents($pagePath)
    : "# 404\nStránka nebyla nalezena.";

$contentHtml = Content::render($content);

require __DIR__ . '/templates/default/header.php';
echo $contentHtml;
require __DIR__ . '/templates/default/footer.php';