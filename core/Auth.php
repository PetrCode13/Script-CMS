<?php
declare(strict_types=1);

require_once __DIR__ . '/Mailer.php';

class Auth
{
    private string $usersFile;
    private string $tokensFile;
    private string $securityLogFile;

    public function __construct(string $usersFile)
    {
        $this->usersFile = $usersFile;
        $this->tokensFile = dirname($usersFile) . '/password_reset_tokens.json';
        $this->securityLogFile = dirname($usersFile) . '/storage/security/events.jsonl';

        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }
    }

    private function loadUsers(): array
    {
        if (!is_file($this->usersFile)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($this->usersFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveUsers(array $users): bool
    {
        return file_put_contents(
            $this->usersFile,
            json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    /**
     * Zapisuje bezpečnostní události autentizace bez ukládání hesel nebo 2FA kódů.
     */
    private function logLoginEvent(string $type, string $username, string $message, string $priority = 'info'): void
    {
        $dir = dirname($this->securityLogFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }

        $event = [
            'type' => $type,
            'priority' => $priority,
            'status' => $type === 'login_success' ? 200 : 401,
            'ip' => $ip,
            'username' => substr($username, 0, 160),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
            'path' => $_SERVER['REQUEST_URI'] ?? '/admin/login.php',
            'user_agent' => substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500),
            'message' => $message,
            'time' => date('c')
        ];

        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents($this->securityLogFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    public function getUser(string $username): ?array
    {
        $users = $this->loadUsers();
        return isset($users[$username]) && is_array($users[$username]) ? $users[$username] : null;
    }

    public function isLoggedIn(): bool
    {
        if (empty($_SESSION['user']) || !is_string($_SESSION['user'])) {
            return false;
        }

        return $this->getUser($_SESSION['user']) !== null;
    }

    public function login(string $username, string $password): bool
    {
        $username = trim($username);
        $users = $this->loadUsers();

        if (!isset($users[$username]) || !is_array($users[$username])) {
            $this->logLoginEvent('login_failed', $username, 'Pokus o přihlášení k neexistujícímu účtu.', 'high');
            return false;
        }

        $storedPassword = (string)($users[$username]['password'] ?? '');
        if ($storedPassword === '' || !password_verify($password, $storedPassword)) {
            $this->logLoginEvent('login_failed', $username, 'Neplatné heslo.', 'high');
            return false;
        }

        $email = trim((string)($users[$username]['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logLoginEvent('login_failed', $username, 'Účet nemá platnou e-mailovou adresu pro 2FA.', 'high');
            return false;
        }

        $this->logLoginEvent('login_2fa_required', $username, 'Heslo bylo přijato; čeká se na ověření 2FA.', 'info');

        session_regenerate_id(true);

        unset($_SESSION['user'], $_SESSION['role']);
        $_SESSION['pending_2fa_user'] = $username;
        $_SESSION['pending_2fa_created'] = time();
        $_SESSION['pending_2fa_attempts'] = 0;

        if (!$this->sendTwoFactorCode($username, true)) {
            $this->clearTwoFactor();
            return false;
        }

        return true;
    }

    public function sendTwoFactorCode(string $username, bool $force = false): bool
    {
        $user = $this->getUser($username);
        if (!$user) {
            return false;
        }

        $email = trim((string)($user['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (!$force && !empty($_SESSION['pending_2fa_sent']) && time() - (int)$_SESSION['pending_2fa_sent'] < 60) {
            return false;
        }

        $code = (string)random_int(100000, 999999);

        $_SESSION['pending_2fa_code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['pending_2fa_expires'] = time() + 600;
        $_SESSION['pending_2fa_sent'] = time();
        $_SESSION['pending_2fa_attempts'] = 0;

        $site = $this->siteTitle();
        $subject = 'Přihlašovací kód – ' . $site;
        $text = "Dobrý den,\n\nVáš jednorázový přihlašovací kód pro {$site} je:\n\n{$code}\n\nKód je platný 10 minut.\n\nPokud jste se o přihlášení nepokoušeli vy, tento e-mail ignorujte.";
        $html = '<p>Dobrý den,</p><p>Váš jednorázový přihlašovací kód pro <strong>' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</strong> je:</p><p style="font-size:30px;font-weight:800;letter-spacing:7px">' . $code . '</p><p>Kód je platný 10 minut.</p><p>Pokud jste se o přihlášení nepokoušeli vy, tento e-mail ignorujte.</p>';

        return Mailer::send($email, $subject, $text, $html);
    }

    public function isPendingTwoFactor(): bool
    {
        return !empty($_SESSION['pending_2fa_user']) &&
            !empty($_SESSION['pending_2fa_code_hash']) &&
            (int)($_SESSION['pending_2fa_expires'] ?? 0) >= time();
    }

    public function verifyTwoFactor(string $code): bool
    {
        if (!$this->isPendingTwoFactor()) {
            return false;
        }

        if ((int)($_SESSION['pending_2fa_attempts'] ?? 0) >= 5) {
            $this->logLoginEvent('login_2fa_failed', $this->pendingTwoFactorUsername(), 'Překročen maximální počet pokusů 2FA.', 'critical');
            return false;
        }

        $_SESSION['pending_2fa_attempts']++;

        if (!preg_match('/^\d{6}$/', $code)) {
            $this->logLoginEvent('login_2fa_failed', $this->pendingTwoFactorUsername(), 'Neplatný formát 2FA kódu.', 'high');
            return false;
        }

        if (!password_verify($code, (string)$_SESSION['pending_2fa_code_hash'])) {
            $this->logLoginEvent('login_2fa_failed', $this->pendingTwoFactorUsername(), 'Neplatný 2FA kód.', 'high');
            return false;
        }

        $username = (string)$_SESSION['pending_2fa_user'];
        $user = $this->getUser($username);
        if (!$user) {
            $this->clearTwoFactor();
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = $username;
        $_SESSION['role'] = $user['role'] ?? 'editor';
        $this->logLoginEvent('login_success', $username, 'Úspěšné přihlášení po ověření 2FA.', 'info');
        $this->clearTwoFactor();
        $this->generateCsrfToken();

        return true;
    }

    public function clearTwoFactor(): void
    {
        unset(
            $_SESSION['pending_2fa_user'],
            $_SESSION['pending_2fa_created'],
            $_SESSION['pending_2fa_attempts'],
            $_SESSION['pending_2fa_code_hash'],
            $_SESSION['pending_2fa_expires'],
            $_SESSION['pending_2fa_sent']
        );
    }

    public function pendingTwoFactorUsername(): string
    {
        return (string)($_SESSION['pending_2fa_user'] ?? '');
    }

    public function requestPasswordReset(string $email): void
    {
        $email = strtolower(trim($email));
        $users = $this->loadUsers();
        $username = null;

        foreach ($users as $name => $user) {
            if (is_array($user) && strtolower(trim((string)($user['email'] ?? ''))) === $email) {
                $username = (string)$name;
                break;
            }
        }

        if ($username === null) {
            return;
        }

        $tokens = $this->loadResetTokens();
        foreach ($tokens as $hash => $token) {
            if (!is_array($token) || (int)($token['expires'] ?? 0) < time()) {
                unset($tokens[$hash]);
            }
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokens[hash('sha256', $rawToken)] = [
            'username' => $username,
            'expires' => time() + 3600
        ];
        $this->saveResetTokens($tokens);

        $base = $this->baseUrl();
        $link = $base . '/admin/reset-password.php?token=' . urlencode($rawToken);
        $site = $this->siteTitle();
        $subject = 'Obnovení hesla – ' . $site;
        $text = "Dobrý den,\n\nPožádali jste o obnovení hesla pro {$site}.\n\nOdkaz pro změnu hesla:\n{$link}\n\nOdkaz platí 60 minut a lze použít pouze jednou.\n\nPokud jste o změnu hesla nežádali, tento e-mail ignorujte.";
        $html = '<p>Dobrý den,</p><p>Požádali jste o obnovení hesla pro <strong>' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</strong>.</p><p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Obnovit heslo</a></p><p>Odkaz platí 60 minut a lze použít pouze jednou.</p><p>Pokud jste o změnu hesla nežádali, tento e-mail ignorujte.</p>';

        Mailer::send($email, $subject, $text, $html);
    }

    public function resetPassword(string $rawToken, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return [false, 'Heslo musí mít alespoň 8 znaků.'];
        }

        $hash = hash('sha256', $rawToken);
        $tokens = $this->loadResetTokens();
        $entry = $tokens[$hash] ?? null;

        if (!is_array($entry) || (int)($entry['expires'] ?? 0) < time()) {
            return [false, 'Odkaz pro obnovu hesla je neplatný nebo vypršel.'];
        }

        $username = (string)($entry['username'] ?? '');
        $users = $this->loadUsers();
        if ($username === '' || !isset($users[$username])) {
            return [false, 'Účet nebyl nalezen.'];
        }

        $users[$username]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!$this->saveUsers($users)) {
            return [false, 'Heslo se nepodařilo uložit.'];
        }

        unset($tokens[$hash]);
        $this->saveResetTokens($tokens);

        return [true, 'Heslo bylo změněno. Nyní se můžete přihlásit.'];
    }

    private function loadResetTokens(): array
    {
        if (!is_file($this->tokensFile)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($this->tokensFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveResetTokens(array $tokens): bool
    {
        return file_put_contents($this->tokensFile, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }

    private function siteTitle(): string
    {
        $configFile = dirname($this->usersFile) . '/config.json';
        $data = is_file($configFile) ? json_decode((string)file_get_contents($configFile), true) : [];
        return is_array($data) ? (string)($data['site_title'] ?? 'ScriptCMS ™') : 'ScriptCMS ™';
    }

    private function baseUrl(): string
    {
        $configFile = dirname($this->usersFile) . '/config.json';
        $data = is_file($configFile) ? json_decode((string)file_get_contents($configFile), true) : [];
        if (is_array($data) && !empty($data['base_url'])) {
            return rtrim((string)$data['base_url'], '/');
        }

        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($https ? 'https://' : 'http://') . $host;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function verifyCsrfToken(?string $token): bool
    {
        return !empty($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
