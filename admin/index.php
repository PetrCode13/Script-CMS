<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/version.php';
require_once __DIR__ . '/../core/Content.php';
require_once __DIR__ . '/../core/Updater.php';
require_once __DIR__ . '/../core/Security.php';

$auth = new Auth(__DIR__ . '/../config/users.json');

if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'] ?? 'editor';
$isAdmin = $role === 'admin';
$csrfToken = $auth->generateCsrfToken();

$pagesDir = __DIR__ . '/../content/pages';
$configFile = __DIR__ . '/../config/config.json';
$usersFile = __DIR__ . '/../config/users.json';
$counterFile = __DIR__ . '/../config/counter.json';
$security = new Security(__DIR__ . '/..');

if (!is_dir($pagesDir)) {
    mkdir($pagesDir, 0755, true);
}

$configData = json_decode(
    (string) @file_get_contents($configFile),
    true
) ?: [];

$usersData = json_decode(
    (string) @file_get_contents($usersFile),
    true
) ?: [];

/*
 * ============================================================
 * Pomocné funkce
 * ============================================================
 */

/**
 * Bezpečně uloží JSON soubor.
 */
function saveJson(string $file, array $data): void
{
    file_put_contents(
        $file,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}

/**
 * Bezpečné přesměrování.
 */
function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Ověří, zda je název Markdown souboru bezpečný.
 *
 * Povoluje:
 * - českou diakritiku
 * - mezery
 * - běžné znaky
 * - tečky
 * - pomlčky
 * - podtržítka
 *
 * Zakazuje:
 * - /
 * - \
 * - NUL byte
 * - řízení znaků
 * - . a ..
 * - názvy bez .md
 */
function isValidPageFilename(string $filename): bool
{
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return false;
    }

    if (!str_ends_with(strtolower($filename), '.md')) {
        return false;
    }

    if (str_contains($filename, '/') || str_contains($filename, '\\')) {
        return false;
    }

    if (str_contains($filename, "\0")) {
        return false;
    }

    /*
     * Zakázání řídicích znaků.
     * Česká UTF-8 diakritika je zde naprosto v pořádku.
     */
    if (preg_match('/[\x00-\x1F\x7F]/u', $filename)) {
        return false;
    }

    return true;
}

/**
 * Bezpečně připraví název stránky.
 *
 * Na rozdíl od původního kódu:
 * - nemaže českou diakritiku
 * - nemaže mezery
 * - nemaže česká písmena
 */
function normalizePageFilename(string $filename): string
{
    $filename = trim($filename);

    /*
     * Odstraníme pouze potenciálně nebezpečné
     * oddělovače cest a NUL byte.
     */
    $filename = str_replace(
        ["\0", '/', '\\'],
        '',
        $filename
    );

    /*
     * Odstranění řídicích znaků.
     * Diakritika ani mezery se nemažou.
     */
    $filename = preg_replace(
        '/[\x00-\x1F\x7F]/u',
        '',
        $filename
    ) ?? $filename;

    $filename = trim($filename);

    if ($filename === '') {
        return '';
    }

    /*
     * Pokud uživatel nezadal .md,
     * automaticky ho doplníme.
     */
    if (!str_ends_with(strtolower($filename), '.md')) {
        $filename .= '.md';
    }

    return $filename;
}

/**
 * Bezpečně získá název souboru z GET parametru edit.
 */
function getSelectedPageFilename(string $default = 'index.md'): string
{
    $filename = trim(
        (string) ($_GET['edit'] ?? $default)
    );

    if (!isValidPageFilename($filename)) {
        return $default;
    }

    return $filename;
}

/*
 * ============================================================
 * Počítadlo návštěv
 * ============================================================
 */

$counterData = [
    'total' => 0,
    'ips' => [],
    'all_time_total' => 0,
    'bots_total' => 0
];

if (file_exists($counterFile)) {
    $loadedCounter = json_decode(
        (string) @file_get_contents($counterFile),
        true
    );

    if (is_array($loadedCounter)) {
        $counterData = array_merge(
            $counterData,
            $loadedCounter
        );
    }
}

$totalVisits = (int) ($counterData['total'] ?? 0);
$allTimeTotal = (int) (
    $counterData['all_time_total'] ?? $totalVisits
);
$totalBots = (int) (
    $counterData['bots_total'] ?? 0
);

/*
 * Unikátní návštěva podle IP po dobu jedné hodiny.
 */
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$nowTime = time();

if (!isset($counterData['ips']) || !is_array($counterData['ips'])) {
    $counterData['ips'] = [];
}

foreach ($counterData['ips'] as $ip => $ts) {
    if ($nowTime - (int) $ts > 3600) {
        unset($counterData['ips'][$ip]);
    }
}

if (!isset($counterData['ips'][$clientIp])) {
    $counterData['total'] = (int) ($counterData['total'] ?? 0) + 1;
    $counterData['all_time_total'] = max(
        (int) ($counterData['all_time_total'] ?? 0),
        (int) $counterData['total']
    );

    $counterData['ips'][$clientIp] = $nowTime;

    @file_put_contents(
        $counterFile,
        json_encode(
            $counterData,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}

$totalVisits = (int) ($counterData['total'] ?? 0);
$allTimeTotal = (int) (
    $counterData['all_time_total'] ?? $totalVisits
);
$totalBots = (int) (
    $counterData['bots_total'] ?? 0
);

/*
 * ============================================================
 * Zprávy
 * ============================================================
 */

$message = '';
$messageType = 'success';

/*
 * ============================================================
 * Odhlášení
 * ============================================================
 */

if (
    isset($_GET['action']) &&
    $_GET['action'] === 'logout'
) {
    $auth->logout();
    redirectTo('login.php');
}

/*
 * ============================================================
 * POST akce
 * ============================================================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !$auth->verifyCsrfToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {
        http_response_code(403);
        exit('Neplatný CSRF token.');
    }

    $action = (string) (
        $_POST['form_action'] ?? ''
    );

    /*
     * ========================================================
     * ULOŽENÍ STRÁNKY
     * ========================================================
     */

    if ($action === 'save_page') {

        /*
         * DŮLEŽITÁ OPRAVA:
         *
         * Nepoužíváme už:
         *
         * preg_replace('/[^a-zA-Z0-9._-]/', '', ...)
         *
         * protože by z:
         *
         * Ceník.md
         *
         * udělal:
         *
         * Cnik.md
         *
         * a z:
         *
         * O nás.md
         *
         * udělal:
         *
         * Ons.md
         *
         * či podobně.
         */

        $filename = normalizePageFilename(
            (string) ($_POST['filename'] ?? '')
        );

        if (!isValidPageFilename($filename)) {

            $message = 'Název stránky není platný. Použij název zakončený .md.';
            $messageType = 'error';

        } else {

            $rawHtml = (string) (
                $_POST['content'] ?? ''
            );

            /*
             * Odstranění našeho interního HTML markeru,
             * pokud se vrací z editoru.
             */
            $rawHtml = preg_replace(
                '/^\s*' .
                preg_quote(Content::HTML_MARKER, '/') .
                '\s*/',
                '',
                $rawHtml,
                1
            ) ?? $rawHtml;

            $cleanHtml = Content::sanitizeHtml(
                $rawHtml
            );

            if ($cleanHtml === '') {
                $cleanHtml = '<p></p>';
            }

            $saved =
                Content::HTML_MARKER .
                "\n" .
                $cleanHtml;

            /*
             * Název je již zbaven pouze nebezpečných
             * oddělovačů cesty.
             *
             * UTF-8 české znaky i mezery zůstávají.
             */
            $target = $pagesDir . DIRECTORY_SEPARATOR . $filename;

            /*
             * Dodatečná ochrana:
             * cesta musí zůstat přímo v pagesDir.
             */
            $realPagesDir = realpath($pagesDir);

            if ($realPagesDir === false) {
                $message = 'Adresář s obsahem neexistuje.';
                $messageType = 'error';

            } else {

                $targetBase = basename($target);

                /*
                 * basename() zde používáme pouze jako
                 * dodatečnou kontrolu výsledné cesty.
                 */
                if ($targetBase !== $filename) {

                    $message = 'Neplatný název souboru.';
                    $messageType = 'error';

                } else {

                    if (
                        file_put_contents(
                            $target,
                            $saved,
                            LOCK_EX
                        ) === false
                    ) {
                        $message =
                            'Stránku se nepodařilo uložit. ' .
                            'Zkontroluj práva zápisu.';
                        $messageType = 'error';

                    } else {

                        /*
                         * rawurlencode() správně převede:
                         *
                         * O nás.md
                         *
                         * na URL-safe hodnotu.
                         */
                        redirectTo(
                            'index.php?view=content&edit=' .
                            rawurlencode($filename) .
                            '&saved=1'
                        );
                    }
                }
            }
        }
    }

    /*
     * ========================================================
     * SMAZÁNÍ STRÁNKY
     * ========================================================
     */

    if ($action === 'delete_page') {

        $filename = trim(
            (string) ($_POST['filename'] ?? '')
        );

        /*
         * Systémové stránky nelze smazat.
         */
        if (
            $filename === 'index.md' ||
            $filename === '404.md'
        ) {

            $message =
                'Tuto systémovou stránku nelze smazat.';
            $messageType = 'error';

        } elseif (
            isValidPageFilename($filename) &&
            is_file(
                $pagesDir .
                DIRECTORY_SEPARATOR .
                $filename
            )
        ) {

            $target =
                $pagesDir .
                DIRECTORY_SEPARATOR .
                $filename;

            if (@unlink($target)) {

                redirectTo(
                    'index.php?view=content&deleted=1'
                );

            } else {

                $message =
                    'Stránku se nepodařilo smazat.';
                $messageType = 'error';
            }

        } else {

            $message = 'Stránka neexistuje.';
            $messageType = 'error';
        }
    }

    /*
     * ========================================================
     * VYTVOŘENÍ UŽIVATELE
     * ========================================================
     */

    if ($action === 'create_user') {

        if (!$isAdmin) {
            http_response_code(403);
            exit('Přístup odepřen.');
        }

        $username = trim(
            (string) ($_POST['username'] ?? '')
        );

        $password = (string) (
            $_POST['password'] ?? ''
        );

        $email = strtolower(
            trim(
                (string) ($_POST['email'] ?? '')
            )
        );

        $newRole =
            ($_POST['role'] ?? 'editor') === 'admin'
            ? 'admin'
            : 'editor';

        if (
            $username === '' ||
            $password === '' ||
            $email === ''
        ) {

            $message =
                'Vyplň uživatelské jméno, e-mail a heslo.';
            $messageType = 'error';

        } elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $message = 'Zadej platný e-mail.';
            $messageType = 'error';

        } elseif (
            isset($usersData[$username])
        ) {

            $message =
                'Tento uživatel již existuje.';
            $messageType = 'error';

        } else {

            $usersData[$username] = [
                'password' => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                'email' => $email,
                'role' => $newRole
            ];

            saveJson(
                $usersFile,
                $usersData
            );

            $message =
                'Uživatel byl vytvořen.';
        }
    }

    /*
     * ========================================================
     * SMAZÁNÍ UŽIVATELE
     * ========================================================
     */

    if ($action === 'delete_user') {

        if (!$isAdmin) {
            http_response_code(403);
            exit('Přístup odepřen.');
        }

        $username = (string) (
            $_POST['username'] ?? ''
        );

        if (
            $username ===
            ($_SESSION['user'] ?? '')
        ) {

            $message =
                'Aktuálně přihlášeného administrátora nelze smazat.';
            $messageType = 'error';

        } elseif (count($usersData) <= 1) {

            $message =
                'Nelze smazat poslední účet.';
            $messageType = 'error';

        } elseif (isset($usersData[$username])) {

            unset($usersData[$username]);

            saveJson(
                $usersFile,
                $usersData
            );

            $message =
                'Uživatel byl smazán.';
        }
    }

    /*
     * ========================================================
     * NASTAVENÍ
     * ========================================================
     */

    if ($action === 'security_save') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Přístup odepřen.');
        }

        $securityConfig = $security->config();
        foreach (['enabled','logging','intelligent','htaccess_enhanced','log_humans','log_bots','rate_limit'] as $key) {
            $securityConfig[$key] = isset($_POST[$key]);
        }

        if ($security->saveConfig($securityConfig)) {
            $security->writeHtaccess((bool)$securityConfig['htaccess_enhanced']);
            $message = 'Bezpečnostní konfigurace byla uložena.';
            $messageType = 'success';
        } else {
            $message = 'Bezpečnostní konfiguraci se nepodařilo uložit.';
            $messageType = 'error';
        }
    }

    if ($action === 'security_block_ip') {
        if (!$isAdmin || !$security->blockIp(trim((string)($_POST['ip'] ?? '')))) {
            $message = 'IP adresa není platná nebo se ji nepodařilo zablokovat.';
            $messageType = 'error';
        } else {
            $message = 'IP adresa byla zablokována.';
        }
    }

    if ($action === 'security_unblock_ip') {
        if (!$isAdmin || !$security->unblockIp(trim((string)($_POST['ip'] ?? '')))) {
            $message = 'IP adresu se nepodařilo odblokovat.';
            $messageType = 'error';
        } else {
            $message = 'IP adresa byla odblokována.';
        }
    }

    if ($action === 'security_block_path') {
        if (!$isAdmin || !$security->blockPath(trim((string)($_POST['path'] ?? '')))) {
            $message = 'Cestu se nepodařilo zablokovat.';
            $messageType = 'error';
        } else {
            $message = 'Požadavky na zadanou cestu budou odmítány.';
        }
    }

    if ($action === 'security_unblock_path') {
        if (!$isAdmin || !$security->unblockPath(trim((string)($_POST['path'] ?? '')))) {
            $message = 'Cestu se nepodařilo odblokovat.';
            $messageType = 'error';
        } else {
            $message = 'Cesta byla odblokována.';
        }
    }

    if ($action === 'security_clear_log') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Přístup odepřen.');
        }
        $security->clearEvents();
        $message = 'Bezpečnostní log byl vymazán.';
    }

    if ($action === 'security_chmod') {
        if (!$isAdmin || !$security->chmodFile(
            trim((string)($_POST['file'] ?? '')),
            trim((string)($_POST['mode'] ?? ''))
        )) {
            $message = 'Práva souboru se nepodařilo změnit.';
            $messageType = 'error';
        } else {
            $message = 'Práva souboru byla změněna.';
        }
    }
    if ($action === 'save_settings') {

        if (!$isAdmin) {
            http_response_code(403);
            exit('Přístup odepřen.');
        }

        $allowedThemes = [
            'light',
            'dark',
            'black',
            'dark-blue',
            'light-blue',
            'red',
            'green',
            'yellow',
            'brown'
        ];

        $theme = (string) (
            $_POST['theme'] ?? 'light'
        );

        if (
            !in_array(
                $theme,
                $allowedThemes,
                true
            )
        ) {
            $theme = 'light';
        }

        $configData['site_title'] =
            trim(
                (string) (
                    $_POST['site_title'] ??
                    'ScriptCMS Web'
                )
            ) ?: 'ScriptCMS Web';

        $configData['theme'] = $theme;

        saveJson(
            $configFile,
            $configData
        );

        $message =
            'Nastavení bylo uloženo.';
    }
}

