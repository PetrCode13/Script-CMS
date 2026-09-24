<?php
$pagesDir = __DIR__ . '/../../content/pages';
$pageFiles = glob($pagesDir . '/*.md') ?: [];

// Výchozí motiv, pokud není nastaven v config.json
$theme = $theme ?? 'light';

// Starší migrace z Joomla mohla uložit české znaky v názvech souborů
// jako #U00e1 apod. Tuto podobu umíme zobrazit správně i bez ruční úpravy.
$decodeMigratedName = static function (string $value): string {
    return preg_replace_callback('/#U([0-9A-Fa-f]{4})/', static function (array $m): string {
        $codepoint = hexdec($m[1]);
        return $codepoint <= 0x10FFFF ? mb_chr($codepoint, 'UTF-8') : $m[0];
    }, $value) ?? $value;
};

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$currentPath = '/' . trim(rawurldecode($currentPath), '/');
if ($currentPath !== '/') {
    $currentPath .= '';
}

$navigationItems = [];
foreach ($pageFiles as $file) {
    $slug = basename($file, '.md');
    if ($slug === '404') {
        continue;
    }

    $label = ($slug === 'index') ? 'Domů' : $decodeMigratedName($slug);
    $label = ucfirst($label);
    $url = ($slug === 'index') ? '/' : '/' . rawurlencode($slug);
    $compareUrl = ($slug === 'index') ? '/' : '/' . $slug;
    $isActive = ($compareUrl === '/')
        ? ($currentPath === '/')
        : rtrim($currentPath, '/') === rtrim($compareUrl, '/');

    $navigationItems[] = [
        'label' => $label,
        'url' => $url,
        'active' => $isActive,
    ];
}

