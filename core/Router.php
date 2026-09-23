<?php
declare(strict_types=1);

class Router {
    private string $contentDir;

    public function __construct(string $contentDir) {
        $this->contentDir = rtrim($contentDir, '/');
    }

    public function getPagePath(): string {
        // Zachovej původní ?route=... režim, pokud jej někde ScriptCMS používá.
        if (isset($_GET['route']) && is_string($_GET['route'])) {
            $route = $_GET['route'];
        } else {
            // Pro klasické odkazy /Nazev-stranky použij cestu z URL.
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $route = rawurldecode(trim($path, '/'));
        }

        $route = trim($route, '/');
        $route = str_replace(['..', "\0"], '', $route);

        if ($route === '' || $route === 'index.php') {
            $route = 'index';
        }

        // ScriptCMS používá plochou složku pages, takže nechceme cestu přes podadresáře.
        $route = basename($route);

        $filePath = $this->contentDir . '/' . $route . '.md';

        if (is_file($filePath)) {
            return $filePath;
        }

        return $this->contentDir . '/404.md';
    }
}
