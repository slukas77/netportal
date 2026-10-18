<?php
/**
 * Kleine Hilfsfunktionen: Ausgabe, URLs, Formulare, CSRF, Flash-Meldungen.
 */

declare(strict_types=1);

function h(mixed $s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Relativer Link auf index.php mit Parametern (null-Werte fallen weg). */
function url(array $params = []): string
{
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
    return './' . ($params !== [] ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

/** Zurück zur vorherigen Seite des Portals (nur Query übernehmen, nie fremde Hosts). */
function back_url(): string
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $q = $ref !== '' ? (string)parse_url($ref, PHP_URL_QUERY) : '';
    parse_str($q, $params);
    unset($params['a']);
    return url(array_map(static fn($v) => is_string($v) ? $v : null, $params));
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Token für die Ausgabe: bei jeder Ausgabe mit einem Zufallswert maskiert (Schutz gegen
 * BREACH bei komprimierten Antworten). Format: hex(pad) . hex(pad XOR token).
 */
function csrf_masked(): string
{
    $tok = (string)hex2bin(csrf_token());
    $pad = random_bytes(strlen($tok));
    return bin2hex($pad) . bin2hex($pad ^ $tok);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_masked()) . '">';
}

function csrf_valid(?string $t): bool
{
    if (!is_string($t) || $t === '' || !isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        return false;
    }
    if (strlen($t) === 128 && ctype_xdigit($t)) {
        $raw = (string)hex2bin($t);
        $t = bin2hex(substr($raw, 0, 32) ^ substr($raw, 32));
    }
    return hash_equals($_SESSION['csrf'], $t);
}

function is_post(): bool
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function is_json_request(): bool
{
    return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

/** POST + gültiges CSRF-Token (Formularfeld oder Header X-CSRF) erzwingen. */
function require_post(): void
{
    if (!is_post()) {
        http_response_code(405);
        header('Allow: POST');
        exit(t('Nur POST erlaubt'));
    }
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null);
    if (!csrf_valid(is_string($t) ? $t : null)) {
        audit('csrf_reject', ['action' => (string)($_GET['a'] ?? '')], null, 'denied');
        if (is_json_request()) {
            json_out(['error' => t('Sitzung abgelaufen – Seite neu laden.')], 419);
        }
        flash(t('Sitzung abgelaufen – bitte erneut versuchen.'), 'error');
        redirect(url());
    }
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** JSON-Body (fetch) oder Formularfelder. */
function input(): array
{
    static $in = null;
    if ($in !== null) {
        return $in;
    }
    $in = $_POST;
    if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $j = json_decode((string)file_get_contents('php://input', false, null, 0, 1048576), true);
        $in = is_array($j) ? $j : [];
    }
    return $in;
}

function in_str(string $key, int $max = 255): string
{
    $v = input()[$key] ?? '';
    if (!is_string($v) && !is_int($v) && !is_float($v)) {
        return '';
    }
    $v = trim(str_replace("\0", '', (string)$v));
    return mb_substr($v, 0, $max);
}

function in_int(string $key, int $default = 0): int
{
    $v = input()[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function in_bool(string $key): bool
{
    $v = input()[$key] ?? '';
    return in_array($v, ['1', 1, true, 'on', 'true'], true);
}

function get_str(string $key, int $max = 200): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // Nur hinter einem konfigurierten Reverse-Proxy dem Header vertrauen
    $proxies = (array)setting('app.trusted_proxies', ['127.0.0.1', '::1']);
    if (in_array($ip, $proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']));
        $cand = end($parts);
        if (filter_var($cand, FILTER_VALIDATE_IP)) {
            $ip = $cand;
        }
    }
    return $ip !== '' ? $ip : 'cli';
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

/** Zeitstempel mit Mikrosekunden und Zeitzone (Audit). */
function now_precise(): string
{
    $d = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new DateTimeImmutable();
    return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s.uP');
}

function random_token(int $bytes = 12): string
{
    return b64url_encode(random_bytes($bytes));
}

function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $r === false ? '' : $r;
}

function fmt_datetime(?string $s, bool $seconds = false): string
{
    if ($s === null || $s === '') {
        return '–';
    }
    $t = strtotime(substr($s, 0, 19));
    return $t ? fmt_dt($t, true, $seconds) : $s;
}

function fmt_ago(?int $ts): string
{
    if ($ts === null || $ts <= 0) {
        return t('nie');
    }
    $d = time() - $ts;
    return match (true) {
        $d < 60 => t('vor {n} s', ['n' => max(1, $d)]),
        $d < 3600 => t('vor {n} min', ['n' => intdiv($d, 60)]),
        $d < 86400 => t('vor {n} h', ['n' => intdiv($d, 3600)]),
        default => fmt_dt($ts),
    };
}

/** Eindeutige ID dieser Anfrage – verbindet alle Audit-Einträge einer Aktion. */
function request_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(6));
}

function app_base_url(): string
{
    $cfg = trim((string)setting('app.base_url', ''));
    if ($cfg !== '') {
        return rtrim($cfg, '/') . '/';
    }
    if (PHP_SAPI === 'cli') {
        return 'http://localhost/';
    }
    // X-Forwarded-Proto nur von eingetragenen Proxys übernehmen
    $fromProxy = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), array_map('strval', (array)setting('app.trusted_proxies', [])), true);
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ($fromProxy && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = 'localhost';
    }
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/** Atomisch schreiben (temporäre Datei + rename). */
function file_put_atomic(string $path, string $data, int $mode = 0640): bool
{
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * HTTP(S)-Abruf (Yubico-Validierung, Icon-Import). Nutzt den in den Einstellungen
 * hinterlegten Proxy bzw. HTTPS_PROXY. Liefert den Inhalt oder false.
 */
/** Antwortet die Adresse überhaupt (egal mit welchem HTTP-Status)? Nutzt denselben Proxy wie http_fetch(). */
function http_reachable(string $url, int $timeout = 4): bool
{
    return http_fetch($url, $timeout, 1024, true) !== false;
}

function http_fetch(string $url, int $timeout = 10, int $maxBytes = 67108864, bool $anyStatus = false): string|false
{
    $opts = ['http' => ['timeout' => $timeout, 'ignore_errors' => $anyStatus, 'follow_location' => 1, 'max_redirects' => 3,
        'header' => "User-Agent: NetworkPortal/" . APP_VERSION . "\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
    $proxy = trim((string)setting('app.http_proxy', '')) ?: (string)(getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: '');
    if ($proxy !== '' && preg_match('#^https?://([^/]+)#', $proxy, $m)) {
        $opts['http']['proxy'] = 'tcp://' . $m[1];
        $opts['http']['request_fulluri'] = false;
    }
    $fh = @fopen($url, 'rb', false, stream_context_create($opts));
    if ($fh === false) {
        return false;
    }
    $data = (string)stream_get_contents($fh, $maxBytes);
    fclose($fh);
    return $data;
}