/*
 * ============================================================
 * Stavové zprávy z URL
 * ============================================================
 */

if (isset($_GET['saved'])) {
    $message =
        'Stránka byla úspěšně uložena.';
    $messageType = 'success';
}

if (isset($_GET['deleted'])) {
    $message =
        'Stránka byla smazána.';
    $messageType = 'success';
}

/*
 * ============================================================
 * Seznam stránek
 * ============================================================
 */

$files = glob(
    $pagesDir . DIRECTORY_SEPARATOR . '*.md'
) ?: [];

/*
 * Seřazení podle názvu.
 *
 * SORT_LOCALE_STRING může být na serveru závislé
 * na locale, proto používáme strcasecmp.
 * Samotné UTF-8 názvy jsou ale plně zachovány.
 */
usort(
    $files,
    static function (
        string $a,
        string $b
    ): int {
        return strcasecmp(
            basename($a),
            basename($b)
        );
    }
);

/*
 * ============================================================
 * Aktuální pohled
 * ============================================================
 */

$view = (string) (
    $_GET['view'] ?? 'dashboard'
);

$allowedViews = [
    'dashboard',
    'content',
    'users',
    'settings',
    'security'
];

if ($view === 'updates') {
    redirectTo('update.php');
}

if (
    !in_array(
        $view,
        $allowedViews,
        true
    )
) {
    $view = 'dashboard';
}

/*
 * ============================================================
 * Vybraný soubor
 * ============================================================
 *
 * TADY JE HLAVNÍ OPRAVA.
 *
 * Už se nepoužívá ASCII regex:
 *
 * /^[a-zA-Z0-9._-]+\.md$/
 *
 * takže fungují:
 *
 * Ceník.md
 * O nás.md
 * Články 2026.md
 * Náš tým.md
 */

$selectedFile = getSelectedPageFilename(
    'index.md'
);

$filePath =
    $pagesDir .
    DIRECTORY_SEPARATOR .
    $selectedFile;

$fileContent = is_file($filePath)
    ? (string) file_get_contents($filePath)
    : '';

/*
 * Nová stránka.
 */
if (isset($_GET['new'])) {

    $selectedFile =
        'nova-stranka.md';

    $fileContent =
        Content::HTML_MARKER .
        "\n" .
        '<h1>Nová stránka</h1>' .
        '<p>Začněte psát obsah stránky.</p>';
}

$editorHtml =
    Content::toEditorHtml(
        $fileContent
    );

/*
 * ============================================================
 * Kontrola aktualizace
 * ============================================================
 */

$updateVersion = null;

if ($isAdmin) {

    $cacheTime = (int) (
        $_SESSION['scriptcms_update_check_time'] ?? 0
    );

    if (
        time() - $cacheTime > 60
    ) {

        $updater =
            new Updater(
                SCRIPTCMS_VERSION
            );

        $newest =
            $updater->getNewestAvailable();

        if (
            $newest !== null &&
            version_compare(
                $newest,
                SCRIPTCMS_VERSION,
                '>'
            )
        ) {

            $_SESSION[
                'scriptcms_new_version'
            ] = $newest;

        } else {

            unset(
                $_SESSION[
                    'scriptcms_new_version'
                ]
            );
        }

        $_SESSION[
            'scriptcms_update_check_time'
        ] = time();
    }

    if (
        isset(
            $_SESSION[
                'scriptcms_new_version'
            ]
        ) &&
        !version_compare(
            $_SESSION[
                'scriptcms_new_version'
            ],
            SCRIPTCMS_VERSION,
            '>'
        )
    ) {

        unset(
            $_SESSION[
                'scriptcms_new_version'
            ]
        );
    }

    $updateVersion =
        $_SESSION[
            'scriptcms_new_version'
        ] ?? null;
}

/*
 * ============================================================
 * Konfigurace vzhledu
 * ============================================================
 */

$configTheme =
    $configData['theme'] ?? 'light';

$siteTitle =
    $configData['site_title'] ??
    'ScriptCMS Web';

$securityConfig = $security->config();
$securityEvents = $security->events(250);
$securityScore = $security->securityScore();
$securityErrors = $security->errorSummary();
$securityFile = trim((string)($_GET['security_file'] ?? ''));
$securityFileInfo = $securityFile !== '' ? $security->fileInfo($securityFile) : null;

?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>ScriptCMS ™ — Administrace</title>

<style>
:root{
 --bg:#f4f7fb;
 --card:#fff;
 --text:#122033;
 --muted:#64748b;
 --border:#dbe3ee;
 --primary:#0878c9;
 --primary2:#075b9a;
 --success:#16803c;
 --danger:#c62828;
 --shadow:0 8px 28px rgba(15,23,42,.07);
 --radius:14px
}

*{
 box-sizing:border-box
}

body{
 margin:0;
 background:var(--bg);
 color:var(--text);
 font-family:
 system-ui,
 -apple-system,
 BlinkMacSystemFont,
 "Segoe UI",
 sans-serif
}

a{
 color:inherit
}

.admin{
 min-height:100vh
}

.topbar{
 height:68px;
 background:var(--card);
 border-bottom:1px solid var(--border);
 display:flex;
 align-items:center;
 padding:0 22px;
 gap:18px;
 position:sticky;
 top:0;
 z-index:50;
 box-shadow:0 2px 12px rgba(15,23,42,.04)
}

.brand{
 font-size:1.1rem;
 font-weight:800;
 color:var(--primary);
 white-space:nowrap
}

.version{
 font-size:.78rem;
 color:var(--muted);
 font-weight:600
}

.menu{
 display:flex;
 align-items:center;
 gap:5px;
 margin-left:auto
}

.menu a{
 padding:9px 11px;
 border-radius:9px;
 text-decoration:none;
 font-weight:650;
 font-size:.93rem;
 position:relative
}

.menu a:hover,
.menu a.active{
 background:#edf6fd;
 color:var(--primary)
}

