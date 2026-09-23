<?php
declare(strict_types=1);

/**
 * ScriptCMS ™ Security Center
 * Bezpečnostní vrstva bez zásahu do uživatelských dat.
 */
final class Security
{
    private string $root;
    private string $configFile;
    private string $logFile;
    private string $securityDir;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->securityDir = $this->root . '/storage/security';
        $this->configFile = $this->securityDir . '/security.json';
        $this->logFile = $this->securityDir . '/events.jsonl';

        if (!is_dir($this->securityDir)) {
            @mkdir($this->securityDir, 0750, true);
        }
        if (!is_file($this->configFile)) {
            $this->saveConfig($this->defaultConfig());
        }
    }

    private function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'logging' => true,
            'intelligent' => true,
            'htaccess_enhanced' => true,
            'log_humans' => true,
            'log_bots' => true,
            'rate_limit' => false,
            'blocked_ips' => [],
            'blocked_paths' => [],
            'updated_at' => date('c')
        ];
    }

    public function config(): array
    {
        $data = @json_decode((string)@file_get_contents($this->configFile), true);
        $data = is_array($data) ? $data : [];
        return array_replace_recursive($this->defaultConfig(), $data);
    }

    public function saveConfig(array $config): bool
    {
        $config['updated_at'] = date('c');
        return @file_put_contents(
            $this->configFile,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    public function clientIp(): string
    {
        // X-Forwarded-For není automaticky důvěryhodné. Použijeme jej jen pokud
        // jej server skutečně poskytuje jako proxy hlavičku; první položka je
        // praktická pro běžný reverse-proxy setup.
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        return $ip;
    }

    public function userAgent(): string
    {
        return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500);
    }

    public function isBot(?string $ua = null): bool
    {
        $ua ??= $this->userAgent();
        return (bool)preg_match(
            '/bot|crawl|crawler|spider|slurp|bingpreview|mediapartners|headless|curl|wget|python-requests|go-http-client|libwww|scrapy/i',
            $ua
        );
    }

    public function isBlocked(string $ip, string $path = ''): bool
    {
        $c = $this->config();
        if (in_array($ip, $c['blocked_ips'], true)) {
            return true;
        }

        $normalized = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
        foreach ($c['blocked_paths'] as $blocked) {
            $blocked = '/' . ltrim((string)$blocked, '/');
            if ($blocked !== '/' && str_starts_with($normalized, $blocked)) {
                return true;
            }
        }
        return false;
    }

    public function request(string $path): void
    {
        $c = $this->config();
        if (!$c['enabled']) {
            return;
        }

        $ip = $this->clientIp();
        $ua = $this->userAgent();
        $bot = $this->isBot($ua);

        if ($this->isBlocked($ip, $path)) {
            $this->logEvent([
                'type' => 'blocked',
                'priority' => 'high',
                'status' => 403,
                'ip' => $ip,
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'path' => $path,
                'user_agent' => $ua,
                'message' => 'Požadavek byl odmítnut pravidlem zabezpečení.'
            ]);
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            exit("403 Forbidden\n");
        }

        if ($c['logging'] && (($bot && $c['log_bots']) || (!$bot && $c['log_humans']))) {
            $this->logEvent([
                'type' => $bot ? 'bot_request' : 'human_request',
                'priority' => 'info',
                'status' => 200,
                'ip' => $ip,
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'path' => $path,
                'user_agent' => $ua,
                'message' => $bot ? 'Detekován robot/crawler.' : 'Návštěva webu.'
            ]);
        }

        if ($c['intelligent']) {
            $this->detectSuspiciousRequest($path, $ua, $ip);
        }
    }

    private function detectSuspiciousRequest(string $path, string $ua, string $ip): void
    {
        $signals = [];
        if (preg_match('/(\.\.\/|%2e%2e|\/\.env|wp-admin|wp-login|phpmyadmin|xmlrpc\.php|\.git\/|composer\.(json|lock)|config\.php)/i', $path)) {
            $signals[] = 'pokus o přístup k citlivému/známému cíli';
        }
        if (preg_match('/(<script|union\s+select|sleep\s*\(|benchmark\s*\(|\bOR\s+1=1\b|\/etc\/passwd|cmd=|base64_decode)/i', urldecode($path))) {
            $signals[] = 'podezřelý vzor požadavku';
        }
        if (strlen($path) > 1200) {
            $signals[] = 'neobvykle dlouhá URL';
        }
        if ($signals) {
            $this->logEvent([
                'type' => 'security_alert',
                'priority' => 'high',
                'status' => 403,
                'ip' => $ip,
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'path' => $path,
                'user_agent' => $ua,
                'message' => implode('; ', $signals)
            ]);
        }
    }

    public function logError(int $status, string $path, string $detail = '', ?string $file = null, string $priority = 'medium'): void
    {
        if (!$this->config()['logging']) {
            return;
        }
        $this->logEvent([
            'type' => 'error',
            'priority' => $priority,
            'status' => $status,
            'ip' => $this->clientIp(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'path' => $path,
            'file' => $file ?: $path,
            'user_agent' => $this->userAgent(),
            'message' => $detail
        ]);
    }

    public function logEvent(array $event): void
    {
        if (!is_dir(dirname($this->logFile))) {
            @mkdir(dirname($this->logFile), 0750, true);
        }
        $event['time'] = date('c');
        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    public function events(int $limit = 250): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }
        $lines = @file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];
        $lines = array_slice($lines, -max(1, $limit));
        $events = [];
        foreach (array_reverse($lines) as $line) {
            $e = json_decode($line, true);
            if (is_array($e)) $events[] = $e;
        }
        return $events;
    }

    public function clearEvents(): void
    {
        if (is_file($this->logFile)) {
            @file_put_contents($this->logFile, '', LOCK_EX);
        }
    }

    public function blockIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $c = $this->config();
        if (!in_array($ip, $c['blocked_ips'], true)) {
            $c['blocked_ips'][] = $ip;
        }
        $ok = $this->saveConfig($c);
        if ($ok) {
            $this->logEvent([
                'type' => 'admin_action',
                'priority' => 'high',
                'status' => 403,
                'ip' => $this->clientIp(),
                'path' => '/admin/index.php?view=security',
                'message' => "Zablokována IP adresa {$ip}."
            ]);
        }
        return $ok;
    }

    public function unblockIp(string $ip): bool
    {
        $c = $this->config();
        $c['blocked_ips'] = array_values(array_filter(
            $c['blocked_ips'],
            static fn($v) => (string)$v !== $ip
        ));
        return $this->saveConfig($c);
    }

    public function blockPath(string $path): bool
    {
        $path = '/' . ltrim(trim($path), '/');
        if ($path === '/') return false;
        $c = $this->config();
        if (!in_array($path, $c['blocked_paths'], true)) {
            $c['blocked_paths'][] = $path;
        }
        return $this->saveConfig($c);
    }

    public function unblockPath(string $path): bool
    {
        $c = $this->config();
        $c['blocked_paths'] = array_values(array_filter(
            $c['blocked_paths'],
            static fn($v) => (string)$v !== $path
        ));
        return $this->saveConfig($c);
    }

    public function errorSummary(): array
    {
        $out = ['403' => 0, '404' => 0, '500' => 0, 'other' => 0];
        foreach ($this->events(1000) as $e) {
            $s = (string)($e['status'] ?? '');
            if (isset($out[$s])) $out[$s]++;
            elseif ($s !== '') $out['other']++;
        }
        return $out;
    }

    public function securityScore(): array
    {
        $c = $this->config();
        $score = 0;
        $checks = [];
        $checks[] = ['label'=>'Bezpečnostní vrstva', 'ok'=>(bool)$c['enabled']];
        $checks[] = ['label'=>'Logování událostí', 'ok'=>(bool)$c['logging']];
        $checks[] = ['label'=>'Inteligentní detekce', 'ok'=>(bool)$c['intelligent']];
        $checks[] = ['label'=>'Rozšířená ochrana .htaccess', 'ok'=>(bool)$c['htaccess_enhanced']];
        foreach ($checks as $check) if ($check['ok']) $score += 25;
        return ['score'=>$score, 'checks'=>$checks];
    }

    public function safeFile(string $relative): ?string
    {
        $relative = str_replace(["\0", '\\'], ['', '/'], $relative);
        $root = realpath($this->root);
        if (!$root) return null;

        // Přijmeme jak relativní cestu (např. admin/index.php),
        // tak absolutní cestu z PHP error logu, pokud leží uvnitř CMS.
        $candidate = str_replace('\\', '/', $relative);
        $rootNormalized = rtrim(str_replace('\\', '/', $root), '/');
        if ($candidate === $rootNormalized || str_starts_with($candidate, $rootNormalized . '/')) {
            $full = realpath($candidate);
        } else {
            $full = realpath($this->root . '/' . ltrim($candidate, '/'));
        }
        if (!$full || !$root) return null;
        if ($full !== $root && !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) return null;
        return is_file($full) ? $full : null;
    }

    public function fileInfo(string $relative): ?array
    {
        $full = $this->safeFile($relative);
        if (!$full) return null;
        $mode = @fileperms($full);
        return [
            'path' => str_replace($this->root . '/', '', $full),
            'size' => @filesize($full) ?: 0,
            'mtime' => @filemtime($full) ?: 0,
            'mode' => $mode !== false ? substr(sprintf('%o', $mode), -4) : '----'
        ];
    }

    public function chmodFile(string $relative, string $mode): bool
    {
        $allowed = ['0600','0640','0644','0700','0750','0755','0770','0775'];
        if (!in_array($mode, $allowed, true)) return false;
        $full = $this->safeFile($relative);
        return $full ? @chmod($full, octdec($mode)) : false;
    }

    public function writeHtaccess(bool $enhanced): bool
    {
        $file = $this->root . '/.htaccess';
        if (!is_file($file)) return false;

        $original = (string)@file_get_contents($file);
        $markerStart = "# === ScriptCMS Security Center START ===";
        $markerEnd = "# === ScriptCMS Security Center END ===";
        $clean = preg_replace(
            '/' . preg_quote($markerStart, '/') . '.*?' . preg_quote($markerEnd, '/') . '\s*/s',
            '',
            $original
        ) ?? $original;

        $block = "\n{$markerStart}\n";
        if ($enhanced) {
            $block .= "<IfModule mod_headers.c>\n";
            $block .= "Header always set X-Content-Type-Options \"nosniff\"\n";
            $block .= "Header always set X-Frame-Options \"SAMEORIGIN\"\n";
            $block .= "Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n";
            $block .= "Header always set Permissions-Policy \"geolocation=(), microphone=(), camera=()\"\n";
            $block .= "</IfModule>\n";
            $block .= "<IfModule mod_rewrite.c>\n";
            $block .= "RewriteEngine On\n";
            $block .= "RewriteRule ^(?:\\.env|\\.git(?:/|$)|composer\\.(?:json|lock)|storage(?:/|$)|config(?:/|$)|core(?:/|$)) - [F,L,NC]\n";
            $block .= "</IfModule>\n";
            $block .= "<FilesMatch \"^(?:security\\.json|events\\.jsonl)$\">\n";
            $block .= "<IfModule mod_authz_core.c>Require all denied</IfModule>\n";
            $block .= "<IfModule !mod_authz_core.c>Order allow,deny\nDeny from all</IfModule>\n";
            $block .= "</FilesMatch>\n";
        }
        $block .= "{$markerEnd}\n";

        return @file_put_contents($file, rtrim($clean) . "\n" . $block, LOCK_EX) !== false;
    }

    public function bootstrap(): void
    {
        $c = $this->config();
        if (!$c['enabled']) return;

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $this->request($uri);

        set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
            $this->logEvent([
                'type' => 'php_error',
                'priority' => $severity >= E_WARNING ? 'high' : 'medium',
                'status' => 500,
                'ip' => $this->clientIp(),
                'path' => $_SERVER['REQUEST_URI'] ?? '/',
                'file' => $file,
                'message' => $message . ' (řádek ' . $line . ')'
            ]);
            return false;
        });

        register_shutdown_function(function(): void {
            $last = error_get_last();
            if (!$last) return;
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (in_array((int)$last['type'], $fatalTypes, true)) {
                $this->logEvent([
                    'type' => 'fatal_error',
                    'priority' => 'critical',
                    'status' => 500,
                    'ip' => $this->clientIp(),
                    'path' => $_SERVER['REQUEST_URI'] ?? '/',
                    'file' => $last['file'] ?? '',
                    'message' => ($last['message'] ?? '') . ' (řádek ' . ($last['line'] ?? 0) . ')'
                ]);
            }
        });
    }
}
