<?php
/**
 * Caddyfile-Generator (reines PHP).
 *
 * Aus angehakten Optionen wird eine saubere, wie mit „caddy fmt“ formatierte
 * Caddy-v2-Konfiguration erzeugt: globale Optionen, beliebig viele Sites mit
 * Reverse-Proxy, PHP-FPM, Dateiserver, Weiterleitung oder fester Antwort,
 * TLS-Varianten, Kompression, Sicherheits-Headern, CORS, Basic-Auth,
 * IP-Freigaben, Größenlimit, Zugriffslog, www-Umleitung, Fehlerseiten.
 *
 * Alle Eingaben werden geprüft; Werte mit Leerzeichen werden in
 * Anführungszeichen gesetzt, Zeilenumbrüche und Klammern sind in Werten
 * nicht erlaubt (keine eingeschleusten Direktiven).
 */

declare(strict_types=1);

const CADDY_MODES = ['proxy' => 'Reverse-Proxy', 'php' => 'PHP-Anwendung (PHP-FPM)', 'static' => 'Statische Dateien', 'redirect' => 'Weiterleitung', 'respond' => 'Feste Antwort (z. B. Wartung)'];
const CADDY_LB = ['' => 'Standard (random)', 'round_robin' => 'round_robin', 'least_conn' => 'least_conn', 'ip_hash' => 'ip_hash', 'first' => 'first (Failover)', 'random' => 'random', 'uri_hash' => 'uri_hash', 'cookie' => 'cookie'];
const CADDY_TLS = ['auto' => 'Automatisch (Let’s Encrypt / ZeroSSL)', 'internal' => 'Interne CA (Intranet, selbst signiert)', 'files' => 'Eigenes Zertifikat (Dateien)', 'dns' => 'ACME per DNS-Challenge (Plugin nötig)', 'off' => 'Kein TLS (nur HTTP)'];
const CADDY_PRESETS_FILE = APP_ROOT . '/config/caddy-presets.json';

function caddy_default_site(): array
{
    return [
        'addresses' => 'app.example.com', 'mode' => 'proxy',
        'upstreams' => '127.0.0.1:8080', 'lb' => '', 'health_uri' => '', 'health_interval' => '10s', 'proxy_path' => '', 'host_header' => '0', 'real_ip' => '0',
        'tls_skip_verify' => '0', 'tls_server_name' => '', 'flush' => '0', 'dial_timeout' => '',
        'root' => '/var/www/html', 'php_socket' => 'unix//run/php/php8.3-fpm.sock', 'browse' => '0', 'precompressed' => '0', 'spa' => '0', 'hide' => '.git .env',
        'redirect_to' => 'https://example.com{uri}', 'redirect_code' => 'permanent',
        'respond_code' => '503', 'respond_body' => 'Wartungsarbeiten – bitte später erneut versuchen.',
        'tls' => 'auto', 'tls_email' => '', 'tls_cert' => '', 'tls_key' => '', 'dns_provider' => 'cloudflare', 'dns_token_env' => 'CF_API_TOKEN', 'tls_min' => '', 'mtls_ca' => '',
        'encode_zstd' => '1', 'encode_gzip' => '1',
        'hsts' => '1', 'hsts_sub' => '1', 'hsts_preload' => '0', 'nosniff' => '1', 'frame' => 'SAMEORIGIN', 'referrer' => 'strict-origin-when-cross-origin',
        'permissions' => '', 'csp' => '', 'remove_server' => '1', 'noindex' => '0', 'headers_custom' => '',
        'cors_origin' => '', 'auth_users' => '', 'auth_path' => '', 'allow_ips' => '', 'client_ip' => '0', 'max_body' => '',
        'log' => '0', 'log_file' => '', 'log_format' => 'json', 'log_roll_size' => '50MiB', 'log_roll_keep' => '10',
        'www' => '', 'errors' => '0', 'cache_static' => '0', 'extra' => '',
    ];
}

function caddy_defaults(): array
{
    return [
        'g' => [
            'enabled' => '1', 'email' => '', 'admin' => 'default', 'admin_addr' => 'localhost:2019', 'debug' => '0', 'http_port' => '', 'https_port' => '',
            'auto_https' => 'on', 'acme_ca' => 'default', 'acme_ca_url' => '', 'local_certs' => '0', 'skip_install_trust' => '0', 'grace_period' => '', 'storage' => '',
            'h1' => '1', 'h2' => '1', 'h3' => '1', 'trusted_proxies' => '', 'timeout_read_body' => '', 'timeout_read_header' => '', 'timeout_write' => '', 'timeout_idle' => '',
            'log' => '0', 'log_output' => 'stderr', 'log_file' => '/var/log/caddy/caddy.log', 'log_format' => 'console', 'log_level' => 'INFO',
            'ocsp_off' => '0', 'default_sni' => '', 'on_demand_ask' => '',
        ],
        's' => [caddy_default_site()],
        'name' => 'Caddyfile',
    ];
}

