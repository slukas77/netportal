<?php
/**
 * Prüft die Übersetzungen: sammelt alle t()/th()-Texte und die übersetzten
 * Konstanten und meldet fehlende/überzählige Einträge in includes/lang/en.php
 * sowie abweichende {Platzhalter}.  Aufruf: php bin/i18n-check.php [--json datei]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
define('APP_ROOT', $root); define('APP_VERSION','x'); define('SETTINGS_FILE',"$root/config/settings.json");
define('KEY_FILE',"$root/config/integrity.key"); define('DB_DIR',"$root/database"); define('SYS_DB_FILE',DB_DIR.'/_system.sqlite');
define('CACHE_DIR',"$root/.cache"); define('MANIFEST_FILE',"$root/manifest.sha256");
require "$root/includes/compat.php";
require "$root/includes/bootstrap.php";
$keys = []; $warn = [];
$files = array_merge(glob("$root/includes/*.php"), glob("$root/includes/pages/*.php"), ["$root/index.php", "$root/bin/netportal-cli.php"]);
foreach ($files as $f) {
    $toks = array_values(array_filter(token_get_all(file_get_contents($f)), fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
    $n = count($toks);
    for ($i = 0; $i < $n - 2; $i++) {
        $t = $toks[$i];
        if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], ['t', 'th'], true) || $toks[$i+1] !== '(') continue;
        $p = $toks[$i-1] ?? null;
        if (is_array($p) && in_array($p[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON])) continue;
        $a = $toks[$i+2];
        if (is_array($a) && $a[0] === T_CONSTANT_ENCAPSED_STRING) {
            $next = $toks[$i+3];
            if ($next === '.') { $warn[] = basename($f).':'.$a[2].' concatenation'; }
            $lit = $a[1];
            $s = $lit[0] === "'" ? strtr(substr($lit, 1, -1), ["\\\\" => "\\", "\\'" => "'"]) : stripcslashes(substr($lit, 1, -1));
            $keys[$s][] = basename($f) . ':' . $a[2];
        } elseif (!(is_array($a) && in_array($a[0], [T_VARIABLE, T_STRING]))) {
            $warn[] = basename($f).':'.$t[2].' non-literal ' . (is_array($a) ? token_name($a[0]) : $a);
        }
    }
}
$add = function ($s, $src) use (&$keys) { if (is_string($s) && $s !== '') $keys[$s][] = $src; };
foreach (AUDIT_ACTION_LABELS as $v) $add($v, 'AUDIT_ACTION_LABELS');
foreach (array_unique(AUDIT_CATEGORIES) as $v) $add($v, 'AUDIT_CATEGORIES');
foreach (ROLES as $v) $add($v, 'ROLES');
foreach (AUTH_SOURCES as $v) $add($v, 'AUTH_SOURCES');
foreach (ROLE_HINTS as $v) $add($v, 'ROLE_HINTS');
foreach (NET_KINDS as $v) $add($v, 'NET_KINDS');
foreach (HOST_KINDS as $v) $add($v, 'HOST_KINDS');
foreach (HOST_ICONS as $v) $add($v[1], 'HOST_ICONS');
foreach (AD_BIND_ERRORS as $v) $add($v[1], 'AD_BIND_ERRORS');
foreach (JS_STRINGS as $v) $add($v, 'JS_STRINGS');
foreach ([WAN_TYPES, WAN_AUTH, WAN_IPV4, WAN_IPV6, WAN_STATUS] as $c) foreach ($c as $v) $add($v, 'WAN_*');
foreach (WAN_ORIGINALS as $o) foreach (['name', 'description', 'user_hint', 'modem', 'sla', 'product'] as $k) $add($o[$k], 'WAN_ORIGINALS');
foreach (SWITCH_ORIGINALS as $o) foreach (['description', 'console'] as $k) $add($o[$k], 'SWITCH_ORIGINALS');
foreach (store_defs() as $d) $add($d['title'], 'store_defs');
$add('Host', 'hosts.php');
ksort($keys);
if (($j = array_search('--json', $argv, true)) !== false && isset($argv[$j + 1])) file_put_contents($argv[$j + 1], json_encode(array_map(fn($v) => array_values(array_unique($v)), $keys), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, count($keys) . " Texte\n");
// Abdeckung prüfen
if (is_file("$root/includes/lang/en.php")) {
    $en = require "$root/includes/lang/en.php";
    $miss = array_diff(array_keys($keys), array_keys($en));
    $extra = array_diff(array_keys($en), array_keys($keys));
    $bad = count($miss);
    fwrite(STDOUT, "missing: " . count($miss) . "\n" . implode("\n", array_slice($miss, 0, 400)) . "\nextra: " . count($extra) . "\n" . implode("\n", array_slice($extra, 0, 50)) . "\n");
    // Platzhalter vergleichen
    foreach ($en as $de => $e) {
        preg_match_all('/\{\w+\}/', $de, $a); preg_match_all('/\{\w+\}/', $e, $b);
        sort($a[0]); sort($b[0]);
        if ($a[0] !== $b[0]) { fwrite(STDOUT, "placeholder mismatch: $de\n"); $bad++; }
    }
}
exit(empty($bad) ? 0 : 1);