// Stabilní pořadí: Úvod první, ostatní stránky podle českého názvu.
usort($navigationItems, static function (array $a, array $b): int {
    if ($a['url'] === '/') return -1;
    if ($b['url'] === '/') return 1;
    return strcasecmp($a['label'], $b['label']);
});
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title><?php echo htmlspecialchars($siteTitle ?? 'Můj web', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/jpeg" href="/favicon.jpg?v=1.0.1">

    <style>
        :root {
            <?php if ($theme === 'dark'): ?>
                --bg-color:#121824;--card-bg:#1e293b;--text-color:#f1f5f9;--border-color:#334155;--link-color:#38bdf8;--card-shadow:0 10px 25px -5px rgba(0,0,0,.5);
            <?php elseif ($theme === 'black'): ?>
                --bg-color:#050505;--card-bg:#121212;--text-color:#f0f0f0;--border-color:#2a2a2a;--link-color:#4da6ff;--card-shadow:0 10px 25px -5px rgba(0,0,0,.8);
            <?php elseif ($theme === 'dark-blue'): ?>
                --bg-color:#0d1b2a;--card-bg:#1b263b;--text-color:#f0f4f8;--border-color:#415a77;--link-color:#48cae4;--card-shadow:0 10px 25px -5px rgba(0,0,0,.5);
            <?php elseif ($theme === 'light-blue'): ?>
                --bg-color:#dbebe5;--card-bg:#fff;--text-color:#0f2a4a;--border-color:#90e0ef;--link-color:#0077b6;--card-shadow:0 10px 25px -5px rgba(0,119,182,.12);
            <?php elseif ($theme === 'red'): ?>
                --bg-color:#fbe8e6;--card-bg:#fff;--text-color:#3b1111;--border-color:#f5a399;--link-color:#d9381e;--card-shadow:0 10px 25px -5px rgba(217,56,30,.12);
            <?php elseif ($theme === 'green'): ?>
                --bg-color:#e2f0d9;--card-bg:#fff;--text-color:#113013;--border-color:#a3d99b;--link-color:#2e8b57;--card-shadow:0 10px 25px -5px rgba(46,139,87,.12);
            <?php elseif ($theme === 'yellow'): ?>
                --bg-color:#fdf3d8;--card-bg:#fff;--text-color:#3a2e05;--border-color:#f7d070;--link-color:#d97706;--card-shadow:0 10px 25px -5px rgba(217,119,6,.12);
            <?php elseif ($theme === 'brown'): ?>
                --bg-color:#f3eae1;--card-bg:#fff;--text-color:#362215;--border-color:#d1b8a5;--link-color:#8b4513;--card-shadow:0 10px 25px -5px rgba(139,69,19,.12);
            <?php else: ?>
                --bg-color:#f1f5f9;--card-bg:#fff;--text-color:#0f172a;--border-color:#cbd5e1;--link-color:#0284c7;--card-shadow:0 10px 25px -5px rgba(0,0,0,.08);
            <?php endif; ?>
        }

        * { box-sizing:border-box; }
        html { min-height:100%; }
        body {
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            line-height:1.6;
            margin:0;
            padding:28px 28px 28px 300px;
            background:var(--bg-color);
            color:var(--text-color);
        }
        .site-card {
            max-width:1100px;
            margin:0 auto;
            background:var(--card-bg);
            border:1px solid var(--border-color);
            border-radius:12px;
            box-shadow:var(--card-shadow);
            padding:28px;
            min-height:calc(100vh - 56px);
        }
        header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:15px;
            border-bottom:2px solid var(--border-color);
            padding-bottom:15px;
            margin-bottom:25px;
            position:relative;
        }
        header h2 { margin:0; font-size:1.5rem; }
        a { color:var(--link-color); transition:color .15s,background .15s; }
        a:hover { opacity:.85; }

        /* PC: klasické permanentně zobrazené menu vlevo. */
        .desktop-sidebar {
            position:fixed;
            z-index:1000;
            left:18px;
            top:18px;
            bottom:18px;
            width:250px;
            background:var(--card-bg);
            border:1px solid var(--border-color);
            border-radius:14px;
            box-shadow:var(--card-shadow);
            padding:22px 14px;
            overflow-y:auto;
            overflow-x:hidden;
        }
        .sidebar-title {
            display:block;
            padding:4px 12px 18px;
            margin-bottom:10px;
            border-bottom:2px solid var(--border-color);
            color:var(--text-color);
            text-decoration:none;
            font-size:1.25rem;
            font-weight:700;
            word-break:break-word;
        }
        .nav-links {
            display:flex;
            flex-direction:column;
            gap:3px;
            list-style:none;
            margin:0;
            padding:0;
        }
        .nav-links li { margin:0; padding:0; }
        .nav-links a {
            display:block;
            padding:9px 12px;
            border-radius:8px;
            text-decoration:none;
            color:var(--text-color);
            font-weight:600;
            line-height:1.35;
            word-break:break-word;
        }
        .nav-links a:hover { background:var(--bg-color); opacity:1; }
        .nav-links a.active { background:var(--link-color); color:var(--card-bg); }

        .hamburger {
            display:none;
            flex:0 0 auto;
            background:var(--card-bg);
            border:1px solid var(--border-color);
            border-radius:8px;
            padding:5px 10px;
            font-size:24px;
            line-height:1;
            cursor:pointer;
            color:var(--text-color);
        }
        .mobile-nav { display:none; }

        pre,code { background:var(--bg-color); border:1px solid var(--border-color); border-radius:6px; padding:2px 6px; }
        pre code { padding:0; border:none; }
        pre { background:#0d1117; color:#e6edf3; padding:16px; border-radius:8px; overflow-x:auto; font-family:ui-monospace,SFMono-Regular,monospace; font-size:.9em; }
        img, img.img-fluid { max-width:100%; height:auto; border-radius:8px; }
        img.img-fluid { margin:15px 0; }
        .table-responsive { overflow-x:auto; margin:20px 0; }
        table { width:100%; border-collapse:collapse; margin-bottom:1rem; }
        th,td { padding:10px 14px; border:1px solid var(--border-color); text-align:left; }
        th { background:var(--bg-color); font-weight:600; }

        /* Mobil: sidebar zmizí a hamburger se otevře POD hlavičkou, ne přes obsah. */
        @media (max-width:767px) {
            body { padding:12px; }
            .desktop-sidebar { display:none; }
            .site-card { max-width:none; min-height:calc(100vh - 24px); padding:18px; }
            header { flex-wrap:wrap; margin-bottom:20px; }
            .hamburger { display:block; }
            .mobile-nav {
                display:none;
                flex:0 0 100%;
                width:100%;
                position:static;
                order:3;
                background:var(--card-bg);
                border:1px solid var(--border-color);
                border-radius:10px;
                padding:8px;
                box-shadow:none;
            }
            .mobile-nav.active { display:block; }
            .mobile-nav .nav-links { gap:2px; }
            .mobile-nav .nav-links a { padding:10px 12px; }
        }
    </style>
    <style id="scriptcms-responsive-images">
        img { max-width:100%; height:auto; }
        .wysiwyg-editor img,.content img,.article-content img,.post-content img,.page-content img { max-width:100%; height:auto; }
    </style>
</head>
<body>
    <aside class="desktop-sidebar" aria-label="Hlavní navigace">
        <a class="sidebar-title" href="/"><?php echo htmlspecialchars($siteTitle ?? 'Můj web', ENT_QUOTES, 'UTF-8'); ?></a>
        <ul class="nav-links">
            <?php foreach ($navigationItems as $item): ?>
                <li>
                    <a class="<?php echo $item['active'] ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>

    <div class="site-card">
        <header>
            <h2><a href="/" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($siteTitle ?? 'Můj web', ENT_QUOTES, 'UTF-8'); ?></a></h2>
            <button class="hamburger" id="mobileMenuButton" type="button" aria-label="Otevřít menu" aria-expanded="false" aria-controls="mobileNav">☰</button>
            <nav class="mobile-nav" id="mobileNav" aria-label="Mobilní navigace">
                <ul class="nav-links">
                    <?php foreach ($navigationItems as $item): ?>
                        <li>
                            <a class="<?php echo $item['active'] ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </header>

        <!-- Obrázek je teď uvnitř karty, takže se hezky zarovná s obsahem a neutíká doleva -->
        <img src="../../../content/images/header.png" alt="Krajina, duha, nebe" style="width: 100%; height: auto; border-radius: 8px; margin-bottom: 20px;">

        <main>