.menu .logout{
 color:var(--danger)
}

.hamburger{
 display:none;
 margin-left:auto;
 background:transparent;
 border:1px solid var(--border);
 border-radius:9px;
 font-size:1.35rem;
 padding:7px 11px;
 cursor:pointer
}

.shell{
 max-width:1250px;
 margin:0 auto;
 padding:24px
}

.page-head{
 display:flex;
 align-items:flex-start;
 justify-content:space-between;
 gap:20px;
 margin-bottom:20px
}

.page-head h1{
 margin:0 0 5px;
 font-size:1.65rem
}

.page-head p{
 margin:0;
 color:var(--muted)
}

.grid{
 display:grid;
 grid-template-columns:repeat(4,minmax(0,1fr));
 gap:15px
}

.card{
 background:var(--card);
 border:1px solid var(--border);
 border-radius:var(--radius);
 box-shadow:var(--shadow);
 padding:20px;
 margin-bottom:18px
}

.stat{
 padding:18px
}

.stat strong{
 display:block;
 font-size:1.8rem;
 margin-top:4px
}

.stat span{
 color:var(--muted);
 font-size:.88rem
}

.notice{
 border:1px solid #b9dcf7;
 background:#eef8ff;
 color:#0b568a;
 border-radius:12px;
 padding:16px 18px;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:16px;
 margin-bottom:18px
}

.notice strong{
 display:block;
 margin-bottom:3px
}

.btn{
 display:inline-flex;
 align-items:center;
 justify-content:center;
 gap:7px;
 border:0;
 border-radius:9px;
 padding:10px 14px;
 background:var(--primary);
 color:#fff;
 text-decoration:none;
 font-weight:700;
 cursor:pointer
}

.btn:hover{
 background:var(--primary2)
}

.btn.success{
 background:var(--success)
}

.btn.danger{
 background:var(--danger)
}

.btn.secondary{
 background:#e9eff5;
 color:var(--text)
}

.btn.full{
 width:100%
}

.layout{
 display:grid;
 grid-template-columns:300px minmax(0,1fr);
 gap:18px
}

.card h2,
.card h3{
 margin:0 0 14px
}

.muted{
 color:var(--muted)
}

input,
select{
 font:inherit;
 width:100%;
 border:1px solid var(--border);
 border-radius:9px;
 padding:10px 11px;
 margin:6px 0 12px;
 background:#fff;
 color:var(--text)
}

label{
 font-size:.9rem;
 font-weight:700
}

.page-list{
 list-style:none;
 padding:0;
 margin:12px 0 0
}

.page-list li{
 display:flex;
 align-items:center;
 gap:6px;
 margin:4px 0
}

.page-link{
 flex:1;
 text-decoration:none;
 padding:9px;
 border-radius:8px;
 word-break:break-word;
 overflow-wrap:anywhere
}

.page-link:hover,
.page-link.active{
 background:#edf6fd;
 color:var(--primary);
 font-weight:700
}

.delete-form{
 margin:0
}

.delete-btn{
 background:#fff1f1;
 color:var(--danger);
 border:0;
 border-radius:7px;
 padding:7px 9px;
 cursor:pointer;
 font-weight:700
}

table{
 width:100%;
 border-collapse:collapse
}

th,
td{
 text-align:left;
 padding:12px 9px;
 border-bottom:1px solid var(--border)
}

th{
 font-size:.8rem;
 color:var(--muted);
 text-transform:uppercase
}

.msg{
 padding:13px 15px;
 border-radius:10px;
 margin-bottom:18px;
 background:#ecfdf3;
 color:#166534;
 border:1px solid #bbf7d0;
 display:flex;
 justify-content:space-between;
 gap:12px
}

.msg.error{
 background:#fff1f2;
 color:#9f1239;
 border-color:#fecdd3
}

.close{
 border:0;
 background:transparent;
 font-size:1.2rem;
 cursor:pointer;
 color:inherit
}

.editor{
 border:1px solid var(--border);
 border-radius:12px;
 overflow:visible;
 background:#fff;
 position:relative
}

.toolbar{
 display:flex;
 flex-wrap:wrap;
 gap:6px;
 padding:9px;
 background:#f7f9fc;
 border-bottom:1px solid var(--border);
 position:sticky;
 top:68px;
 z-index:30;
 box-shadow:0 4px 6px -1px rgba(0,0,0,.03);
 border-top-left-radius:11px;
 border-top-right-radius:11px
}

.tool{
 border:1px solid var(--border);
 background:#fff;
 border-radius:7px;
 padding:7px 9px;
 cursor:pointer;
 font-weight:700;
 color:var(--text)
}

.tool:hover{
 background:#eaf2f8
}

.separator{
 width:1px;
 background:var(--border);
 margin:0 2px
}

.editor-area{
 min-height:520px;
 height:auto;
 overflow-y:hidden;
 padding:22px;
 outline:none;
 line-height:1.65;
 font-size:16px;
 scroll-margin-top:140px;
 overflow-wrap:anywhere
}

.editor-area:focus{
 box-shadow:
 inset 0 0 0 2px rgba(8,120,201,.08)
}

.editor-area img{
 max-width:100%;
 height:auto
}

.editor-area table{
 width:100%;
 border-collapse:collapse;
 margin:15px 0;
 border:1px solid var(--border)
}

.editor-area th{
 background:#f8fafc;
 font-weight:700;
 text-align:left;
 border:1px solid var(--border);
 padding:10px
}

.editor-area td{
 border:1px solid var(--border);
 padding:10px
}

.editor-area:empty:before{
 content:"Začněte psát…";
 color:#94a3b8
}

.editor-actions{
 display:flex;
 justify-content:space-between;
 gap:10px;
 margin-top:13px
}

.help{
 font-size:.86rem;
 color:var(--muted);
 margin-top:10px
}

.update-badge{
 display:inline-block;
 width:8px;
 height:8px;
 background-color:#16803c;
 border-radius:50%;
 margin-left:6px;
 vertical-align:middle
}


/* ============================================================
   ScriptCMS Security Center — tmavé kartové prostředí
   ============================================================ */
