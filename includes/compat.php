<?php
/**
 * Verträglichkeit: Ersatz für fehlendes mbstring, sichere
 * Aufrufe ggf. gesperrter Funktionen und eine lesbare Meldung statt einer
 * leeren 500-Seite, wenn die Umgebung nicht passt.
 */

declare(strict_types=1);

function compat_call(string $function, ...$args)
{
    return function_exists($function) ? @$function(...$args) : null;
}

if (!function_exists('mb_internal_encoding')) {
    function mb_internal_encoding(?string $encoding = null)
    {
        return $encoding === null ? 'UTF-8' : true;
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        if (preg_match('//u', $string) !== 1) {
            return $length === null ? substr($string, $start) : substr($string, $start, $length);
        }
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        $n = preg_match_all('/./us', $string);
        return $n === false ? strlen($string) : $n;
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        return strtoupper($string);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}

if (!function_exists('mb_str_pad')) {
    // PHP < 8.3
    function mb_str_pad(string $s, int $len, string $pad = ' ', int $type = STR_PAD_RIGHT): string
    {
        $diff = strlen($s) - mb_strlen($s);
        return str_pad($s, $len + $diff, $pad, $type);
    }
}

function compat_set_timezone(): void
{
    if ((string)ini_get('date.timezone') !== '') {
        return;
    }
    $tz = is_readable('/etc/timezone') ? trim((string)@file_get_contents('/etc/timezone')) : '';
    if ($tz === '' && is_link('/etc/localtime') && preg_match('#zoneinfo/(.+)$#', (string)@readlink('/etc/localtime'), $m)) {
        $tz = $m[1];
    }
    date_default_timezone_set(($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) ? $tz : 'Europe/Berlin');
}

function compat_require_environment(): void
{
    $problems = [];
    if (PHP_VERSION_ID < 80100) {
        $problems[] = 'PHP 8.1 oder neuer wird benötigt (installiert: ' . PHP_VERSION . ').';
    }
    foreach (['session_start' => 'session', 'json_encode' => 'json', 'preg_match' => 'pcre', 'hash_hmac' => 'hash'] as $fn => $ext) {
        if (!function_exists($fn)) {
            $problems[] = 'Die PHP-Erweiterung "' . $ext . '" fehlt oder ist deaktiviert.';
        }
    }
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $problems[] = 'Die PHP-Erweiterung "pdo_sqlite" fehlt (Paket php-sqlite3).';
    }
    foreach ([APP_ROOT . '/.cache', APP_ROOT . '/database', APP_ROOT . '/config'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
            $problems[] = 'Der Ordner ' . $dir . ' ist für PHP nicht beschreibbar'
                . ($user !== '' ? ' (PHP läuft als "' . $user . '")' : '') . '. Abhilfe: chown -R '
                . ($user !== '' ? $user : '<php-fpm-Benutzer>') . ' ' . $dir;
        }
    }
    if ($problems === []) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Network Portal kann nicht starten:\n\n- " . implode("\n- ", $problems) . "\n";
    exit;
}
