<?php
// Nur für den PHP-Entwicklungsserver:  PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080 router.php
// (mehrere Worker, weil das SSH-Terminal Long-Polling benutzt)
// (Produktiv: Caddy/nginx/Apache, siehe server/ und README.md)
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#^/assets/[A-Za-z0-9._-]+$#', $path) && is_file(__DIR__ . $path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