.security-center{--sec-bg:#090d14;--sec-card:#111823;--sec-card2:#0d131d;--sec-border:#253246;--sec-text:#edf4ff;--sec-muted:#8ea0b8;--sec-blue:#55a8ff;--sec-green:#36d399;--sec-red:#ff5d73;--sec-yellow:#f6c453;background:var(--sec-bg);color:var(--sec-text);border:1px solid #182230;border-radius:18px;padding:20px;box-shadow:0 18px 55px rgba(0,0,0,.18)}
.security-center .page-head{margin-bottom:20px}.security-center .page-head h1{color:#fff}
.sec-score{background:linear-gradient(145deg,#172337,#0d141f);border:1px solid #2b3b52;border-radius:15px;padding:14px 18px;text-align:right;min-width:150px}.sec-score span{display:block;color:var(--sec-muted);font-size:.78rem}.sec-score strong{display:block;font-size:1.8rem;color:var(--sec-green)}
.security-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}
.security-card{background:var(--sec-card);border:1px solid var(--sec-border);border-radius:14px;padding:15px;display:flex;gap:12px;align-items:center;min-height:92px}.security-card .sec-icon{font-size:1.55rem}.security-card span{display:block;color:var(--sec-muted);font-size:.75rem}.security-card strong{display:block;font-size:1.25rem;margin:2px 0}.security-card small{display:block;color:#6f819a;font-size:.72rem}
.security-columns{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(320px,.9fr);gap:18px}.security-panel{background:var(--sec-card2);border:1px solid var(--sec-border);border-radius:15px;padding:17px;margin-bottom:16px}.security-panel h2{color:#fff;margin:0 0 3px}.security-panel h3{color:#dbe7f7;margin:18px 0 9px}.panel-title{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:13px}.panel-title p{color:var(--sec-muted);margin:0;font-size:.82rem}
.event-list{display:grid;gap:9px}.event-card{background:#111a26;border:1px solid #263449;border-left:4px solid var(--sec-blue);border-radius:12px;padding:12px}.event-card.priority-critical{border-left-color:#ff3150}.event-card.priority-high{border-left-color:var(--sec-red)}.event-card.priority-medium{border-left-color:var(--sec-yellow)}.event-card.priority-info{border-left-color:var(--sec-blue)}
.event-top{display:flex;align-items:center;gap:9px;flex-wrap:wrap;font-size:.82rem}.event-time{margin-left:auto;color:#71839b;font-size:.72rem}.priority-badge{border-radius:999px;padding:3px 7px;font-size:.65rem;font-weight:800;background:#1d2a3b;color:#9ecbff}.priority-critical .priority-badge{background:#421521;color:#ff9aaa}.priority-high .priority-badge{background:#3c1b23;color:#ff9aaa}.priority-medium .priority-badge{background:#3d3119;color:#ffd978}
.event-main{padding-top:8px;font-size:.84rem}.event-main b{font-size:1rem}.event-meta{display:flex;gap:12px;flex-wrap:wrap;color:#7f92aa;margin-top:7px;font-size:.73rem}.event-detail{margin-top:9px;border-top:1px solid #223044;padding-top:7px}.event-detail summary{cursor:pointer;color:#9fc9f5;font-size:.78rem}.detail-box{background:#0a1018;border:1px solid #1e2a3a;border-radius:10px;padding:11px;margin-top:8px;color:#b7c5d7;font-size:.76rem}.detail-box p{margin:5px 0}.event-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.event-actions form{margin:0}.event-actions .btn{font-size:.73rem;padding:7px 9px}
.error-code-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.error-code-grid>div{background:#121b28;border:1px solid #253349;border-radius:10px;padding:12px}.error-code-grid b{font-size:1.25rem}.error-code-grid span{display:block;color:var(--sec-muted);font-size:.72rem}.error-code-grid strong{display:block;margin-top:3px}
.toggle-list{display:grid;gap:7px}.toggle-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px;background:#111a26;border:1px solid #223045;border-radius:10px;cursor:pointer}.toggle-row span{min-width:0}.toggle-row b{display:block;font-size:.8rem}.toggle-row small{display:block;color:#70829a;font-size:.68rem;margin-top:2px}.toggle-row input{position:absolute;opacity:0;width:1px;height:1px}.toggle-row i{width:42px;height:23px;border-radius:999px;background:#263448;position:relative;flex:0 0 auto}.toggle-row i:after{content:"";position:absolute;width:17px;height:17px;border-radius:50%;background:#9aaabd;left:3px;top:3px;transition:.15s}.toggle-row input:checked+i{background:#176e4d}.toggle-row input:checked+i:after{left:22px;background:#fff}
.warning-box{background:#211d12;border:1px solid #4d3f20;color:#d8c79b;border-radius:10px;padding:10px;margin-top:12px;font-size:.74rem}.warning-box p{margin:5px 0 0}.warning-box code{color:#ffe49a}
.security-center input,.security-center select{background:#0b121b;color:#e8f0fa;border-color:#2b3b50}.security-center .btn.secondary{background:#253247;color:#e7eff8}.security-center .btn.success{background:#13734f}.security-center .btn.danger{background:#9f2840}.chip-list{display:flex;flex-wrap:wrap;gap:7px}.chip{display:inline-flex;align-items:center;gap:6px;background:#192333;border:1px solid #2a3a51;padding:5px 8px;border-radius:999px;font-size:.72rem}.chip form{margin:0}.chip button{border:0;background:transparent;color:#ff8295;cursor:pointer;font-size:1rem}.ip-table{display:grid;gap:5px}.ip-table>div{display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:center;background:#111a26;border:1px solid #202d40;border-radius:8px;padding:7px 8px}.ip-table code{color:#a9cfff;font-size:.72rem;word-break:break-all}.ip-table span{color:#65778e;font-size:.68rem}.mini-btn{border:0;border-radius:7px;padding:5px 7px;cursor:pointer;font-size:.68rem;color:#fff}.mini-btn.danger{background:#8f2940}.file-meta{display:flex;gap:10px;flex-wrap:wrap;color:#8497af;font-size:.72rem;margin-bottom:12px}.source-view{max-height:520px;overflow:auto;background:#080c12;border:1px solid #1e2a39;border-radius:10px;padding:13px;color:#c9d7e8;font-size:.72rem;line-height:1.5;white-space:pre-wrap;word-break:break-word}.chmod-form{display:flex;gap:8px;align-items:end;margin-bottom:12px}.chmod-form label{display:block;flex:1}.chmod-form select{margin:6px 0 0}.empty-sec{padding:20px;text-align:center;color:#73859b;border:1px dashed #2a3a50;border-radius:10px}
@media(max-width:1100px){.security-grid{grid-template-columns:repeat(3,1fr)}.security-columns{grid-template-columns:1fr}}
@media(max-width:650px){.security-center{padding:12px}.security-grid{grid-template-columns:1fr 1fr}.error-code-grid{grid-template-columns:1fr}.security-card{min-height:78px}.sec-score{width:100%;text-align:left}.chmod-form{flex-direction:column;align-items:stretch}}
@media(max-width:430px){.security-grid{grid-template-columns:1fr}.event-time{margin-left:0}.ip-table>div{grid-template-columns:1fr auto}.ip-table form{grid-column:1/-1}.ip-table .mini-btn{width:100%}}

@media(max-width:900px){
 .grid{
  grid-template-columns:repeat(2,1fr)
 }

 .layout{
  grid-template-columns:1fr
 }
}

@media(max-width:720px){

 .topbar{
  padding:0 14px
 }

 .hamburger{
  display:block
 }

 .menu{
  display:none;
  position:absolute;
  top:68px;
  left:10px;
  right:10px;
  background:var(--card);
  border:1px solid var(--border);
  border-radius:12px;
  padding:8px;
  box-shadow:var(--shadow);
  flex-direction:column;
  align-items:stretch
 }

 .menu.open{
  display:flex
 }

 .menu a{
  margin:1px 0
 }

 .shell{
  padding:15px
 }

 .grid{
  grid-template-columns:1fr 1fr
 }

 .page-head{
  flex-direction:column
 }

 .toolbar{
  top:68px
 }

 .editor-area{
  min-height:420px;
  padding:15px
 }
}

@media(max-width:430px){

 .grid{
  grid-template-columns:1fr
 }

 .notice{
  align-items:flex-start;
  flex-direction:column
 }

 .editor-actions{
  flex-direction:column
 }

 .btn{
  width:100%
 }
}

/* ==========================================================
   Security Center v2 — full dark surface, no white canvas
   ========================================================== */
body.security-mode{background:#070b11;color:#eaf2ff}
body.security-mode .topbar{background:#0b111a;border-color:#1b2737;box-shadow:0 2px 20px rgba(0,0,0,.35)}
body.security-mode .brand{color:#62b2ff}
body.security-mode .version{color:#73869e}
body.security-mode .menu a{color:#b9c8da}
body.security-mode .menu a:hover,body.security-mode .menu a.active{background:#162235;color:#fff}
body.security-mode .shell{max-width:none;width:100%;margin:0;padding:24px 28px 45px;background:#070b11;min-height:calc(100vh - 68px)}
.security-center{max-width:1500px;margin:0 auto;background:#070b11;color:#eaf2ff;border:0;border-radius:0;padding:0;box-shadow:none}
.security-head{display:flex;align-items:flex-start;justify-content:space-between;gap:25px;margin:0 0 18px;padding:6px 2px}
.security-kicker{font-size:.68rem;letter-spacing:.16em;color:#5e7c9e;font-weight:900;margin-bottom:5px}
.security-head h1{margin:0 0 5px;color:#fff;font-size:1.75rem}
.security-head p{margin:0;color:#8193aa;font-size:.86rem;max-width:780px}
.sec-score{min-width:165px;background:#0e1621;border:1px solid #223147;border-radius:15px;padding:13px 16px;text-align:right}
.sec-score span,.sec-score small{display:block;color:#71839a;font-size:.7rem}.sec-score strong{display:block;color:#72baff;font-size:1.75rem;line-height:1.1;margin:3px 0}
.security-tabs{display:flex;gap:6px;flex-wrap:wrap;background:#0b121c;border:1px solid #1d2a3c;border-radius:14px;padding:7px;margin-bottom:18px;position:sticky;top:68px;z-index:30}
.security-tabs a{display:flex;align-items:center;gap:7px;padding:9px 12px;border-radius:9px;color:#8fa1b8;text-decoration:none;font-size:.82rem;font-weight:750}
.security-tabs a:hover{background:#111d2c;color:#e9f3ff}.security-tabs a.active{background:#18304b;color:#fff;box-shadow:inset 0 0 0 1px #2d5a86}.security-tabs a span{font-size:1rem}
.security-grid-compact{grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:18px}.security-grid-compact .security-card{min-height:88px}
.security-two-col{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(330px,.75fr);gap:18px}
.security-panel{background:#0d151f;border:1px solid #1f2c3e;border-radius:14px;padding:17px;margin-bottom:16px;box-shadow:0 10px 30px rgba(0,0,0,.16)}
.security-panel h2{color:#f5f9ff;margin:0 0 3px;font-size:1.05rem}.security-panel h3{color:#dbe8f8;margin:18px 0 9px}.panel-title{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:13px}.panel-title p{color:#74879f;margin:0;font-size:.78rem}
.status-pill,.count-badge,.login-badge,.code-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;font-size:.66rem;font-weight:900;letter-spacing:.04em;padding:4px 8px}.status-pill.ok{background:#0e3c2e;color:#5ce0b0;border:1px solid #17634b}.status-pill.off{background:#321824;color:#ff8297;border:1px solid #653044}.count-badge{background:#172538;border:1px solid #2b425f;color:#9fc9f5}
.quick-links{display:grid;grid-template-columns:1fr 1fr;gap:8px}.quick-links a{padding:11px;background:#111d2b;border:1px solid #22344a;border-radius:9px;text-decoration:none;color:#c8d8eb;font-size:.78rem;font-weight:750}.quick-links a:hover{background:#17273a;border-color:#315274}
.security-status-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #192536;color:#a9b9cc;font-size:.78rem}.security-status-row:last-child{border-bottom:0}
.security-table-wrap{overflow:auto;border:1px solid #1d2b3d;border-radius:10px}.security-table{width:100%;border-collapse:collapse;min-width:820px;font-size:.74rem}.security-table th{background:#111c2a;color:#7890aa;text-align:left;text-transform:uppercase;font-size:.62rem;letter-spacing:.06em}.security-table th,.security-table td{padding:10px;border-bottom:1px solid #1a2738;vertical-align:top}.security-table tr:last-child td{border-bottom:0}.security-table tr:hover td{background:#101a27}.security-table code{color:#a9d0f9;word-break:break-word}.security-table td{color:#aebdce}.ua-cell{max-width:310px;word-break:break-word;color:#71859d!important}
.inline-form{display:inline;margin:0}.mini-btn{border:0;border-radius:7px;padding:6px 9px;cursor:pointer;font-size:.67rem;color:#fff;font-weight:800}.mini-btn.danger{background:#8f2940}.mini-btn.secondary{background:#2a3b51;text-decoration:none;display:inline-block}.log-row{display:grid;grid-template-columns:145px 125px 45px 105px minmax(0,1fr);gap:9px;padding:9px 2px;border-bottom:1px solid #1a2738;font-size:.73rem;align-items:start}.log-row:last-child{border-bottom:0}.log-row span:first-child{color:#71869e}.log-row code{color:#9ec9f5}.log-row strong{color:#c6d6e8;word-break:break-word}
.error-code-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.error-code-grid>div{background:#101a27;border:1px solid #223247;border-radius:10px;padding:12px}.error-code-grid b{font-size:1.25rem;color:#fff;display:block}.error-code-grid span{color:#71849a;font-size:.7rem}.error-code-grid strong{display:block;margin-top:5px;color:#9fc9f5;font-size:1rem}.code-badge{min-width:40px}.code-403{background:#3b1720;color:#ff8397}.code-404{background:#332b14;color:#f7cf65}.code-500{background:#3b1b17;color:#ff947c}.code-200{background:#0d362a;color:#5ce0b0}.code-0{background:#1a2736;color:#9eb0c4}
.login-badge.login-ok{background:#0d3a2c;color:#58dfae}.login-badge.login-bad{background:#3a1720;color:#ff8298}
.security-settings-form{display:grid;gap:5px}.toggle-row{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:13px 12px;background:#101a26;border:1px solid #1e2d40;border-radius:10px;cursor:pointer}.toggle-row span b{display:block;color:#dce8f6;font-size:.8rem}.toggle-row span small{display:block;color:#70839a;font-size:.68rem;margin-top:2px}.toggle-row input{display:none}.toggle-row i{width:43px;height:24px;background:#263447;border-radius:999px;position:relative;flex:none}.toggle-row i:after{content:"";position:absolute;width:18px;height:18px;top:3px;left:3px;background:#8da0b5;border-radius:50%;transition:.18s}.toggle-row input:checked+i{background:#125c46}.toggle-row input:checked+i:after{left:22px;background:#55dfae}
.stack-form{display:grid;grid-template-columns:1fr auto;gap:7px;margin-bottom:9px}.stack-form input{margin:0;background:#0a111a!important;color:#e8f1fb!important;border:1px solid #26384e!important}.stack-form .btn{white-space:nowrap}.chip-list{display:flex;flex-wrap:wrap;gap:7px}.chip{display:inline-flex;align-items:center;gap:6px;background:#172334;border:1px solid #2a3b53;padding:5px 8px;border-radius:999px;font-size:.7rem;color:#b8c9db}.chip form{margin:0}.chip button{border:0;background:transparent;color:#ff8295;cursor:pointer;font-size:1rem}.empty-sec{padding:22px;text-align:center;color:#71849b;border:1px dashed #2a3b52;border-radius:10px}.source-view{max-height:520px;overflow:auto;background:#080c12;border:1px solid #1e2a39;border-radius:10px;padding:13px;color:#c9d7e8;font-size:.72rem;line-height:1.5;white-space:pre-wrap;word-break:break-word}.file-meta{display:flex;gap:10px;flex-wrap:wrap;color:#8497af;font-size:.72rem;margin-bottom:12px}.chmod-form{display:flex;gap:8px;align-items:end;margin-bottom:12px}.chmod-form label{display:block;flex:1;color:#9db0c5;font-size:.76rem}.chmod-form select{margin-top:6px;width:100%}
body.security-mode .security-center input,body.security-mode .security-center select{background:#0a111a;color:#e8f1fb;border-color:#26384e}body.security-mode .security-center .btn.secondary{background:#26384b;color:#e7eff8}body.security-mode .security-center .btn.success{background:#13734f}body.security-mode .security-center .btn.danger{background:#9f2840}
@media(max-width:1250px){.security-grid-compact{grid-template-columns:repeat(3,1fr)}}
@media(max-width:950px){.security-two-col{grid-template-columns:1fr}.security-tabs{position:static}.security-head{flex-direction:column}.sec-score{text-align:left;width:100%}}
@media(max-width:650px){body.security-mode .shell{padding:14px 10px 30px}.security-grid-compact{grid-template-columns:1fr 1fr}.error-code-grid{grid-template-columns:1fr 1fr}.quick-links{grid-template-columns:1fr}.stack-form{grid-template-columns:1fr}.log-row{grid-template-columns:1fr 1fr}.security-tabs{overflow-x:auto;flex-wrap:nowrap}.security-tabs a{white-space:nowrap}.security-head h1{font-size:1.45rem}}
@media(max-width:430px){.security-grid-compact{grid-template-columns:1fr}.error-code-grid{grid-template-columns:1fr}.security-panel{padding:13px}.log-row{grid-template-columns:1fr}.security-tabs a{padding:8px 10px}}

</style>
</head>

<body class="<?= $view === 'security' ? 'security-mode' : '' ?>">

<div class="admin">

<header class="topbar">

<a
 class="brand"
 href="index.php?view=dashboard"
 style="text-decoration:none"
>
 ScriptCMS ™
</a>

<span class="version">
 v<?= htmlspecialchars(
     SCRIPTCMS_VERSION,
     ENT_QUOTES,
     'UTF-8'
 ) ?>
</span>

<button
 class="hamburger"
 type="button"
 id="menuButton"
 aria-label="Otevřít menu"
 aria-expanded="false"
>
 ☰
</button>

<nav class="menu" id="adminMenu">

<a
 href="index.php?view=dashboard"
 class="<?= $view === 'dashboard' ? 'active' : '' ?>"
>
 Přehled
</a>

<a
 href="index.php?view=content"
 class="<?= $view === 'content' ? 'active' : '' ?>"
>
 Obsah
</a>

<?php if ($isAdmin): ?>

<a
 href="index.php?view=users"
 class="<?= $view === 'users' ? 'active' : '' ?>"
>
 Uživatelé
</a>

<a
 href="index.php?view=settings"
 class="<?= $view === 'settings' ? 'active' : '' ?>"
>
 Nastavení
</a>

<a
 href="index.php?view=security"
 class="<?= $view === 'security' ? 'active' : '' ?>"
>
Zabezpečení
</a>

<a
 href="update.php"
 class="<?= $view === 'updates' ? 'active' : '' ?>"
>
 Aktualizace
 <?php if ($updateVersion): ?>
  <span class="update-badge"></span>
 <?php endif; ?>
</a>

<?php endif; ?>

<a
 class="logout"
 href="index.php?action=logout"
>
 Odhlásit se
</a>

</nav>

</header>

<main class="shell">

<?php if ($message): ?>

<div
 class="msg <?= $messageType === 'error' ? 'error' : '' ?>"
>

<span>
<?= htmlspecialchars(
    $message,
    ENT_QUOTES,
    'UTF-8'
) ?>
</span>

<button
 class="close"
 type="button"
 onclick="this.parentElement.remove()"
>
 ×
</button>

</div>

<?php endif; ?>


<?php if ($view === 'dashboard'): ?>

<div class="page-head">

<div>

<h1>Přehled</h1>

<p>
 Vítej,
 <?= htmlspecialchars(
     (string) ($_SESSION['user'] ?? 'uživateli'),
     ENT_QUOTES,
     'UTF-8'
 ) ?>.
</p>

</div>

<a
 class="btn"
 href="index.php?view=content&new=1"
>
 ＋ Nová stránka
</a>

</div>


<?php if ($isAdmin && $updateVersion): ?>

<div class="notice">

<div>

<strong>
 Je dostupná nová verze ScriptCMS ™:
 v<?= htmlspecialchars(
     $updateVersion,
     ENT_QUOTES,
     'UTF-8'
 ) ?>
</strong>

<span>
 Aktuálně používáš
 v<?= htmlspecialchars(
     SCRIPTCMS_VERSION,
     ENT_QUOTES,
     'UTF-8'
 ) ?>.
</span>

</div>

<a
 class="btn success"
 href="update.php"
>
 Zobrazit aktualizaci
</a>

</div>

<?php endif; ?>


<div class="grid">

<div class="card stat">
<span>Verze systému</span>
<strong>
<?= htmlspecialchars(
    SCRIPTCMS_VERSION,
    ENT_QUOTES,
    'UTF-8'
) ?>
</strong>
</div>

<div class="card stat">
<span>Dnešní počet návštěv (lidé)</span>
<strong>
<?= number_format(
    $totalVisits,
    0,
    '',
    ' '
) ?>
</strong>
</div>

<div class="card stat">
<span>Počet návštěv lidí (od počátku věků)</span>
<strong>
<?= number_format(
    $allTimeTotal,
    0,
    '',
    ' '
) ?>
</strong>
</div>

<div class="card stat">
<span>Dnešní boti a roboti</span>
<strong>
<?= number_format(
    $totalBots,
    0,
    '',
    ' '
) ?>
</strong>
</div>

<div class="card stat">
<span>Práva</span>
<strong>
<?= $isAdmin ? 'Admin' : 'Editor' ?>
</strong>
</div>

</div>


<div class="card">

<h2>Rychlé akce</h2>

<p class="muted">
 Spravuj obsah webu a systém z jednoho místa.
</p>

<div style="display:flex;flex-wrap:wrap;gap:9px">

<a
 class="btn"
 href="index.php?view=content"
>
 Upravit obsah
</a>

<?php if ($isAdmin): ?>

<a
 class="btn secondary"
 href="index.php?view=settings"
>
 Nastavení webu
</a>

<a
 class="btn secondary"
 href="index.php?view=users"
>
 Správa uživatelů
</a>

<?php endif; ?>

</div>

</div>


<?php elseif ($view === 'content'): ?>

<div class="page-head">

<div>

<h1>Obsah webu</h1>

<p>
 Vizuální WYSIWYG editor — bez psaní Markdownu.
</p>

</div>

<a
 class="btn"
 href="index.php?view=content&new=1"
>
 ＋ Nová stránka
</a>

</div>


<div class="layout">

<aside>

<div class="card">

<h3>Stránky</h3>

<ul class="page-list">

<?php foreach ($files as $file): ?>

<?php
$name = basename($file);
?>

<li>

<a
 class="page-link <?= (
     $name === $selectedFile &&
     !isset($_GET['new'])
 ) ? 'active' : '' ?>"
 href="index.php?view=content&edit=<?= rawurlencode($name) ?>"
>
<?= htmlspecialchars(
    $name,
    ENT_QUOTES,
    'UTF-8'
) ?>
</a>

<?php if (
    !in_array(
        $name,
        ['index.md', '404.md'],
        true
    )
): ?>

<form
 class="delete-form"
 method="post"
 onsubmit="return confirm('Opravdu chcete tuto stránku smazat?')"
>

<input
 type="hidden"
 name="csrf_token"
 value="<?= htmlspecialchars(
     $csrfToken,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
>

<input
 type="hidden"
 name="form_action"
 value="delete_page"
>

<input
 type="hidden"
 name="filename"
 value="<?= htmlspecialchars(
     $name,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
>

<button
 class="delete-btn"
 title="Smazat"
 type="submit"
>
 ×
</button>

</form>

<?php endif; ?>

</li>

<?php endforeach; ?>

</ul>

</div>

</aside>


<section>

<div class="card">

<h2>

<?php if (isset($_GET['new'])): ?>

Nová stránka

<?php else: ?>

Upravit:
<?= htmlspecialchars(
    $selectedFile,
    ENT_QUOTES,
    'UTF-8'
) ?>

<?php endif; ?>

</h2>


<form
 method="post"
 id="pageForm"
>

<input
 type="hidden"
 name="csrf_token"
 value="<?= htmlspecialchars(
     $csrfToken,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
>

<input
 type="hidden"
 name="form_action"
 value="save_page"
>


<label for="filename">
 Název souboru
</label>

<input
 id="filename"
 type="text"
 name="filename"
 value="<?= htmlspecialchars(
     $selectedFile,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
 required
 autocomplete="off"
>


<label>
 Obsah stránky
</label>


<div class="editor">

<div
 class="toolbar"
 role="toolbar"
 aria-label="Editor"
>

<button
 class="tool"
 type="button"
 data-cmd="bold"
 title="Tučně"
>
 <b>B</b>
</button>

<button
 class="tool"
 type="button"
 data-cmd="italic"
 title="Kurzíva"
>
 <i>I</i>
</button>

<button
 class="tool"
 type="button"
 data-cmd="underline"
 title="Podtržení"
>
 <u>U</u>
</button>

<span class="separator"></span>

<button
 class="tool"
 type="button"
 data-cmd="insertUnorderedList"
>
 • Seznam
</button>

<button
 class="tool"
 type="button"
 data-cmd="insertOrderedList"
>
 1. Seznam
</button>

<button
 class="tool"
 type="button"
 data-cmd="formatBlock"
 data-value="blockquote"
>
 ❝ Citace
</button>

<span class="separator"></span>

<button
 class="tool"
 type="button"
 id="linkBtn"
>
 🔗 Odkaz
</button>

<button
 class="tool"
 type="button"
 id="imageBtn"
>
 🖼 Obrázek
</button>

<button
 class="tool"
 type="button"
 id="tableBtn"
>
 ▦ Tabulka
</button>

<button
 class="tool"
 type="button"
 data-cmd="removeFormat"
>
 Vyčistit formát
</button>

</div>


<div
 id="editor"
 class="editor-area"
 contenteditable="true"
 spellcheck="true"
><?= $editorHtml ?></div>

</div>


<textarea
 name="content"
 id="contentField"
 hidden
></textarea>


<div class="editor-actions">

<span class="help">
 Obsah se bezpečně uloží jako HTML
 a zachová se i po aktualizaci systému.
</span>

<button
 class="btn success"
 type="submit"
>
 Uložit stránku
</button>

</div>

</form>

</div>

</section>

</div>


<?php elseif ($view === 'users' && $isAdmin): ?>

<div class="page-head">

<div>

<h1>Uživatelé</h1>

<p>
 Správa administrátorů a editorů.
</p>

</div>

</div>


<div class="layout">

<div class="card">

<h3>Nový uživatel</h3>

<form method="post">

<input
 type="hidden"
 name="csrf_token"
 value="<?= htmlspecialchars(
     $csrfToken,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
>

<input
 type="hidden"
 name="form_action"
 value="create_user"
>

<label>
 Uživatelské jméno
</label>

<input
 type="text"
 name="username"
 required
>

<label>
 E-mail
</label>

<input
 type="email"
 name="email"
 autocomplete="email"
 required
>

<label>
 Heslo
</label>

<input
 type="password"
 name="password"
 minlength="8"
 required
>

<label>
 Role
</label>

<select name="role">

<option value="editor">
 Editor
</option>

<option value="admin">
 Administrátor
</option>

</select>

<button
 class="btn success full"
 type="submit"
>
 Vytvořit účet
</button>

</form>

</div>


<div class="card">

<h3>Účty</h3>

<table>

<thead>

<tr>
<th>Uživatel</th>
<th>E-mail</th>
<th>Role</th>
<th>Akce</th>
</tr>

</thead>

<tbody>

<?php foreach ($usersData as $name => $data): ?>

<tr>

<td>
<?= htmlspecialchars(
    (string) $name,
    ENT_QUOTES,
    'UTF-8'
) ?>
</td>

<td>
<?= htmlspecialchars(
    (string) ($data['email'] ?? ''),
    ENT_QUOTES,
    'UTF-8'
) ?>
</td>

<td>
<?= (
    ($data['role'] ?? 'editor') === 'admin'
)
    ? 'Administrátor'
    : 'Editor'
?>
</td>

<td>

<?php if (
    $name !==
    ($_SESSION['user'] ?? '')
): ?>

<form
 method="post"
 style="margin:0"
 onsubmit="return confirm('Opravdu chcete účet smazat?')"
>

<input
 type="hidden"
 name="csrf_token"
 value="<?= htmlspecialchars(
     $csrfToken,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
>

<input
 type="hidden"
 name="form_action"
 value="delete_user"
>

<input
 type="hidden"
 name="username"
 value="<?= htmlspecialchars(
     (string) $name,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
>

<button
 class="btn danger"
 type="submit"
>
 Smazat
</button>

</form>

<?php else: ?>

<span class="muted">
 Přihlášený účet
</span>

<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>


<?php elseif ($view === 'security' && $isAdmin): ?>

<?php
$securityTab = (string)($_GET['security_tab'] ?? 'overview');
$allowedSecurityTabs = ['overview','robots','humans','errors','requests','logins','settings'];
if (!in_array($securityTab, $allowedSecurityTabs, true)) {
    $securityTab = 'overview';
}

$eventType = static fn(array $e): string => (string)($e['type'] ?? '');
$eventStatus = static fn(array $e): int => (int)($e['status'] ?? 0);
$formatEventTime = static function(array $e): string {
    $ts = strtotime((string)($e['time'] ?? ''));
    return $ts ? date('d.m.Y H:i:s', $ts) : '—';
};

$robotEvents = array_values(array_filter($securityEvents, static fn(array $e): bool => $eventType($e) === 'bot_request'));
$humanEvents = array_values(array_filter($securityEvents, static fn(array $e): bool => $eventType($e) === 'human_request'));
$requestEvents = array_values(array_filter($securityEvents, static fn(array $e): bool => in_array($eventType($e), ['bot_request','human_request','blocked','security_alert'], true)));
$errorEvents = array_values(array_filter($securityEvents, static fn(array $e): bool => $eventType($e) === 'error' || in_array($eventStatus($e), [403,404,500], true)));
$loginEvents = array_values(array_filter($securityEvents, static fn(array $e): bool => str_starts_with($eventType($e), 'login_')));

$uniqueIps = static function(array $events): array {
    $out = [];
    foreach ($events as $e) {
        $ip = trim((string)($e['ip'] ?? ''));
        if ($ip !== '') $out[$ip] = $e;
    }
    return $out;
};
$robotIps = $uniqueIps($robotEvents);
$humanIps = $uniqueIps($humanEvents);

$securityTitle = [
    'overview' => 'Přehled',
    'robots' => 'Roboti',
    'humans' => 'Lidé',
    'errors' => 'Err Kódy',
    'requests' => 'Požadavky',
    'logins' => 'Historie přihlášení',
    'settings' => 'Konfigurace'
][$securityTab];
?>

<div class="security-center">
    <div class="security-head">
        <div>
            <div class="security-kicker">SECURITY CENTER</div>
            <h1>🛡️ Zabezpečení</h1>
            <p>Centrální přehled provozu, požadavků, chyb, přihlášení a ochranných pravidel ScriptCMS ™.</p>
        </div>
        <div class="sec-score">
            <span>Bezpečnostní skóre</span>
            <strong><?= (int)$securityScore['score'] ?>/100</strong>
            <small><?= $securityConfig['enabled'] ? 'Ochrana aktivní' : 'Ochrana vypnutá' ?></small>
        </div>
    </div>

    <nav class="security-tabs" aria-label="Sekce zabezpečení">
        <?php
        $tabs = [
            'overview'=>['🛡️','Přehled'], 'robots'=>['🤖','Roboti'], 'humans'=>['👤','Lidé'],
            'errors'=>['⚠️','Err Kódy'], 'requests'=>['↔️','Požadavky'], 'logins'=>['🔐','Přihlášení'], 'settings'=>['⚙️','Konfigurace']
        ];
        foreach ($tabs as $key=>$tab):
        ?>
            <a href="index.php?view=security&security_tab=<?= $key ?>" class="<?= $securityTab === $key ? 'active' : '' ?>">
                <span><?= $tab[0] ?></span><?= htmlspecialchars($tab[1], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($securityTab === 'overview'): ?>
        <div class="security-grid security-grid-compact">
            <div class="security-card"><div class="sec-icon">👤</div><div><span>Lidé</span><strong><?= count($humanIps) ?></strong><small>zachycených IP</small></div></div>
            <div class="security-card"><div class="sec-icon">🤖</div><div><span>Roboti</span><strong><?= count($robotIps) ?></strong><small>zachycených IP</small></div></div>
            <div class="security-card"><div class="sec-icon">↔️</div><div><span>Požadavky</span><strong><?= count($requestEvents) ?></strong><small>z posledního logu</small></div></div>
            <div class="security-card"><div class="sec-icon">⚠️</div><div><span>Chyby</span><strong><?= count($errorEvents) ?></strong><small>403 / 404 / 500 a další</small></div></div>
            <div class="security-card"><div class="sec-icon">🔐</div><div><span>Přihlášení</span><strong><?= count($loginEvents) ?></strong><small>úspěšné i neúspěšné</small></div></div>
            <div class="security-card"><div class="sec-icon">🚫</div><div><span>Blokované IP</span><strong><?= count($securityConfig['blocked_ips']) ?></strong><small>aktivní blokace</small></div></div>
        </div>

        <div class="security-two-col">
            <section class="security-panel">
                <div class="panel-title"><div><h2>Inteligentní zabezpečení</h2><p>Nejdůležitější události seřazené od nejnovějších.</p></div><span class="status-pill <?= $securityConfig['intelligent'] ? 'ok' : 'off' ?>"><?= $securityConfig['intelligent'] ? 'ZAP' : 'VYP' ?></span></div>
                <?php if (!$securityEvents): ?><div class="empty-sec">Zatím nejsou zaznamenány žádné události.</div><?php endif; ?>
                <div class="event-list">
                <?php foreach (array_slice($securityEvents, 0, 12) as $event): ?>
                    <?php $priority=(string)($event['priority']??'info'); $labels=['critical'=>'KRITICKÁ','high'=>'VYSOKÁ','medium'=>'STŘEDNÍ','info'=>'INFO']; ?>
                    <article class="event-card priority-<?= htmlspecialchars($priority,ENT_QUOTES,'UTF-8') ?>">
                        <div class="event-top"><span class="priority-badge"><?= $labels[$priority] ?? strtoupper($priority) ?></span><strong><?= htmlspecialchars((string)($event['type']??'událost'),ENT_QUOTES,'UTF-8') ?></strong><span class="event-time"><?= $formatEventTime($event) ?></span></div>
                        <div class="event-main"><div><b><?= (int)($event['status']??0) ?></b> <?= htmlspecialchars((string)($event['message']??''),ENT_QUOTES,'UTF-8') ?></div><div class="event-meta"><span>🌐 <?= htmlspecialchars((string)($event['ip']??'—'),ENT_QUOTES,'UTF-8') ?></span><span>📍 <?= htmlspecialchars((string)($event['path']??'/'),ENT_QUOTES,'UTF-8') ?></span></div></div>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>
            <aside>
                <section class="security-panel">
                    <div class="panel-title"><div><h2>Stav ochrany</h2><p>Aktuální aktivní vrstvy.</p></div></div>
                    <?php foreach ([['enabled','Bezpečnostní vrstva'],['logging','Logování'],['intelligent','Inteligentní detekce'],['htaccess_enhanced','.htaccess ochrana'],['log_humans','Logování lidí'],['log_bots','Logování robotů']] as $row): ?>
                        <div class="security-status-row"><span><?= htmlspecialchars($row[1],ENT_QUOTES,'UTF-8') ?></span><b class="status-pill <?= !empty($securityConfig[$row[0]]) ? 'ok':'off' ?>"><?= !empty($securityConfig[$row[0]]) ? 'ZAP':'VYP' ?></b></div>
                    <?php endforeach; ?>
                </section>
                <section class="security-panel">
                    <div class="panel-title"><div><h2>Rychlé akce</h2><p>Přejdi přímo na potřebný přehled.</p></div></div>
                    <div class="quick-links"><a href="index.php?view=security&security_tab=robots">🤖 Roboti</a><a href="index.php?view=security&security_tab=humans">👤 Lidé</a><a href="index.php?view=security&security_tab=errors">⚠️ Err Kódy</a><a href="index.php?view=security&security_tab=requests">↔️ Požadavky</a><a href="index.php?view=security&security_tab=logins">🔐 Přihlášení</a></div>
                </section>
            </aside>
        </div>

    <?php elseif ($securityTab === 'robots' || $securityTab === 'humans'): ?>
        <?php $isRobots = $securityTab === 'robots'; $rows = $isRobots ? $robotIps : $humanIps; $events = $isRobots ? $robotEvents : $humanEvents; ?>
        <section class="security-panel">
            <div class="panel-title"><div><h2><?= $isRobots ? '🤖 Roboti' : '👤 Lidé' ?></h2><p><?= $isRobots ? 'Rozpoznané crawly, boty, headless klienti a automatizované požadavky.' : 'Návštěvy rozpoznané jako běžní lidskí návštěvníci.' ?></p></div><span class="count-badge"><?= count($rows) ?> IP</span></div>
            <?php if (!$rows): ?><div class="empty-sec">Zatím nejsou k dispozici žádné záznamy.</div><?php else: ?>
            <div class="security-table-wrap"><table class="security-table"><thead><tr><th>IP adresa</th><th>Poslední záznam</th><th>Požadavků</th><th>Poslední cesta</th><th>Akce</th></tr></thead><tbody>
            <?php foreach ($rows as $ip=>$last): $ipCount=0; foreach($events as $e){if((string)($e['ip']??'')===(string)$ip)$ipCount++;} ?>
                <tr><td><code><?= htmlspecialchars((string)$ip,ENT_QUOTES,'UTF-8') ?></code></td><td><?= $formatEventTime($last) ?></td><td><?= $ipCount ?></td><td><?= htmlspecialchars((string)($last['path']??'/'),ENT_QUOTES,'UTF-8') ?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_block_ip"><input type="hidden" name="ip" value="<?= htmlspecialchars((string)$ip,ENT_QUOTES,'UTF-8') ?>"><button class="mini-btn danger" type="submit">🚫 Blokovat</button></form></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
        <section class="security-panel"><div class="panel-title"><div><h2>Poslední záznamy</h2><p>Nejnovější události tohoto typu.</p></div></div>
            <?php foreach(array_slice($events,0,50) as $event): ?><div class="log-row"><span><?= $formatEventTime($event) ?></span><code><?= htmlspecialchars((string)($event['ip']??'—'),ENT_QUOTES,'UTF-8') ?></code><b><?= (int)($event['status']??0) ?></b><span><?= htmlspecialchars((string)($event['method']??'GET'),ENT_QUOTES,'UTF-8') ?></span><strong><?= htmlspecialchars((string)($event['path']??'/'),ENT_QUOTES,'UTF-8') ?></strong></div><?php endforeach; ?>
        </section>

    <?php elseif ($securityTab === 'errors'): ?>
        <section class="security-panel">
            <div class="panel-title"><div><h2>⚠️ Err Kódy</h2><p>Chybové odpovědi a bezpečnostní odmítnutí včetně času a původu.</p></div></div>
            <div class="error-code-grid"><div><b>403</b><span>Odmítnuto</span><strong><?= (int)$securityErrors['403'] ?></strong></div><div><b>404</b><span>Nenalezeno</span><strong><?= (int)$securityErrors['404'] ?></strong></div><div><b>500</b><span>Server</span><strong><?= (int)$securityErrors['500'] ?></strong></div><div><b>Jiné</b><span>Ostatní</span><strong><?= (int)$securityErrors['other'] ?></strong></div></div>
        </section>
        <section class="security-panel"><div class="security-table-wrap"><table class="security-table"><thead><tr><th>Datum a čas</th><th>Chyba</th><th>Soubor / cesta</th><th>Původ</th><th>Detail</th><th></th></tr></thead><tbody>
        <?php foreach(array_slice($errorEvents,0,150) as $event): ?>
            <tr><td><?= $formatEventTime($event) ?></td><td><span class="code-badge code-<?= (int)($event['status']??0) ?>"><?= (int)($event['status']??0) ?></span></td><td><code><?= htmlspecialchars((string)($event['file']??$event['path']??'—'),ENT_QUOTES,'UTF-8') ?></code></td><td><code><?= htmlspecialchars((string)($event['ip']??'—'),ENT_QUOTES,'UTF-8') ?></code></td><td><?= htmlspecialchars((string)($event['message']??''),ENT_QUOTES,'UTF-8') ?></td><td><details><summary>Detail</summary><div class="action-pop"><a class="mini-btn secondary" href="index.php?view=security&security_tab=errors&security_file=<?= rawurlencode((string)($event['file']??'')) ?>">📄 Soubor</a><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_block_ip"><input type="hidden" name="ip" value="<?= htmlspecialchars((string)($event['ip']??''),ENT_QUOTES,'UTF-8') ?>"><button class="mini-btn danger">🚫 Blokovat</button></form></div></details></td></tr>
        <?php endforeach; ?></tbody></table></div></section>
        <?php if ($securityFileInfo): ?><section class="security-panel file-viewer"><div class="panel-title"><div><h2>📄 <?= htmlspecialchars($securityFileInfo['path'],ENT_QUOTES,'UTF-8') ?></h2><p>Bezpečný náhled souboru a změna oprávnění.</p></div></div><div class="file-meta"><span>Velikost: <?= number_format((int)$securityFileInfo['size'],0,'',' ') ?> B</span><span>Práva: <b><?= htmlspecialchars($securityFileInfo['mode'],ENT_QUOTES,'UTF-8') ?></b></span><span>Upraveno: <?= date('d.m.Y H:i:s',(int)$securityFileInfo['mtime']) ?></span></div><form method="post" class="chmod-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_chmod"><input type="hidden" name="file" value="<?= htmlspecialchars($securityFileInfo['path'],ENT_QUOTES,'UTF-8') ?>"><label>Změnit chmod<select name="mode"><?php foreach(['0600','0640','0644','0700','0750','0755','0770','0775'] as $mode): ?><option value="<?= $mode ?>" <?= $mode===$securityFileInfo['mode']?'selected':'' ?>><?= $mode ?></option><?php endforeach; ?></select></label><button class="btn success">Uložit práva</button></form><?php $sourceFile=$security->safeFile($securityFileInfo['path']); $sourceText=$sourceFile&&is_readable($sourceFile)?(string)@file_get_contents($sourceFile):'Soubor nelze přečíst.'; ?><pre class="source-view"><?= htmlspecialchars(mb_substr($sourceText,0,200000),ENT_QUOTES,'UTF-8') ?></pre></section><?php endif; ?>

    <?php elseif ($securityTab === 'requests'): ?>
        <section class="security-panel"><div class="panel-title"><div><h2>↔️ Požadavky</h2><p>HTTP provoz zachycený bezpečnostní vrstvou. Roboti, lidé, blokace i podezřelé požadavky.</p></div><span class="count-badge"><?= count($requestEvents) ?></span></div><div class="security-table-wrap"><table class="security-table"><thead><tr><th>Čas</th><th>Typ</th><th>Stav</th><th>IP</th><th>Metoda</th><th>Cesta</th><th>User-Agent</th></tr></thead><tbody>
        <?php foreach(array_slice($requestEvents,0,250) as $event): ?><tr><td><?= $formatEventTime($event) ?></td><td><?= htmlspecialchars((string)($event['type']??'—'),ENT_QUOTES,'UTF-8') ?></td><td><span class="code-badge code-<?= (int)($event['status']??0) ?>"><?= (int)($event['status']??0) ?></span></td><td><code><?= htmlspecialchars((string)($event['ip']??'—'),ENT_QUOTES,'UTF-8') ?></code></td><td><?= htmlspecialchars((string)($event['method']??'GET'),ENT_QUOTES,'UTF-8') ?></td><td><code><?= htmlspecialchars((string)($event['path']??'/'),ENT_QUOTES,'UTF-8') ?></code></td><td class="ua-cell"><?= htmlspecialchars((string)($event['user_agent']??'—'),ENT_QUOTES,'UTF-8') ?></td></tr><?php endforeach; ?></tbody></table></div></section>

    <?php elseif ($securityTab === 'logins'): ?>
        <section class="security-panel"><div class="panel-title"><div><h2>🔐 Historie přihlášení všech uživatelů</h2><p>Úspěšné přihlášení, špatná hesla, neznámé účty a neúspěšné 2FA pokusy. Každý záznam obsahuje IP adresu.</p></div><span class="count-badge"><?= count($loginEvents) ?></span></div><div class="security-table-wrap"><table class="security-table"><thead><tr><th>Datum a čas</th><th>Výsledek</th><th>Uživatel</th><th>IP adresa</th><th>Detail</th></tr></thead><tbody>
        <?php foreach(array_slice($loginEvents,0,250) as $event): $lt=(string)($event['type']??''); $ok=str_ends_with($lt,'success'); $label=$lt==='login_success'?'ÚSPĚCH':($lt==='login_attempt'?'POKUS':($lt==='login_2fa_failed'?'2FA CHYBA':'NEÚSPĚCH')); ?>
            <tr><td><?= $formatEventTime($event) ?></td><td><span class="login-badge <?= $ok?'login-ok':'login-bad' ?>"><?= $label ?></span></td><td><strong><?= htmlspecialchars((string)($event['username']??'Neznámý'),ENT_QUOTES,'UTF-8') ?></strong></td><td><code><?= htmlspecialchars((string)($event['ip']??'—'),ENT_QUOTES,'UTF-8') ?></code></td><td><?= htmlspecialchars((string)($event['message']??''),ENT_QUOTES,'UTF-8') ?></td></tr>
        <?php endforeach; ?></tbody></table></div></section>

    <?php elseif ($securityTab === 'settings'): ?>
        <div class="security-two-col"><section class="security-panel"><div class="panel-title"><div><h2>⚙️ Konfigurace ochrany</h2><p>Zapínej a vypínej jednotlivé bezpečnostní vrstvy.</p></div></div><form method="post" class="security-settings-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_save">
        <?php $securityToggles=[
            'enabled'=>['Bezpečnostní vrstva','Odmítání blokovaných IP a cest.'],'logging'=>['Logovací systém','Ukládání požadavků a bezpečnostních událostí.'],'intelligent'=>['Inteligentní zabezpečení','Detekce známých podezřelých vzorů.'],'htaccess_enhanced'=>['Rozšířená .htaccess ochrana','Bezpečnostní hlavičky a ochrana systémových adresářů.'],'log_humans'=>['Logovat lidi','Zapisovat rozpoznané lidské návštěvy.'],'log_bots'=>['Logovat roboty','Zapisovat rozpoznané roboty a crawlery.'],'rate_limit'=>['Rate limit','Připraveno pro omezení příliš rychlých požadavků.']]; ?>
        <?php foreach($securityToggles as $key=>$meta): ?><label class="toggle-row"><span><b><?= htmlspecialchars($meta[0],ENT_QUOTES,'UTF-8') ?></b><small><?= htmlspecialchars($meta[1],ENT_QUOTES,'UTF-8') ?></small></span><input type="checkbox" name="<?= $key ?>" <?= !empty($securityConfig[$key])?'checked':'' ?>><i></i></label><?php endforeach; ?><button class="btn success" type="submit">💾 Uložit konfiguraci</button></form></section>
        <aside><section class="security-panel"><div class="panel-title"><div><h2>🚫 Blokování</h2><p>Ručně zakázané zdroje a cesty.</p></div></div><form method="post" class="stack-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_block_ip"><input name="ip" placeholder="IP adresa, např. 192.0.2.10"><button class="btn danger">Blokovat IP</button></form><form method="post" class="stack-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_block_path"><input name="path" placeholder="Cesta, např. /tajnavec.php"><button class="btn secondary">Zakázat cestu</button></form>
        <?php if($securityConfig['blocked_ips']): ?><h3>Blokované IP</h3><div class="chip-list"><?php foreach($securityConfig['blocked_ips'] as $ip): ?><span class="chip"><?= htmlspecialchars((string)$ip,ENT_QUOTES,'UTF-8') ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_unblock_ip"><input type="hidden" name="ip" value="<?= htmlspecialchars((string)$ip,ENT_QUOTES,'UTF-8') ?>"><button title="Odblokovat">×</button></form></span><?php endforeach; ?></div><?php endif; ?>
        <?php if($securityConfig['blocked_paths']): ?><h3>Blokované cesty</h3><div class="chip-list"><?php foreach($securityConfig['blocked_paths'] as $path): ?><span class="chip"><?= htmlspecialchars((string)$path,ENT_QUOTES,'UTF-8') ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_unblock_path"><input type="hidden" name="path" value="<?= htmlspecialchars((string)$path,ENT_QUOTES,'UTF-8') ?>"><button title="Odblokovat">×</button></form></span><?php endforeach; ?></div><?php endif; ?></section>
        <section class="security-panel"><div class="panel-title"><div><h2>🧹 Log</h2><p>Vymazání bezpečnostního logu je nevratná operace.</p></div></div><form method="post" onsubmit="return confirm('Opravdu vymazat celý bezpečnostní log?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="form_action" value="security_clear_log"><button class="btn danger">Vymazat celý log</button></form></section></aside></div>
    <?php endif; ?>
</div>

<?php elseif ($view === 'settings' && $isAdmin): ?>

<div class="page-head">

<div>

<h1>Nastavení</h1>

<p>
 Základní nastavení veřejného webu.
</p>

</div>

</div>


<div class="layout">

<div
 class="card"
 style="max-width:650px"
>

<form method="post">

<input
 type="hidden"
 name="csrf_token"
 value="<?= htmlspecialchars(
     $csrfToken,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
>

<input
 type="hidden"
 name="form_action"
 value="save_settings"
>

<label>
 Název webu
</label>

<input
 type="text"
 name="site_title"
 value="<?= htmlspecialchars(
     $siteTitle,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
 required
>

<label>
 Motiv
</label>

<select name="theme">

<?php
$themes = [
    'light' => 'Světlý',
    'dark' => 'Tmavý',
    'black' => 'Černý AMOLED',
    'dark-blue' => 'Tmavě modrý',
    'light-blue' => 'Světle modrý',
    'red' => 'Červený',
    'green' => 'Zelený',
    'yellow' => 'Žlutý / okr',
    'brown' => 'Hnědý / dřevo'
];

foreach ($themes as $value => $label):
?>

<option
 value="<?= htmlspecialchars(
     $value,
     ENT_QUOTES,
     'UTF-8'
 ) ?>"
 <?= $configTheme === $value ? 'selected' : '' ?>
>
<?= htmlspecialchars(
    $label,
    ENT_QUOTES,
    'UTF-8'
) ?>
</option>

<?php endforeach; ?>

</select>

<button
 class="btn success"
 type="submit"
>
 Uložit nastavení
</button>

</form>

</div>


<div
 class="card"
 style="max-width:300px"
>

<h3>
 Statistika
</h3>

<p
 style="font-size:1.1rem;margin:10px 0 0 0;"
>
 👁️ Unikátní návštěvy:
 <strong>
 <?= number_format(
     $totalVisits,
     0,
     '',
     ' '
 ) ?>
 </strong>
</p>

</div>

</div>

<?php endif; ?>

</main>

</div>


<script>
/*
 * ============================================================
 * Mobilní menu
 * ============================================================
 */

const menuButton =
    document.getElementById('menuButton');

const adminMenu =
    document.getElementById('adminMenu');

if (menuButton && adminMenu) {

    menuButton.addEventListener(
        'click',
        (event) => {

            event.stopPropagation();

            const open =
                adminMenu.classList.toggle('open');

            menuButton.setAttribute(
                'aria-expanded',
                open ? 'true' : 'false'
            );
        }
    );

    document.addEventListener(
        'click',
        (event) => {

            if (
                window.innerWidth <= 720 &&
                !adminMenu.contains(event.target) &&
                event.target !== menuButton
            ) {

                adminMenu.classList.remove('open');

                menuButton.setAttribute(
                    'aria-expanded',
                    'false'
                );
            }
        }
    );
}


/*
 * ============================================================
 * WYSIWYG editor
 * ============================================================
 */

const editor =
    document.getElementById('editor');

const form =
    document.getElementById('pageForm');

const field =
    document.getElementById('contentField');

if (editor && form && field) {

    let savedRange = null;

    const autoResizeEditor = () => {

        editor.style.height = 'auto';

        editor.style.height =
            Math.max(
                520,
                editor.scrollHeight
            ) + 'px';
    };


    const saveSelection = () => {

        const selection =
            window.getSelection();

        if (
            selection &&
            selection.rangeCount > 0
        ) {

            savedRange =
                selection
                    .getRangeAt(0)
                    .cloneRange();
        }
    };


    const restore = () => {

        if (!savedRange) {
            return;
        }

        const selection =
            window.getSelection();

        selection.removeAllRanges();

        selection.addRange(
            savedRange
        );

        editor.focus();
    };


    const exec = (
        command,
        value = null
    ) => {

        restore();

        document.execCommand(
            command,
            false,
            value
        );

        saveSelection();

        editor.focus();

        autoResizeEditor();
    };


    editor.addEventListener(
        'keyup',
        saveSelection
    );

    editor.addEventListener(
        'mouseup',
        saveSelection
    );

    editor.addEventListener(
        'input',
        autoResizeEditor
    );


    window.addEventListener(
        'load',
        autoResizeEditor
    );

    requestAnimationFrame(
        autoResizeEditor
    );


    document
        .querySelectorAll('[data-cmd]')
        .forEach(
            button => {

                button.addEventListener(
                    'click',
                    () => {

                        exec(
                            button.dataset.cmd,
                            button.dataset.value || null
                        );
                    }
                );
            }
        );


    document
        .querySelectorAll('[data-block]')
        .forEach(
            button => {

                button.addEventListener(
                    'click',
                    () => {

                        exec(
                            'formatBlock',
                            '<' +
                            button.dataset.block +
                            '>'
                        );
                    }
                );
            }
        );


    document
        .getElementById('linkBtn')
        ?.addEventListener(
            'click',
            () => {

                const url =
                    prompt(
                        'URL odkazu:',
                        'https://'
                    );

                if (url) {
                    exec(
                        'createLink',
                        url
                    );
                }
            }
        );


    document
        .getElementById('imageBtn')
        ?.addEventListener(
            'click',
            () => {

                const url =
                    prompt(
                        'URL obrázku:',
                        'https://'
                    );

                if (url) {
                    exec(
                        'insertImage',
                        url
                    );
                }
            }
        );


    document
        .getElementById('tableBtn')
        ?.addEventListener(
            'click',
            () => {

                restore();

                const rowsInput =
                    prompt(
                        'Počet řádků (bez hlavičky):',
                        '2'
                    );

                const colsInput =
                    prompt(
                        'Počet sloupců:',
                        '2'
                    );

                const rows =
                    parseInt(
                        rowsInput,
                        10
                    );

                const cols =
                    parseInt(
                        colsInput,
                        10
                    );

                if (
                    isNaN(rows) ||
                    isNaN(cols) ||
                    rows <= 0 ||
                    cols <= 0
                ) {
                    return;
                }

                let html =
                    '<table><thead><tr>';

                for (
                    let c = 1;
                    c <= cols;
                    c++
                ) {

                    html +=
                        `<th>Hlavička ${c}</th>`;
                }

                html +=
                    '</tr></thead><tbody>';


                for (
                    let r = 1;
                    r <= rows;
                    r++
                ) {

                    html += '<tr>';

                    for (
                        let c = 1;
                        c <= cols;
                        c++
                    ) {

                        html +=
                            `<td>Text ${r}-${c}</td>`;
                    }

                    html += '</tr>';
                }

                html +=
                    '</tbody></table><p></p>';


                document.execCommand(
                    'insertHTML',
                    false,
                    html
                );

                saveSelection();

                autoResizeEditor();
            }
        );


    /*
     * Před odesláním převedeme obsah
     * contenteditable editoru do textarea.
     */
    form.addEventListener(
        'submit',
        () => {

            field.value =
                ContentMarker() +
                editor.innerHTML;
        }
    );


    /*
     * ContentMarker odpovídá hodnotě,
     * kterou Content.php očekává.
     *
     * Marker je vložen přímo při ukládání
     * na serveru, takže zde není nutné
     * duplikovat jeho PHP konstantu.
     */
    function ContentMarker() {
        return '';
    }
}
</script>

</body>
</html>