<?php
// Cesty k souborům
$sitemap_file = $_SERVER['DOCUMENT_ROOT'] . '/sitemap.xml';
$pages_dir = $_SERVER['DOCUMENT_ROOT'] . '/content/pages';

$error = '';
$success = '';

if (isset($_POST['generate_sitemap'])) {
    if (!is_dir($pages_dir)) {
        $error = 'Chyba: Složka /content/pages/ neexistuje na serveru.';
    } else {
        try {
            // Definice správného XML jmenného prostoru
            $ns = 'http://www.sitemaps.org/schemas/sitemap/0.9';
            
            // Inicializace XML sitemap struktury se správným namespace
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="' . $ns . '"></urlset>');

            // 1. Přidání hlavní úvodní stránky webu zdravi.peceme.org/content/pages
            // U addChild je nutné uvést $ns jako třetí parametr, aby se správně aplikoval jmenný prostor
            $homeUrl = $xml->addChild('url', null, $ns);
            $homeUrl->addChild('loc', 'https://peceme.org', $ns);
            $homeUrl->addChild('lastmod', date('c'), $ns);
            $homeUrl->addChild('changefreq', 'daily', $ns);
            $homeUrl->addChild('priority', '1.0', $ns);

            // 2. Vyhledání všech .md souborů ve složce /content/pages/
            $md_files = glob($pages_dir . '/*.md');

            if (!empty($md_files)) {
                foreach ($md_files as $file) {
                    $filename = basename($file, '.md'); // Získá název bez přípony (např. "o-nas")
                    
                    // Přeskočení úvodní stránky, pokud se jmenuje index.md nebo home.md (aby nebyla v sitemapě dvakrát)
                    if (in_array($filename, ['index', 'home'])) {
                        continue;
                    }

                    // Získání reálného času poslední úpravy souboru na disku
                    $lastmod = date('c', filemtime($file));

                    // Sestavení URL adresy podstránky (přidáno chybějící lomítko za doménu)
                    $url_path = 'https://peceme.org' . $filename;

                    // Přidání do XML se správným jmenným prostorem
                    $urlNode = $xml->addChild('url', null, $ns);
                    $urlNode->addChild('loc', htmlspecialchars($url_path), $ns);
                    $urlNode->addChild('lastmod', $lastmod, $ns);
                    $urlNode->addChild('changefreq', 'weekly', $ns);
                    $urlNode->addChild('priority', '0.7', $ns);
                }
            }

            // Uložení hotového XML do kořenového adresáře
            if ($xml->asXML($sitemap_file)) {
                $success = 'Sitemapa byla úspěšně vygenerována z vašich .md souborů!';
            } else {
                $error = 'Chyba: Nepodařilo se zapsat soubor sitemap.xml. Zkontrolujte oprávnění v kořenové složce.';
            }

        } catch (Exception $e) {
            $error = 'Došlo k chybě: ' . $e->getMessage();
        }
    }
}

// Kontrola aktuálního stavu souboru pro zobrazení v administraci
$sitemap_exists = file_exists($sitemap_file);
$sitemap_date = $sitemap_exists ? date("d.m.Y H:i:s", filemtime($sitemap_file)) : null;
?>