/** Ein Wert für das Caddyfile: Zeilenumbrüche/Klammern verboten, Leerzeichen → Anführungszeichen. */
function caddy_q(string $v): string
{
    $v = str_replace(["\r", "\n", "\t"], ' ', $v);
    if ($v === '' || preg_match('/[\s"`]/', $v)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
    return $v;
}

function caddy_bool(array $a, string $k): bool
{
    return (string)($a[$k] ?? '0') === '1';
}

/** Eingaben übernehmen und prüfen: [config, fehler, hinweise] */
function caddy_normalize(array $in): array
{
    $def = caddy_defaults();
    $err = [];
    $warn = [];
    $str = static fn($v, int $max = 300): string => mb_substr(trim(str_replace(["\0", "\r", "\n"], ' ', is_scalar($v) ? (string)$v : '')), 0, $max);
    $multi = static fn($v, int $max = 4000): string => mb_substr(str_replace(["\0", "\r"], '', is_scalar($v) ? (string)$v : ''), 0, $max);
    $g = [];
    foreach ($def['g'] as $k => $d) {
        $g[$k] = isset($in['g'][$k]) ? $str($in['g'][$k]) : (in_array($d, ['0', '1'], true) && isset($in['g']) ? '0' : $d);
    }
    $sites = [];
    foreach (array_slice(array_values(is_array($in['s'] ?? null) ? $in['s'] : $def['s']), 0, 30) as $i => $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $s = [];
        foreach (caddy_default_site() as $k => $d) {
            $long = in_array($k, ['upstreams', 'headers_custom', 'auth_users', 'allow_ips', 'extra', 'addresses', 'trusted_proxies'], true);
            $s[$k] = isset($raw[$k]) ? ($long ? $multi($raw[$k]) : $str($raw[$k])) : (in_array($d, ['0', '1'], true) ? '0' : $d);
        }
        $sites[] = $s;
    }
    if ($sites === []) {
        $sites[] = caddy_default_site();
    }
    $name = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($in['name'] ?? 'Caddyfile')) ?: 'Caddyfile';

    // Prüfungen
    $email = '/^[^\s@"]+@[^\s@"]+\.[^\s@"]+$/';
    $dur = '/^\d+(\.\d+)?(ns|us|ms|s|m|h|d)$/';
    $size = '/^\d+(\.\d+)?\s*(B|KB|KiB|MB|MiB|GB|GiB)?$/i';
    $path = '#^/[A-Za-z0-9._/@+\-]*$#';
    $cidr = static fn(string $c): bool => cidr_parse($c) !== null || ip_valid($c) || $c === 'private_ranges';
    if ($g['email'] !== '' && !preg_match($email, $g['email'])) {
        $err[] = t('Globale E-Mail-Adresse ungültig.');
    }
    foreach (['http_port', 'https_port'] as $k) {
        if ($g[$k] !== '' && (!ctype_digit($g[$k]) || (int)$g[$k] < 1 || (int)$g[$k] > 65535)) {
            $err[] = t('{f}: Port zwischen 1 und 65535 erwartet.', ['f' => $k]);
        }
    }
    if ($g['admin'] === 'custom' && !preg_match('#^(localhost|[\w.-]+|\[[0-9a-f:]+\]|unix/\S+)?:\d{1,5}$|^unix/\S+$#i', $g['admin_addr'])) {
        $err[] = t('Admin-Adresse ungültig (z. B. localhost:2019).');
    }
    if ($g['acme_ca'] === 'custom' && !preg_match('#^https://\S+$#', $g['acme_ca_url'])) {
        $err[] = t('ACME-Server muss mit https:// beginnen.');
    }
    foreach (['grace_period', 'timeout_read_body', 'timeout_read_header', 'timeout_write', 'timeout_idle'] as $k) {
        if ($g[$k] !== '' && !preg_match($dur, $g[$k])) {
            $err[] = t('{f}: Dauer wie 30s, 5m oder 1h angeben.', ['f' => $k]);
        }
    }
    if ($g['storage'] !== '' && !preg_match($path, $g['storage'])) {
        $err[] = t('Speicherort muss ein absoluter Pfad sein.');
    }
    if ($g['log_file'] !== '' && !preg_match($path, $g['log_file'])) {
        $err[] = t('Log-Datei muss ein absoluter Pfad sein.');
    }
    if ($g['on_demand_ask'] !== '' && !preg_match('#^https?://\S+$#', $g['on_demand_ask'])) {
        $err[] = t('On-Demand-TLS: Prüf-URL muss mit http:// oder https:// beginnen.');
    }
    if ($g['default_sni'] !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $g['default_sni'])) {
        $err[] = t('default_sni ist kein gültiger Name.');
    }
    foreach (preg_split('/[\s,]+/', $g['trusted_proxies'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
        if (!$cidr($c)) {
            $err[] = t('Vertrauenswürdiger Proxy „{v}“ ist keine IP/CIDR.', ['v' => $c]);
        }
    }
    if (!caddy_bool($g, 'h1') && !caddy_bool($g, 'h2') && !caddy_bool($g, 'h3')) {
        $err[] = t('Mindestens ein HTTP-Protokoll (h1, h2, h3) auswählen.');
    }
    $seen = [];
    foreach ($sites as $i => &$s) {
        $n = t('Site {n}', ['n' => $i + 1]);
        $addrs = preg_split('/[\s,]+/', $s['addresses'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($addrs === []) {
            $err[] = $n . ': ' . t('Adresse (Domain) fehlt.');
        }
        foreach ($addrs as $a) {
            if (!preg_match('#^((https?)://)?(\*\.)?([A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*|\[[0-9a-fA-F:]+\]|\d{1,3}(\.\d{1,3}){3})?(:\d{1,5})?(/[A-Za-z0-9._/*-]*)?$#', $a) || $a === '' || $a === 'http://' || $a === 'https://') {
                $err[] = $n . ': ' . t('Adresse „{a}“ ungültig.', ['a' => $a]);
            }
            if (isset($seen[strtolower($a)])) {
                $warn[] = t('Adresse „{a}“ kommt mehrfach vor.', ['a' => $a]);
            }
            $seen[strtolower($a)] = true;
        }
        $s['_addrs'] = $addrs;
        if (!isset(CADDY_MODES[$s['mode']])) {
            $s['mode'] = 'proxy';
        }
        if ($s['mode'] === 'proxy') {
            $ups = preg_split('/[\s,]+/', $s['upstreams'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($ups === []) {
                $err[] = $n . ': ' . t('Mindestens ein Ziel (Upstream) angeben.');
            }
            foreach ($ups as $u) {
                if (!preg_match('#^((h2c|https?)://)?([A-Za-z0-9.-]+|\[[0-9a-fA-F:]+\]|\{[a-z_.]+\})(:\d{1,5})?$|^unix//\S+$#', $u)) {
                    $err[] = $n . ': ' . t('Ziel „{u}“ ungültig (z. B. 10.0.0.5:8080 oder https://host:8443).', ['u' => $u]);
                }
            }
            $s['_ups'] = $ups;
            if (!isset(CADDY_LB[$s['lb']])) {
                $s['lb'] = '';
            }
            if ($s['health_uri'] !== '' && !preg_match($path, $s['health_uri'])) {
                $err[] = $n . ': ' . t('Health-Check-Pfad muss mit / beginnen.');
            }
            if ($s['health_interval'] !== '' && !preg_match($dur, $s['health_interval'])) {
                $err[] = $n . ': ' . t('Health-Check-Intervall wie 10s angeben.');
            }
            if ($s['dial_timeout'] !== '' && !preg_match($dur, $s['dial_timeout'])) {
                $err[] = $n . ': ' . t('Verbindungs-Timeout wie 5s angeben.');
            }
            if ($s['proxy_path'] !== '' && !preg_match('#^/[A-Za-z0-9._/*-]*$#', $s['proxy_path'])) {
                $err[] = $n . ': ' . t('Pfad für den Proxy muss mit / beginnen (z. B. /api/*).');
            }
            if ($s['tls_server_name'] !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $s['tls_server_name'])) {
                $err[] = $n . ': ' . t('TLS-Servername ungültig.');
            }
        }
        if (in_array($s['mode'], ['php', 'static'], true) || $s['spa'] === '1') {
            if (!preg_match($path, $s['root'])) {
                $err[] = $n . ': ' . t('Wurzelverzeichnis muss ein absoluter Pfad sein.');
            }
        }
        if ($s['mode'] === 'php' && !preg_match('#^(unix//[A-Za-z0-9._/-]+|[A-Za-z0-9.-]+:\d{1,5})$#', $s['php_socket'])) {
            $err[] = $n . ': ' . t('PHP-FPM: Socket wie unix//run/php/php8.3-fpm.sock oder 127.0.0.1:9000.');
        }
        if ($s['mode'] === 'redirect') {
            if (!preg_match('#^(https?://[^\s"{}]+|/[^\s"{}]*)(\{uri\}|\{path\})?$#', $s['redirect_to'])) {
                $err[] = $n . ': ' . t('Weiterleitungsziel ungültig (z. B. https://neu.example.com{uri}).');
            }
            if (!in_array($s['redirect_code'], ['permanent', 'temporary', 'html', '301', '302', '307', '308'], true)) {
                $s['redirect_code'] = 'permanent';
            }
        }
        if ($s['mode'] === 'respond' && !preg_match('/^[1-5]\d\d$/', $s['respond_code'])) {
            $err[] = $n . ': ' . t('Statuscode zwischen 100 und 599 angeben.');
        }
        if ($s['mode'] === 'respond' && preg_match('/[{}]/', $s['respond_body'])) {
            $err[] = $n . ': ' . t('Antworttext darf keine Klammern { } enthalten (Caddy-Platzhalter könnten Umgebungsvariablen verraten).');
        }
        if (!isset(CADDY_TLS[$s['tls']])) {
            $s['tls'] = 'auto';
        }
        if ($s['tls_email'] !== '' && !preg_match($email, $s['tls_email'])) {
            $err[] = $n . ': ' . t('E-Mail für das Zertifikat ungültig.');
        }
        if ($s['tls'] === 'files' && (!preg_match($path, $s['tls_cert']) || !preg_match($path, $s['tls_key']))) {
            $err[] = $n . ': ' . t('Zertifikat und Schlüssel als absolute Pfade angeben.');
        }
        if ($s['tls'] === 'dns' && (!preg_match('/^[a-z0-9_]+$/', $s['dns_provider']) || !preg_match('/^[A-Z0-9_]+$/', $s['dns_token_env']))) {
            $err[] = $n . ': ' . t('DNS-Anbieter (z. B. cloudflare) und Umgebungsvariable (z. B. CF_API_TOKEN) angeben.');
        }
        if ($s['tls'] === 'dns') {
            $warn[] = t('DNS-Challenge braucht ein Caddy mit dem passenden DNS-Plugin (xcaddy / caddyserver.com/download).');
        }
        if ($s['mtls_ca'] !== '' && !preg_match($path, $s['mtls_ca'])) {
            $err[] = $n . ': ' . t('CA-Datei für Client-Zertifikate als absoluten Pfad angeben.');
        }
        if (!in_array($s['tls_min'], ['', 'tls1.2', 'tls1.3'], true)) {
            $s['tls_min'] = '';
        }
        if (!in_array($s['frame'], ['', 'DENY', 'SAMEORIGIN'], true)) {
            $s['frame'] = 'SAMEORIGIN';
        }
        if (!in_array($s['referrer'], ['', 'no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'no-referrer-when-downgrade'], true)) {
            $s['referrer'] = 'strict-origin-when-cross-origin';
        }
        foreach (['csp', 'permissions'] as $k) {
            if (preg_match('/[{}"\\\\]/', $s[$k])) {
                $err[] = $n . ': ' . t('{f} darf keine Klammern { } oder Anführungszeichen enthalten.', ['f' => $k === 'csp' ? 'Content-Security-Policy' : 'Permissions-Policy']);
            }
        }
        $hdrs = [];
        foreach (preg_split('/\n/', $s['headers_custom']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(-?[A-Za-z0-9-]+)(?:\s*:?\s+(.+))?$/', $line, $m) || (isset($m[2]) && preg_match('/[{}"\\\\]/', $m[2]))) {
                $err[] = $n . ': ' . t('Header-Zeile „{l}“ ungültig (Name Wert).', ['l' => mb_substr($line, 0, 60)]);
                continue;
            }
            $hdrs[] = [$m[1], $m[2] ?? null];
        }
        $s['_headers'] = $hdrs;
        if ($s['cors_origin'] !== '' && !preg_match('#^(\*|https?://[A-Za-z0-9.-]+(:\d{1,5})?)$#', $s['cors_origin'])) {
            $err[] = $n . ': ' . t('CORS-Origin wie https://app.example.com oder * angeben.');
        }
        $users = [];
        foreach (preg_split('/\n/', $s['auth_users']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) !== 2 || !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $parts[0])) {
                $err[] = $n . ': ' . t('Basic-Auth: je Zeile „benutzer passwort“ (oder bcrypt-Hash).');
                continue;
            }
            $users[] = $parts;
        }
        $s['_users'] = $users;
        if ($s['auth_path'] !== '' && !preg_match('#^/[A-Za-z0-9._/*-]*$#', $s['auth_path'])) {
            $err[] = $n . ': ' . t('Basic-Auth-Pfad muss mit / beginnen.');
        }
        $ips = preg_split('/[\s,]+/', $s['allow_ips'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($ips as $c) {
            if (!$cidr($c)) {
                $err[] = $n . ': ' . t('IP-Freigabe „{v}“ ist keine IP/CIDR.', ['v' => $c]);
            }
        }
        $s['_ips'] = $ips;
        if ($s['max_body'] !== '' && !preg_match($size, $s['max_body'])) {
            $err[] = $n . ': ' . t('Maximale Upload-Größe wie 10MB angeben.');
        }
        if ($s['log'] === '1') {
            if ($s['log_file'] !== '' && !preg_match($path, $s['log_file'])) {
                $err[] = $n . ': ' . t('Log-Datei muss ein absoluter Pfad sein.');
            }
            if ($s['log_roll_size'] !== '' && !preg_match($size, $s['log_roll_size'])) {
                $err[] = $n . ': ' . t('Log-Rotation: Größe wie 50MiB angeben.');
            }
            if ($s['log_roll_keep'] !== '' && !ctype_digit($s['log_roll_keep'])) {
                $err[] = $n . ': ' . t('Log-Rotation: Anzahl als Zahl angeben.');
            }
        }
        if (!in_array($s['www'], ['', 'to_apex', 'to_www'], true)) {
            $s['www'] = '';
        }
        if ($s['extra'] !== '' && substr_count($s['extra'], '{') !== substr_count($s['extra'], '}')) {
            $err[] = $n . ': ' . t('Eigene Direktiven: Klammern { } sind nicht ausgeglichen.');
        }
        if ($s['tls'] === 'auto' && $addrs !== [] && preg_match('#^(localhost|\d|\[|:|http://)#', $addrs[0])) {
            $warn[] = $n . ': ' . t('Für IPs, localhost oder :port stellt Caddy kein öffentliches Zertifikat aus – ggf. „Interne CA“ wählen.');
        }
    }
    unset($s);
    return [['g' => $g, 's' => $sites, 'name' => $name], $err, array_values(array_unique($warn))];
}

/** Zeilen mit Tab-Einrückung zusammensetzen. */
function caddy_block(array &$out, int $lvl, string $open, callable $body): void
{
    $out[] = str_repeat("\t", $lvl) . ($open === '' ? '{' : $open . ' {');
    $before = count($out);
    $body($out, $lvl + 1);
    if (count($out) === $before) {
        array_pop($out);
        if ($open !== '') {
            $out[] = str_repeat("\t", $lvl) . $open;
        }
        return;
    }
    $out[] = str_repeat("\t", $lvl) . '}';
}

function caddy_line(array &$out, int $lvl, string $line): void
{
    $out[] = str_repeat("\t", $lvl) . $line;
}

/** bcrypt-Hash für basic_auth (Caddy erwartet $2a$/$2b$). */
function caddy_bcrypt(string $pw): string
{
    if (preg_match('/^\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}$/', $pw)) {
        return $pw;
    }
    return '$2a$' . substr((string)password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]), 4);
}

function caddy_render(array $c, bool $hashPasswords = true): string
{
    $g = $c['g'];
    $out = ['# ' . $c['name'] . ' – erzeugt mit dem Network Portal ' . APP_VERSION . ' am ' . date('Y-m-d H:i'),
        '# Prüfen:  caddy validate --config ' . $c['name'] . ' --adapter caddyfile',
        '# Format:  caddy fmt --overwrite ' . $c['name'], ''];
    // --- Globale Optionen
    if (caddy_bool($g, 'enabled')) {
        caddy_block($out, 0, '', static function (array &$o, int $l) use ($g): void {
            if (caddy_bool($g, 'debug')) {
                caddy_line($o, $l, 'debug');
            }
            if ($g['email'] !== '') {
                caddy_line($o, $l, 'email ' . caddy_q($g['email']));
            }
            if ($g['admin'] === 'off') {
                caddy_line($o, $l, 'admin off');
            } elseif ($g['admin'] === 'custom') {
                caddy_line($o, $l, 'admin ' . $g['admin_addr']);
            }
            if ($g['http_port'] !== '') {
                caddy_line($o, $l, 'http_port ' . (int)$g['http_port']);
            }
            if ($g['https_port'] !== '') {
                caddy_line($o, $l, 'https_port ' . (int)$g['https_port']);
            }
            if (in_array($g['auto_https'], ['off', 'disable_redirects', 'disable_certs', 'ignore_loaded_certs'], true)) {
                caddy_line($o, $l, 'auto_https ' . $g['auto_https']);
            }
            if ($g['acme_ca'] === 'le_staging') {
                caddy_line($o, $l, 'acme_ca https://acme-staging-v02.api.letsencrypt.org/directory');
            } elseif ($g['acme_ca'] === 'le') {
                caddy_line($o, $l, 'acme_ca https://acme-v02.api.letsencrypt.org/directory');
            } elseif ($g['acme_ca'] === 'zerossl') {
                caddy_line($o, $l, 'acme_ca https://acme.zerossl.com/v2/DV90');
            } elseif ($g['acme_ca'] === 'custom' && $g['acme_ca_url'] !== '') {
                caddy_line($o, $l, 'acme_ca ' . caddy_q($g['acme_ca_url']));
            }
            if (caddy_bool($g, 'local_certs')) {
                caddy_line($o, $l, 'local_certs');
            }
            if (caddy_bool($g, 'skip_install_trust')) {
                caddy_line($o, $l, 'skip_install_trust');
            }
            if ($g['grace_period'] !== '') {
                caddy_line($o, $l, 'grace_period ' . $g['grace_period']);
            }
            if ($g['default_sni'] !== '') {
                caddy_line($o, $l, 'default_sni ' . $g['default_sni']);
            }
            if (caddy_bool($g, 'ocsp_off')) {
                caddy_line($o, $l, 'ocsp_stapling off');
            }
            if ($g['storage'] !== '') {
                caddy_line($o, $l, 'storage file_system ' . caddy_q($g['storage']));
            }
            if ($g['on_demand_ask'] !== '') {
                caddy_block($o, $l, 'on_demand_tls', static fn(array &$x, int $m) => caddy_line($x, $m, 'ask ' . caddy_q($g['on_demand_ask'])));
            }
            $protos = array_values(array_filter(['h1', 'h2', 'h3'], static fn($p) => caddy_bool($g, $p)));
            $tp = preg_split('/[\s,]+/', $g['trusted_proxies'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $timeouts = array_filter(['read_body' => $g['timeout_read_body'], 'read_header' => $g['timeout_read_header'], 'write' => $g['timeout_write'], 'idle' => $g['timeout_idle']]);
            if (count($protos) < 3 || $tp !== [] || $timeouts !== []) {
                caddy_block($o, $l, 'servers', static function (array &$x, int $m) use ($protos, $tp, $timeouts): void {
                    if (count($protos) < 3) {
                        caddy_line($x, $m, 'protocols ' . implode(' ', $protos));
                    }
                    if ($tp !== []) {
                        caddy_line($x, $m, 'trusted_proxies static ' . implode(' ', $tp));
                    }
                    if ($timeouts !== []) {
                        caddy_block($x, $m, 'timeouts', static function (array &$y, int $n) use ($timeouts): void {
                            foreach ($timeouts as $k => $v) {
                                caddy_line($y, $n, $k . ' ' . $v);
                            }
                        });
                    }
                });
            }
            if (caddy_bool($g, 'log')) {
                caddy_block($o, $l, 'log', static function (array &$x, int $m) use ($g): void {
                    caddy_line($x, $m, 'output ' . ($g['log_output'] === 'file' ? 'file ' . caddy_q($g['log_file']) : ($g['log_output'] === 'stdout' ? 'stdout' : 'stderr')));
                    caddy_line($x, $m, 'format ' . ($g['log_format'] === 'json' ? 'json' : 'console'));
                    if (in_array($g['log_level'], ['DEBUG', 'INFO', 'WARN', 'ERROR'], true) && $g['log_level'] !== 'INFO') {
                        caddy_line($x, $m, 'level ' . $g['log_level']);
                    }
                });
            }
        });
        if (end($out) === '}') {
            $out[] = '';
        }
    }
    // --- Sites
    foreach ($c['s'] as $s) {
        $addrs = $s['_addrs'] ?? preg_split('/[\s,]+/', $s['addresses'], -1, PREG_SPLIT_NO_EMPTY);
        if (!$addrs) {
            continue;
        }
        if ($s['tls'] === 'off') {
            $addrs = array_map(static fn($a) => preg_match('#^(https?://|:)#', $a) ? preg_replace('#^https://#', 'http://', $a) : 'http://' . $a, $addrs);
        }
        // www-Umleitung als eigener Block
        if ($s['www'] !== '') {
            $from = [];
            foreach ($addrs as $a) {
                $host = preg_replace('#^https?://#', '', $a);
                if (str_starts_with($host, '*.') || preg_match('#^(\d|\[|:|localhost)#', $host)) {
                    continue;
                }
                $from[] = $s['www'] === 'to_apex' ? (str_starts_with($host, 'www.') ? null : 'www.' . $host) : (str_starts_with($host, 'www.') ? null : $host);
            }
            $from = array_values(array_filter($from));
            if ($from !== []) {
                if ($s['www'] === 'to_www') {
                    $target = 'www.' . preg_replace('#^https?://#', '', $addrs[0]);
                    $out[] = '# Ohne www → www';
                    caddy_block($out, 0, implode(', ', $from), static fn(array &$o, int $l) => caddy_line($o, $l, 'redir https://' . $target . '{uri} permanent'));
                    $addrs = array_map(static fn($h) => 'www.' . preg_replace('#^https?://#', '', $h), $from);
                } else {
                    $target = preg_replace('#^https?://#', '', $addrs[0]);
                    $out[] = '# www → ohne www';
                    caddy_block($out, 0, implode(', ', $from), static fn(array &$o, int $l) => caddy_line($o, $l, 'redir https://' . $target . '{uri} permanent'));
                }
                $out[] = '';
            }
        }
        caddy_block($out, 0, implode(', ', $addrs), static function (array &$o, int $l) use ($s, $hashPasswords): void {
            caddy_site_body($o, $l, $s, $hashPasswords);
        });
        $out[] = '';
    }
    while ($out !== [] && end($out) === '') {
        array_pop($out);
    }
    return implode("\n", $out) . "\n";
}

function caddy_site_body(array &$o, int $l, array $s, bool $hashPasswords): void
{
    // TLS
    switch ($s['tls']) {
        case 'internal':
            caddy_line($o, $l, 'tls internal');
            break;
        case 'files':
            caddy_line($o, $l, 'tls ' . caddy_q($s['tls_cert']) . ' ' . caddy_q($s['tls_key']));
            break;
        case 'dns':
            caddy_block($o, $l, 'tls' . ($s['tls_email'] !== '' ? ' ' . caddy_q($s['tls_email']) : ''), static fn(array &$x, int $m) => caddy_line($x, $m, 'dns ' . $s['dns_provider'] . ' {env.' . $s['dns_token_env'] . '}'));
            break;
        case 'auto':
            if ($s['tls_email'] !== '' && $s['tls_min'] === '' && $s['mtls_ca'] === '') {
                caddy_line($o, $l, 'tls ' . caddy_q($s['tls_email']));
            }
            break;
    }
    if ($s['tls'] !== 'off' && ($s['tls_min'] !== '' || $s['mtls_ca'] !== '') && $s['tls'] !== 'dns') {
        $head = 'tls' . ($s['tls'] === 'auto' && $s['tls_email'] !== '' ? ' ' . caddy_q($s['tls_email']) : '');
        if ($s['tls'] === 'internal' || $s['tls'] === 'files') {
            array_pop($o);
            $head = $s['tls'] === 'internal' ? 'tls internal' : 'tls ' . caddy_q($s['tls_cert']) . ' ' . caddy_q($s['tls_key']);
        }
        caddy_block($o, $l, $head, static function (array &$x, int $m) use ($s): void {
            if ($s['tls_min'] !== '') {
                caddy_line($x, $m, 'protocols ' . $s['tls_min']);
            }
            if ($s['mtls_ca'] !== '') {
                caddy_block($x, $m, 'client_auth', static function (array &$y, int $n) use ($s): void {
                    caddy_line($y, $n, 'mode require_and_verify');
                    caddy_line($y, $n, 'trust_pool file ' . caddy_q($s['mtls_ca']));
                });
            }
        });
    }
    // Zugriffslog
    if ($s['log'] === '1') {
        caddy_block($o, $l, 'log', static function (array &$x, int $m) use ($s): void {
            if ($s['log_file'] !== '') {
                caddy_block($x, $m, 'output file ' . caddy_q($s['log_file']), static function (array &$y, int $n) use ($s): void {
                    if ($s['log_roll_size'] !== '') {
                        caddy_line($y, $n, 'roll_size ' . str_replace(' ', '', $s['log_roll_size']));
                    }
                    if ($s['log_roll_keep'] !== '') {
                        caddy_line($y, $n, 'roll_keep ' . (int)$s['log_roll_keep']);
                    }
                });
            }
            caddy_line($x, $m, 'format ' . ($s['log_format'] === 'console' ? 'console' : 'json'));
        });
    }
    // IP-Freigabe
    if (($s['_ips'] ?? []) !== []) {
        caddy_line($o, $l, '@blocked not ' . ($s['client_ip'] === '1' ? 'client_ip' : 'remote_ip') . ' ' . implode(' ', $s['_ips']));
        caddy_line($o, $l, 'respond @blocked "Forbidden" 403');
    }
    // Upload-Limit
    if ($s['max_body'] !== '') {
        caddy_block($o, $l, 'request_body', static fn(array &$x, int $m) => caddy_line($x, $m, 'max_size ' . str_replace(' ', '', $s['max_body'])));
    }
    // Basic-Auth
    if (($s['_users'] ?? []) !== []) {
        caddy_block($o, $l, 'basic_auth' . ($s['auth_path'] !== '' ? ' ' . $s['auth_path'] : ''), static function (array &$x, int $m) use ($s, $hashPasswords): void {
            foreach ($s['_users'] as [$u, $pw]) {
                caddy_line($x, $m, $u . ' ' . ($hashPasswords ? caddy_bcrypt($pw) : '<bcrypt-Hash>'));
            }
        });
    }
    // Kompression
    $enc = array_values(array_filter(['zstd', 'gzip'], static fn($e) => $s['encode_' . $e] === '1'));
    if ($enc !== []) {
        caddy_line($o, $l, 'encode ' . implode(' ', $enc));
    }
    // Header
    $hdr = [];
    if ($s['hsts'] === '1' && $s['tls'] !== 'off') {
        $hdr[] = ['Strict-Transport-Security', 'max-age=31536000' . ($s['hsts_sub'] === '1' ? '; includeSubDomains' : '') . ($s['hsts_preload'] === '1' ? '; preload' : '')];
    }
    if ($s['nosniff'] === '1') {
        $hdr[] = ['X-Content-Type-Options', 'nosniff'];
    }
    if ($s['frame'] !== '') {
        $hdr[] = ['X-Frame-Options', $s['frame']];
    }
    if ($s['referrer'] !== '') {
        $hdr[] = ['Referrer-Policy', $s['referrer']];
    }
    if ($s['permissions'] !== '') {
        $hdr[] = ['Permissions-Policy', $s['permissions']];
    }
    if ($s['csp'] !== '') {
        $hdr[] = ['Content-Security-Policy', $s['csp']];
    }
    if ($s['noindex'] === '1') {
        $hdr[] = ['X-Robots-Tag', 'noindex, nofollow'];
    }
    if ($s['cors_origin'] !== '') {
        $hdr[] = ['Access-Control-Allow-Origin', $s['cors_origin']];
        $hdr[] = ['Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS'];
        $hdr[] = ['Access-Control-Allow-Headers', 'Content-Type, Authorization'];
        if ($s['cors_origin'] !== '*') {
            $hdr[] = ['Vary', 'Origin'];
        }
    }
    foreach ($s['_headers'] ?? [] as $h) {
        $hdr[] = $h;
    }
    if ($s['remove_server'] === '1') {
        $hdr[] = ['-Server', null];
        $hdr[] = ['-X-Powered-By', null];
    }
    if ($hdr !== []) {
        caddy_block($o, $l, 'header', static function (array &$x, int $m) use ($hdr): void {
            foreach ($hdr as [$k, $v]) {
                caddy_line($x, $m, $k . ($v !== null ? ' ' . caddy_q($v) : ''));
            }
        });
    }
    if ($s['cors_origin'] !== '') {
        caddy_line($o, $l, '@preflight method OPTIONS');
        caddy_line($o, $l, 'respond @preflight 204');
    }
    if ($s['cache_static'] === '1') {
        caddy_line($o, $l, '@static path *.css *.js *.svg *.png *.jpg *.jpeg *.webp *.gif *.ico *.woff *.woff2');
        caddy_line($o, $l, 'header @static Cache-Control "public, max-age=31536000, immutable"');
    }
    // Inhalt
    switch ($s['mode']) {
        case 'proxy':
            $ups = $s['_ups'] ?? [];
            $opts = static function (array &$x, int $m) use ($s): void {
                if ($s['lb'] !== '') {
                    caddy_line($x, $m, 'lb_policy ' . $s['lb']);
                }
                if ($s['health_uri'] !== '') {
                    caddy_line($x, $m, 'health_uri ' . $s['health_uri']);
                    if ($s['health_interval'] !== '') {
                        caddy_line($x, $m, 'health_interval ' . $s['health_interval']);
                    }
                }
                if ($s['host_header'] === '1') {
                    caddy_line($x, $m, 'header_up Host {upstream_hostport}');
                }
                if ($s['real_ip'] === '1') {
                    caddy_line($x, $m, 'header_up X-Real-IP {remote_host}');
                }
                if ($s['flush'] === '1') {
                    caddy_line($x, $m, 'flush_interval -1');
                }
                if ($s['tls_skip_verify'] === '1' || $s['tls_server_name'] !== '' || $s['dial_timeout'] !== '') {
                    caddy_block($x, $m, 'transport http', static function (array &$y, int $n) use ($s): void {
                        if ($s['dial_timeout'] !== '') {
                            caddy_line($y, $n, 'dial_timeout ' . $s['dial_timeout']);
                        }
                        if ($s['tls_server_name'] !== '') {
                            caddy_line($y, $n, 'tls_server_name ' . $s['tls_server_name']);
                        }
                        if ($s['tls_skip_verify'] === '1') {
                            caddy_line($y, $n, 'tls_insecure_skip_verify');
                        }
                    });
                }
            };
            if ($s['proxy_path'] !== '' && $s['proxy_path'] !== '/' && $s['proxy_path'] !== '/*') {
                caddy_block($o, $l, 'handle_path ' . $s['proxy_path'], static function (array &$x, int $m) use ($ups, $opts): void {
                    caddy_block($x, $m, 'reverse_proxy ' . implode(' ', $ups), $opts);
                });
                if ($s['spa'] === '1') {
                    caddy_block($o, $l, 'handle', static function (array &$x, int $m) use ($s): void {
                        caddy_line($x, $m, 'root * ' . caddy_q($s['root']));
                        if ($s['spa'] === '1') {
                            caddy_line($x, $m, 'try_files {path} /index.html');
                        }
                        caddy_line($x, $m, 'file_server');
                    });
                }
            } else {
                caddy_block($o, $l, 'reverse_proxy ' . implode(' ', $ups), $opts);
            }
            break;
        case 'php':
            caddy_line($o, $l, 'root * ' . caddy_q($s['root']));
            caddy_line($o, $l, 'php_fastcgi ' . $s['php_socket']);
            caddy_static_tail($o, $l, $s);
            break;
        case 'static':
            caddy_line($o, $l, 'root * ' . caddy_q($s['root']));
            if ($s['spa'] === '1') {
                caddy_line($o, $l, 'try_files {path} /index.html');
            }
            caddy_static_tail($o, $l, $s);
            break;
        case 'redirect':
            caddy_line($o, $l, 'redir ' . $s['redirect_to'] . ' ' . $s['redirect_code']);
            break;
        case 'respond':
            caddy_line($o, $l, 'respond ' . caddy_q($s['respond_body']) . ' ' . (int)$s['respond_code']);
            break;
    }
    if ($s['errors'] === '1') {
        caddy_block($o, $l, 'handle_errors', static fn(array &$x, int $m) => caddy_line($x, $m, 'respond "{err.status_code} {err.status_text}"'));
    }
    if (trim($s['extra']) !== '') {
        caddy_line($o, $l, '# Eigene Direktiven');
        foreach (preg_split('/\n/', trim($s['extra'])) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            caddy_line($o, $l, ltrim($line));
        }
    }
}

function caddy_static_tail(array &$o, int $l, array $s): void
{
    $hide = preg_split('/[\s,]+/', $s['hide'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $hide = array_values(array_filter($hide, static fn($x) => preg_match('/^[A-Za-z0-9._*\/-]+$/', $x)));
    $fsOpts = [];
    if ($s['browse'] === '1') {
        $fsOpts[] = 'browse';
    }
    if ($s['precompressed'] === '1') {
        $fsOpts[] = 'precompressed zstd br gzip';
    }
    if ($hide !== []) {
        $fsOpts[] = 'hide ' . implode(' ', $hide);
    }
    if ($fsOpts === []) {
        caddy_line($o, $l, 'file_server');
        return;
    }
    caddy_block($o, $l, 'file_server', static function (array &$x, int $m) use ($fsOpts): void {
        foreach ($fsOpts as $f) {
            caddy_line($x, $m, $f);
        }
    });
}

// --- Vorlagen (config/caddy-presets.json) -------------------------------------------------------

function caddy_presets(): array
{
    $d = is_file(CADDY_PRESETS_FILE) ? json_decode((string)file_get_contents(CADDY_PRESETS_FILE), true) : null;
    return is_array($d) ? $d : [];
}

function caddy_presets_save(array $p): void
{
    if (!file_put_atomic(CADDY_PRESETS_FILE, (string)json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0640)) {
        throw new RuntimeException(t('Vorlage konnte nicht gespeichert werden (config/ nicht beschreibbar).'));
    }
}

/** Nur die Eingabefelder (ohne berechnete _-Felder) für Vorlagen/Formular. */
function caddy_strip(array $c): array
{
    foreach ($c['s'] as &$s) {
        foreach (array_keys($s) as $k) {
            if (str_starts_with((string)$k, '_')) {
                unset($s[$k]);
            }
        }
    }
    unset($s);
    return $c;
}
