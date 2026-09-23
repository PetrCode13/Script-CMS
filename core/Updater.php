<?php
declare(strict_types=1);

class Updater
{
    private string $currentVersion;
    private string $updateBaseUrl;
    private string $baseDir;

    public function __construct(string $currentVersion, string $updateBaseUrl = 'https://updates.peceme.org/cms/')
    {
        $this->currentVersion = trim($currentVersion);
        $this->updateBaseUrl = rtrim($updateBaseUrl, '/') . '/';
        $this->baseDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }

    /**
     * Stáhne a dekóduje manifest ze vzdáleného serveru
     */
    public function getManifest(): ?array
    {
        // Unikátní časové razítko zabraňuje kešování cURL/webhostingu
        $json = $this->httpGet($this->updateBaseUrl . 'latest.json?t=' . time(), 15);
        if (!$json) {
            return null;
        }

        // Odstranění BOM a neviditelných znaků
        $json = preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/u', '', trim($json)) ?? trim($json);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Vrátí číslo verze, POUZE pokud je novější než aktuálně nainstalovaná verze
     */
    public function getNewestAvailable(): ?string
    {
        $manifest = $this->getManifest();
        if (!$manifest || empty($manifest['latest'])) {
            return null;
        }

        $remoteVersion = trim((string)$manifest['latest']);

        // Porovnání verzí: vrátí číslo novější verze, jinak null
        if (version_compare($remoteVersion, $this->currentVersion, '>')) {
            return $remoteVersion;
        }

        return null;
    }

    /**
     * Provede stažení a instalaci verze
     */
    public function installVersion(string $version): array
    {
        $version = trim($version);
        if ($version === '' || !preg_match('/^[a-zA-Z0-9\.\-_]+$/', $version)) {
            return ['success' => false, 'message' => 'Neplatný název verze.'];
        }

        // Ověříme, zda je požadovaná verze skutečně novější než aktuální
        if (!version_compare($version, $this->currentVersion, '>')) {
            return ['success' => false, 'message' => 'Tato verze není novější než aktuálně nainstalovaná verze.'];
        }

        $zipUrl = $this->updateBaseUrl . $version . '.zip?t=' . time();
        $zipData = $this->httpGet($zipUrl, 60);

        if (!$zipData) {
            return ['success' => false, 'message' => 'Nepodařilo se stáhnout instalační balíček ' . $version . '.zip.'];
        }

        $tmpDir = sys_get_temp_dir() . '/scriptcms_upd_' . uniqid();
        if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            return ['success' => false, 'message' => 'Nelze vytvořit dočasný adresář pro rozbalení.'];
        }

        $zipFile = $tmpDir . '/update.zip';
        file_put_contents($zipFile, $zipData);

        if (!class_exists('ZipArchive')) {
            $this->rrmdir($tmpDir);
            return ['success' => false, 'message' => 'Na serveru chybí PHP rozšíření ZipArchive.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            $this->rrmdir($tmpDir);
            return ['success' => false, 'message' => 'Stažený ZIP balíček je poškozený nebo neplatný.'];
        }

        $extractDir = $tmpDir . '/extracted';
        @mkdir($extractDir, 0777, true);
        $zip->extractTo($extractDir);
        $zip->close();

        // Pokud ZIP obsahuje obalovací podsložku, posuneme se do ní
        $sourceDir = $extractDir;
        $items = array_diff(scandir($extractDir) ?: [], ['.', '..']);
        if (count($items) === 1) {
            $singleItem = $extractDir . '/' . reset($items);
            if (is_dir($singleItem)) {
                $sourceDir = $singleItem;
            }
        }

        // Kopírování aktualizovaných souborů
        $copySuccess = $this->copyRecursive($sourceDir, $this->baseDir);
        $this->rrmdir($tmpDir);

        if (!$copySuccess) {
            return ['success' => false, 'message' => 'Selhal přepis některých souborů. Zkontrolujte oprávnění (chmod).'];
        }

        // Okamžitá invalidace OPcache pro version.php
        $versionFile = $this->baseDir . '/core/version.php';
        if (function_exists('opcache_invalidate') && file_exists($versionFile)) {
            @opcache_invalidate($versionFile, true);
        }

        return ['success' => true, 'message' => 'Aktualizace na verzi ' . $version . ' byla úspěšně dokončena.'];
    }

    private function copyRecursive(string $src, string $dst): bool
    {
        $dir = opendir($src);
        if (!$dir) {
            return false;
        }

        @mkdir($dst, 0777, true);

        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            // Ochrana před přepsáním konfigurace a uživatelských dat
            if ($file === 'config' || $file === 'uploads' || $file === '.htaccess') {
                if (file_exists($dstPath) && is_dir($srcPath)) {
                    continue;
                }
            }

            if (is_dir($srcPath)) {
                $this->copyRecursive($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        closedir($dir);
        return true;
    }

    private function rrmdir(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            if ($objects) {
                foreach ($objects as $object) {
                    if ($object !== '.' && $object !== '..') {
                        $path = $dir . '/' . $object;
                        if (is_dir($path) && !is_link($path)) {
                            $this->rrmdir($path);
                        } else {
                            @unlink($path);
                        }
                    }
                }
            }
            @rmdir($dir);
        }
    }

    private function httpGet(string $url, int $timeout = 15): ?string
    {
        if (!function_exists('curl_init')) {
            return @file_get_contents($url) ?: null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'ScriptCMS-Updater'
        ]);

        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($res === false || $code !== 200) {
            return null;
        }

        return (string)$res;
    }
}

