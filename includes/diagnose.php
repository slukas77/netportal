<?php
/**
 * Diagnose & Debug
 *
 * diag_run()  prüft alle Teile des Portals und liefert je Bereich eine Liste von
 *             Prüfungen: [label, status ok|warn|fail|info, detail, hint, ms].
 *             Wird von der Seite „Diagnose & Debug“ und von
 *             „php bin/netportal-cli.php diagnose“ benutzt.
 *
 * Debug-Modus (Einstellung app.debug): jede Anfrage (Route, Benutzer, Status,
 * Laufzeit, Speicher) sowie PHP-Warnungen und JavaScript-Fehler aus dem Browser
 * landen in .cache/debug.log (rotierend, max. 2 MB). Geheimnisse werden nie
 * protokolliert.
 */

declare(strict_types=1);

const DEBUG_LOG = CACHE_DIR . '/debug.log';

/** Eine Prüfung ausführen und Zeit messen. $fn liefert [status, detail, hint?]. */
function diag_check(string $label, callable $fn): array
{
    $t0 = microtime(true);
    try {
        $r = $fn();
    } catch (Throwable $e) {
        $r = ['fail', get_class($e) . ': ' . $e->getMessage(), ''];
    }
    return ['label' => $label, 'status' => $r[0], 'detail' => (string)($r[1] ?? ''), 'hint' => (string)($r[2] ?? ''), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

function diag_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
    };
}

/** HTTP-Status einer Adresse (für die Prüfung der Webserver-Sperren). */
function diag_http_status(string $url): ?int
{
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: netportal-diagnose\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = @file_get_contents($url, false, $ctx, 0, 2048);
    // PHP 8.4+: http_get_last_response_headers() (die Variable $http_response_header ist ab 8.5 veraltet)
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []); // @phpstan-ignore-line
    if ($body === false && $headers === []) {
        return null;
    }
    return preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($headers[0] ?? ''), $m) ? (int)$m[1] : null;
}

function diag_writable(string $dir): array
{
    if (!is_dir($dir)) {
        return ['fail', t('Ordner fehlt'), t('anlegen und dem Webserver-Benutzer geben')];
    }
    $probe = $dir . '/.diag-' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'x') === false) {
        return ['fail', t('nicht beschreibbar'), 'chown -R www-data ' . $dir];
    }
    @unlink($probe);
    $perm = substr(sprintf('%o', fileperms($dir)), -4);
    return [((int)octdec($perm) & 0007) ? 'warn' : 'ok', t('beschreibbar · Rechte {p}', ['p' => $perm]), ((int)octdec($perm) & 0007) ? t('für „andere“ sperren: chmod o-rwx') : ''];
}

function diag_bin(string $path, array $versionArgs = ['--version']): array
{
    if ($path === '' || !is_file($path) || !is_executable($path)) {
        return ['fail', t('nicht gefunden ({p})', ['p' => $path ?: '–']), ''];
    }
    [$code, $out, $err] = proc_run([$path, ...$versionArgs], '', 5);
    $line = trim(strtok(trim($out . "\n" . $err), "\n") ?: '');
    return ['ok', $path . ($line !== '' ? ' · ' . mb_substr($line, 0, 90) : ''), ''];
}

/** Alle Prüfungen. $deep = auch Netzwerk-/Prozess-Tests (etwas langsamer). */
function diag_run(bool $deep = true): array
{
    $S = [];

    // --- PHP
    $S[t('PHP & Server')] = [
        diag_check('PHP', static fn() => [version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', PHP_VERSION . ' · ' . PHP_SAPI . ' · ' . php_uname('s') . ' ' . php_uname('r'), t('mindestens PHP 8.1')]),
        diag_check(t('PHP-Erweiterungen'), static function (): array {
            $need = ['pdo_sqlite', 'openssl', 'mbstring', 'json', 'zlib'];
            $opt = ['ldap', 'sodium', 'posix'];
            $miss = array_values(array_filter($need, static fn($e) => !extension_loaded($e)));
            $missOpt = array_values(array_filter($opt, static fn($e) => !extension_loaded($e)));
            return [$miss ? 'fail' : ($missOpt ? 'warn' : 'ok'), $miss ? t('fehlt: {l}', ['l' => implode(', ', $miss)]) : t('alle Pflicht-Erweiterungen geladen') . ($missOpt ? ' · ' . t('optional fehlt: {l}', ['l' => implode(', ', $missOpt)]) : ''), t('unter „System & Pakete“ installieren')];
        }),
        diag_check(t('Gesperrte PHP-Funktionen'), static function (): array {
            $dis = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
            $crit = array_values(array_intersect($dis, ['proc_open', 'proc_get_status', 'proc_terminate', 'stream_socket_client', 'stream_select']));
            return [$crit ? 'fail' : 'ok', $crit ? implode(', ', $crit) : t('keine benötigte Funktion gesperrt'), t('php.ini: disable_functions anpassen (Ping, SSH, iperf3, Terminal brauchen proc_open)')];
        }),
        diag_check(t('Speicher & Upload'), static function (): array {
            $mem = (string)ini_get('memory_limit');
            $up = (string)ini_get('upload_max_filesize');
            $post = (string)ini_get('post_max_size');
            $low = $mem !== '-1' && diag_bytes($mem) < 128 * 1048576;
            return [$low ? 'warn' : 'ok', 'memory_limit ' . $mem . ' · upload_max_filesize ' . $up . ' · post_max_size ' . $post . ' · max_execution_time ' . ini_get('max_execution_time'),
                $low ? t('memory_limit mindestens 128M (Icon-Import)') : ''];
        }),
        diag_check(t('Zeitzone & Uhr'), static fn() => ['ok', date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s T'), '']),
        diag_check('OPcache', static fn() => [function_exists('opcache_get_status') && @opcache_get_status(false) ? 'ok' : 'info', function_exists('opcache_get_status') && @opcache_get_status(false) ? t('aktiv') : t('nicht aktiv (optional, schneller mit OPcache)'), '']),
        diag_check(t('Sitzungen'), static function (): array {
            $p = (string)(session_save_path() ?: sys_get_temp_dir());
            $p = preg_replace('/^\d+;/', '', $p) ?? $p;
            return [is_writable($p) ? 'ok' : 'fail', session_name() . ' · ' . $p, t('Sitzungsordner muss beschreibbar sein')];
        }),
    ];

    // --- Dateien
    $S[t('Dateien & Rechte')] = [
        diag_check('config/', static fn() => diag_writable(APP_ROOT . '/config')),
        diag_check('database/', static fn() => diag_writable(DB_DIR)),
        diag_check('.cache/', static fn() => diag_writable(CACHE_DIR)),
        diag_check('icons/', static fn() => diag_writable(ICON_DIR)),
        diag_check('config/integrity.key', static function (): array {
            if (!is_file(KEY_FILE)) {
                return ['fail', t('fehlt'), t('aus der Sicherung zurückspielen')];
            }
            $perm = substr(sprintf('%o', fileperms(KEY_FILE)), -4);
            return [$perm === '0600' || $perm === '0400' ? 'ok' : 'warn', t('Rechte {p}', ['p' => $perm]), $perm === '0600' ? '' : 'chmod 600 config/integrity.key'];
        }),
        diag_check(t('Speicherplatz'), static function (): array {
            $free = @disk_free_space(DB_DIR);
            $cache = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CACHE_DIR, FilesystemIterator::SKIP_DOTS)) as $f) {
                $cache += $f->isFile() ? $f->getSize() : 0;
            }
            return [$free !== false && $free < 200 * 1048576 ? 'warn' : 'ok', t('frei: {f} · .cache: {c}', ['f' => $free === false ? '?' : fmt_bytes((int)$free), 'c' => fmt_bytes($cache)]), ''];
        }),
    ];
    if ($deep && PHP_SAPI !== 'cli') {
        $base = app_base_url();
        $S[t('Dateien & Rechte')][] = diag_check(t('Webserver sperrt Datenordner'), static function () use ($base): array {
            $bad = [];
            $unknown = 0;
            foreach (['config/settings.json', 'config/integrity.key', 'database/users.sql', 'database/_system.sqlite', '.cache/netindex.json', 'includes/util.php', 'bin/netportal-cli.php', 'system/install.php', 'README.md'] as $f) {
                $st = diag_http_status($base . $f);
                if ($st === null) {
                    $unknown++;
                } elseif ($st < 400) {
                    $bad[] = $f . ' (' . $st . ')';
                }
            }
            if ($bad !== []) {
                return ['fail', t('direkt abrufbar: {l}', ['l' => implode(', ', $bad)]), t('Webserver-Konfiguration aus server/ übernehmen (Caddyfile, nginx.conf, .htaccess)')];
            }
            return [$unknown > 0 ? 'info' : 'ok', $unknown > 0 ? t('Server konnte sich selbst nicht erreichen ({u}) – bitte von außen prüfen', ['u' => $base]) : t('alle geprüften Pfade gesperrt'), ''];
        });
    }

    // --- Datenbank & Integrität
    $S[t('Daten & Integrität')] = [
        diag_check(t('System-Datenbank'), static fn() => ['ok', 'SQLite ' . (string)sys_val('SELECT sqlite_version()') . ' · ' . fmt_bytes((int)@filesize(SYS_DB_FILE)) . ' · ' . t('{n} Audit-Einträge', ['n' => fmt_num((int)sys_val('SELECT COUNT(*) FROM audit'))]), '']),
        diag_check(t('Integritätsschlüssel'), static fn() => ['ok', t('Fingerprint {f}', ['f' => integrity_key_fingerprint()]), '']),
    ];
    $integ = null;
    $S[t('Daten & Integrität')][] = diag_check(t('Integritätsprüfung (alle Dateien)'), static function () use (&$integ): array {
        $integ = integrity_run_all();
        $bad = array_keys(array_filter($integ['nets'], static fn($n) => !$n['ok']));
        if (!$integ['users']['ok']) {
            $bad[] = 'users.sql';
        }
        if ($integ['masters'] !== null && !$integ['masters']['ok']) {
            $bad[] = 'masters.sql';
        }
        $inv = array_merge($integ['inventory']['unsealed'], $integ['inventory']['missing']);
        $n = count($integ['nets']) + 1 + ($integ['masters'] !== null ? 1 : 0);
        return [$bad === [] && $inv === [] ? 'ok' : 'fail', $bad === [] && $inv === [] ? t('{n} SQL-Dateien in Ordnung', ['n' => $n]) : t('Abweichung: {l}', ['l' => implode(', ', [...$bad, ...$inv])]), t('Details unter „Integrität“')];
    });
    $S[t('Daten & Integrität')][] = diag_check(t('Audit-Kette'), static function () use (&$integ): array {
        $a = $integ['audit'] ?? audit_verify();
        return [$a['ok'] ? 'ok' : 'fail', (string)$a['message'], t('Details unter „Audit-Log“')];
    });
    $S[t('Daten & Integrität')][] = diag_check(t('Programmdateien'), static function () use (&$integ): array {
        $c = $integ['code'] ?? integrity_check_code();
        if ($c['ok'] === null) {
            return ['warn', (string)$c['detail'], 'php bin/netportal-cli.php manifest'];
        }
        $diff = array_merge($c['changed'], $c['missing'], $c['extra']);
        return [$c['ok'] ? 'ok' : 'warn', $c['ok'] ? (string)$c['detail'] : t('geändert/neu/fehlt: {l}', ['l' => implode(', ', array_slice($diff, 0, 8))]), t('nach einem Update unter „Integrität“ das Manifest neu schreiben')];
    });
    $S[t('Daten & Integrität')][] = diag_check(t('Einstellungen'), static function (): array {
        if (!is_file(SETTINGS_FILE)) {
            return ['info', t('config/settings.json noch nicht angelegt – Standardwerte'), ''];
        }
        json_decode((string)file_get_contents(SETTINGS_FILE), true);
        return [json_last_error() === JSON_ERROR_NONE ? 'ok' : 'fail', json_last_error() === JSON_ERROR_NONE ? t('gültiges JSON') : json_last_error_msg(), t('Datei reparieren oder löschen (dann Standardwerte)')];
    });
    $S[t('Daten & Integrität')][] = diag_check(t('Sperren'), static function (): array {
        $locks = glob(CACHE_DIR . '/locks/*.lock') ?: [];
        $busy = [];
        foreach ($locks as $l) {
            $fh = @fopen($l, 'c');
            if ($fh && !flock($fh, LOCK_EX | LOCK_NB)) {
                $busy[] = basename($l, '.lock');
            } elseif ($fh) {
                flock($fh, LOCK_UN);
            }
            if ($fh) {
                fclose($fh);
            }
        }
        return [$busy ? 'warn' : 'ok', $busy ? t('gerade gesperrt: {l}', ['l' => implode(', ', $busy)]) : t('{n} Sperrdateien, keine hängt', ['n' => count($locks)]), ''];
    });

    // --- Anmeldung
    $S[t('Anmeldung')] = [
        diag_check(t('Benutzer'), static function (): array {
            $u = users_list();
            $admins = array_filter($u, static fn($x) => $x['role'] === 'admin' && (int)$x['active'] === 1);
            return [$admins ? 'ok' : 'warn', t('{n} Konten · {a} aktive Portal-Admins', ['n' => count($u), 'a' => count($admins)]), $admins ? '' : t('mindestens einen Portal-Admin anlegen')];
        }),
        diag_check('HTTPS', static function (): array {
            if (PHP_SAPI === 'cli') {
                return ['info', t('nur im Browser prüfbar (Seite „Diagnose & Debug“)'), ''];
            }
            $https = str_starts_with(app_base_url(), 'https://');
            $local = in_array(parse_url(app_base_url(), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true);
            return [$https ? 'ok' : ($local ? 'info' : 'warn'), app_base_url(), $https ? '' : t('WebAuthn (YubiKey) braucht HTTPS')];
        }),
        diag_check('WebAuthn rpId', static function (): array {
            if (PHP_SAPI === 'cli') {
                return ['info', webauthn_rp_id(), ''];
            }
            $host = (string)parse_url(app_base_url(), PHP_URL_HOST);
            $rp = webauthn_rp_id();
            $ok = $rp === $host || str_ends_with($host, '.' . $rp);
            return [$ok ? 'ok' : 'warn', $rp . ' · ' . t('Portal: {h}', ['h' => $host]), $ok ? '' : t('rpId muss zur Adresse passen (Einstellungen → YubiKey)')];
        }),
        diag_check('PAM', static function (): array {
            if (!setting('auth.pam.enabled')) {
                return ['info', t('ausgeschaltet'), ''];
            }
            $bad = array_filter(pam_diagnose(), static fn($r) => !$r['ok']);
            return [$bad ? 'fail' : 'ok', $bad ? implode(' · ', array_map(static fn($r) => $r['label'] . ': ' . $r['detail'], $bad)) : t('einsatzbereit'), t('PAM-Tester unter „System & Pakete“')];
        }),
        diag_check('LDAP / AD', static function () use ($deep): array {
            if (!setting('auth.ldap.enabled')) {
                return ['info', t('ausgeschaltet'), ''];
            }
            if (!ldap_available()) {
                return ['fail', t('PHP-Erweiterung „ldap“ fehlt'), t('unter „System & Pakete“ installieren')];
            }
            $uri = (string)setting('auth.ldap.uri', '');
            if (!$deep || !preg_match('#^(ldaps?)://([^/:\s]+)(?::(\d+))?#i', $uri, $m)) {
                return ['ok', $uri, ''];
            }
            $port = (int)($m[3] ?? 0) ?: (strtolower($m[1]) === 'ldaps' ? 636 : 389);
            $ip = net_resolve($m[2]);
            $open = $ip !== null && (net_scan_ports($ip, [$port], 2.0, false)[$port]['state'] ?? '') === 'open';
            return [$open ? 'ok' : 'fail', $uri . ' → ' . ($ip ?? t('nicht auflösbar')) . ':' . $port . ' ' . ($open ? t('erreichbar') : t('nicht erreichbar')), t('LDAP-Tester unter „System & Pakete“')];
        }),
        diag_check('Yubico-OTP', static function (): array {
            if (!setting('auth.yubico_otp.enabled')) {
                return ['info', t('ausgeschaltet'), ''];
            }
            $ok = (string)setting('auth.yubico_otp.client_id', '') !== '';
            return [$ok ? 'ok' : 'fail', $ok ? t('Client-ID eingetragen') : t('Client-ID fehlt'), t('Einstellungen → YubiKey')];
        }),
    ];

    // --- Werkzeuge
    $S[t('Netzwerk-Werkzeuge')] = [
        diag_check('ping', static function () use ($deep): array {
            $b = ping_bin();
            if ($b === null) {
                return ['warn', t('nicht gefunden – TCP-Ping als Ersatz'), 'apt install iputils-ping'];
            }
            if (!$deep) {
                return ['ok', $b, ''];
            }
            $r = net_ping('127.0.0.1', 1);
            return [$r['ok'] ? 'ok' : 'warn', $b . ' · 127.0.0.1 ' . ($r['ok'] ? t('antwortet') . ($r['avg'] !== null ? ' (' . $r['avg'] . ' ms, ' . $r['method'] . ')' : '') : t('keine Antwort')), $r['ok'] ? '' : t('ICMP für den Webserver-Benutzer erlauben (net.ipv4.ping_group_range)')];
        }),
        diag_check(t('Namensauflösung'), static function (): array {
            $ip = net_resolve('localhost');
            return [$ip !== null ? 'ok' : 'warn', 'localhost → ' . ($ip ?? '–'), ''];
        }),
        diag_check('iperf3', static fn() => iperf_bin() !== null ? diag_bin((string)iperf_bin(), ['--version']) : ['warn', t('nicht installiert'), 'apt install iperf3']),
        diag_check('ssh', static fn() => diag_bin((string)setting('ssh.ssh_path', '/usr/bin/ssh'), ['-V'])),
        diag_check('ssh-keyscan', static fn() => is_file((string)setting('ssh.keyscan_path')) ? ['ok', (string)setting('ssh.keyscan_path'), ''] : ['warn', t('nicht gefunden'), 'apt install openssh-client']),
        diag_check(t('SSH-Schlüssel des Portals'), static fn() => ssh_public_key() !== null ? ['ok', mb_substr((string)ssh_public_key(), 0, 60) . '…', ''] : ['info', t('noch nicht erzeugt'), t('Einstellungen → SSH → Schlüsselpaar erzeugen')]),
        diag_check('known_hosts', static function (): array {
            $kh = app_path((string)setting('ssh.known_hosts'));
            $dir = dirname($kh);
            return [is_dir($dir) && is_writable($dir) ? 'ok' : 'warn', $kh . (is_file($kh) ? ' · ' . t('{n} Einträge', ['n' => count(file($kh) ?: [])]) : ''), t('Ordner config/ssh/ beschreibbar machen')];
        }),
        diag_check(t('MAC-Erkennung'), static function (): array {
            $parts = [is_readable('/proc/net/arp') ? '/proc/net/arp ✓' : '/proc/net/arp ✗'];
            foreach (['nmblookup' => (string)setting('detect.nmblookup_path', '/usr/bin/nmblookup'), 'ip' => '/usr/sbin/ip', 'oui.txt' => '/usr/share/ieee-data/oui.txt'] as $n => $p) {
                $parts[] = $n . (is_file($p) ? ' ✓' : ' ✗');
            }
            return [is_readable('/proc/net/arp') ? 'ok' : 'warn', implode(' · ', $parts), t('fehlende Pakete unter „System & Pakete“')];
        }),
    ];

    // --- SSH-Terminal
    $S[t('SSH-Terminal')] = [
        diag_check(t('Voraussetzungen'), static function (): array {
            $p = terminal_problems();
            return [$p === [] ? 'ok' : (setting('ssh.terminal_enabled', true) ? 'fail' : 'info'), $p === [] ? t('einsatzbereit') : implode(' · ', $p), t('fehlende Pakete unter „System & Pakete“')];
        }),
        diag_check(t('PHP-Kommandozeile'), static fn() => terminal_php_cli() !== null ? diag_bin((string)terminal_php_cli(), ['-v']) : ['fail', t('nicht gefunden'), 'apt install php' . php_pkg_version() . '-cli']),
    ];
    if ($deep) {
        $S[t('SSH-Terminal')][] = diag_check(t('Pseudo-Terminal (script + stty)'), static function (): array {
            $script = (string)setting('ssh.script_path', '/usr/bin/script');
            if (!is_file($script)) {
                return ['fail', t('script fehlt'), 'apt install bsdutils'];
            }
            [$code, $out] = proc_run([$script, '-qec', 'stty rows 33 cols 111; stty size', '/dev/null'], '', 5);
            $ok = str_contains($out, '33 111');
            return [$ok ? 'ok' : 'fail', $ok ? t('Pseudo-Terminal funktioniert (33×111)') : t('unerwartete Ausgabe: {o}', ['o' => mb_substr(trim($out), 0, 80)]), t('util-linux/bsdutils prüfen')];
        });
        $S[t('SSH-Terminal')][] = diag_check(t('Laufende Sitzungen'), static function (): array {
            $n = 0;
            foreach (glob(TERM_DIR . '/*/status.json') ?: [] as $f) {
                $s = json_decode((string)file_get_contents($f), true);
                $n += ($s['state'] ?? '') === 'running' ? 1 : 0;
            }
            return ['ok', t('{n} aktiv', ['n' => $n]), ''];
        });
    }

    // --- Weitere Funktionen
    $S[t('Weitere Funktionen')] = [
        diag_check(t('Master-Vorlagen'), static function (): array {
            $list = masters_list();
            $conf = 0;
            foreach ($list as $m) {
                foreach (master_plan($m, master_services((int)$m['id']), master_nets((int)$m['id'])) as $r) {
                    $conf += in_array($r['state'], ['conflict', 'nocidr'], true) ? 1 : 0;
                }
            }
            return [$conf ? 'warn' : 'ok', t('{n} Master · {c} Netzwerke mit belegter/ungültiger IP', ['n' => count($list), 'c' => $conf]), $conf ? t('unter „Master“ ansehen') : ''];
        }),
        diag_check(t('Caddyfile-Generator'), static function (): array {
            [$c, $err] = caddy_normalize(caddy_defaults());
            $txt = $err === [] ? caddy_render($c, false) : '';
            return [$txt !== '' && str_contains($txt, 'reverse_proxy') ? 'ok' : 'fail', $txt !== '' ? t('Selbsttest erzeugt {n} Zeilen', ['n' => substr_count($txt, "\n")]) : implode(' · ', $err), ''];
        }),
        diag_check(t('Switch-Verwaltung'), static function (): array {
            $list = switches_list();
            $bad = [];
            $ports = 0;
            foreach ($list as $sw) {
                $tpl = switch_template((string)$sw['template']);
                $ports += count(switch_ports((int)$sw['id']));
                if ($tpl === null) {
                    $bad[] = t('{s}: Vorlage fehlt', ['s' => $sw['name']]);
                    continue;
                }
                $cfg = switch_config((int)$sw['id']);
                if ($tpl['cli'] === 'procurve' && (switch_parse_procurve($cfg)['settings']['hostname'] ?? '') === '') {
                    $bad[] = t('{s}: Konfiguration nicht lesbar', ['s' => $sw['name']]);
                }
            }
            $self = switch_parse_procurve("hostname \"t\"\nvlan 10\n   untagged 1-4\n   exit\n");
            if (($self['vlans'][10]['untagged'] ?? []) !== ['1', '2', '3', '4']) {
                $bad[] = t('Selbsttest ProCurve-Leser fehlgeschlagen');
            }
            return [$bad ? 'fail' : 'ok', t('{n} Switches · {p} Ports · {t} Vorlagen', ['n' => count($list), 'p' => $ports, 't' => count(switch_template_list())]) . ($bad ? ' · ' . implode(' · ', $bad) : ''), ''];
        }),
        diag_check(t('NetMap'), static function (): array {
            $g = netmap_build();
            $tree = netmap_tree($g);
            $hosts = array_filter($g['nodes'], static fn($n) => $n['type'] === 'host');
            $inet = count(array_filter(array_keys($hosts), static fn($id) => array_key_exists($id, $tree['depth']) && netmap_root($tree, $id) === 'inet'));
            // Verweise auf Einträge, die es nicht mehr gibt
            $dangling = 0;
            foreach (store_load('switches')['switch_ports'] as $p) {
                $dangling += $p['device_net'] && !isset($g['nodes'][nm_hid((string)$p['device_net'], (int)$p['device_host'])]) ? 1 : 0;
            }
            foreach (wan_links() as $w) {
                $dangling += $w['router_net'] && !isset($g['nodes'][nm_hid((string)$w['router_net'], (int)$w['router_host'])]) ? 1 : 0;
            }
            return [$dangling ? 'warn' : 'ok', t('{n} Verbindungen · {i} Einträge mit Weg ins Internet', ['n' => count($g['edges']), 'i' => $inet])
                . ($dangling ? ' · ' . t('{n} Verweise auf gelöschte Einträge', ['n' => $dangling]) : ''), $dangling ? t('Port bzw. WAN-Anschluss neu zuordnen') : ''];
        }),
        diag_check(t('WAN-Verwaltung'), static function (): array {
            $list = wan_links();
            $warn = 0;
            $undec = 0;
            foreach ($list as $w) {
                $warn += wan_warnings($w) !== [] ? 1 : 0;
                $undec += (string)$w['secret'] !== '' && secret_decrypt((string)$w['secret']) === null ? 1 : 0;
            }
            $selfOk = secret_decrypt(secret_encrypt('np-selbsttest')) === 'np-selbsttest';
            $st = !$selfOk || $undec ? 'fail' : ($warn ? 'warn' : 'ok');
            return [$st, t('{n} Anschlüsse · {w} mit Hinweis', ['n' => count($list), 'w' => $warn]) . ($undec ? ' · ' . t('{n} Passwörter nicht entschlüsselbar', ['n' => $undec]) : '')
                . ($selfOk ? '' : ' · ' . t('Verschlüsselung (AES-256-GCM) nicht verfügbar')), $warn ? t('unter „WAN-Anschlüsse“ ansehen') : ''];
        }),
        diag_check('Icons', static fn() => ['ok', t('{b} Logos geladen · {s} fest eingebaut · {c} eigene Icons', ['b' => count(brand_icons_dynamic()), 's' => count(brand_icons_static()), 'c' => count(custom_icons())]), '']),
        diag_check(t('Übersetzungen'), static function (): array {
            $en = is_file(APP_ROOT . '/includes/lang/en.php') ? (array)require APP_ROOT . '/includes/lang/en.php' : [];
            $missJs = array_values(array_filter(JS_STRINGS, static fn($s) => !isset($en[$s])));
            return [$missJs ? 'warn' : 'ok', t('{n} englische Texte', ['n' => count($en)]) . ($missJs ? ' · ' . t('fehlend: {l}', ['l' => implode(', ', array_slice($missJs, 0, 5))]) : ''), 'php bin/i18n-check.php'];
        }),
        diag_check(t('Hintergrund-Prüfungen (cron)'), static function (): array {
            $last = integrity_last();
            $age = $last ? time() - (int)$last['ts'] : null;
            $ping = 0;
            foreach (glob(CACHE_DIR . '/status/*.json') ?: [] as $f) {
                $ping = max($ping, (int)filemtime($f));
            }
            $txt = t('letzte Integritätsprüfung: {a}', ['a' => $last ? fmt_ago((int)$last['ts']) : t('nie')]) . ' · ' . t('letzter Status-Eintrag: {b}', ['b' => $ping ? fmt_ago($ping) : t('nie')]);
            return [$age !== null && $age < 86400 ? 'ok' : 'info', $txt, t('cron-Zeilen aus der README einrichten (check, ping, services)')];
        }),
    ];
    return $S;
}

function diag_summary(array $S): array
{
    $c = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
    foreach ($S as $checks) {
        foreach ($checks as $ch) {
            $c[$ch['status']] = ($c[$ch['status']] ?? 0) + 1;
        }
    }
    return $c;
}

/** Klartext-Bericht (für Download und Kommandozeile). Geheimnisse sind nie enthalten. */
function diag_report(array $S, bool $withLog = true): string
{
    $sym = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]', 'info' => '[INFO]'];
    $c = diag_summary($S);
    $o = 'Network Portal ' . APP_VERSION . ' – ' . t('Diagnose-Bericht') . ' ' . date('Y-m-d H:i:s T') . "\n"
        . php_uname('n') . ' · PHP ' . PHP_VERSION . ' · ' . (string)setting('app.title', 'Network Portal') . "\n"
        . t('{ok} in Ordnung · {w} Warnungen · {f} Fehler · {i} Hinweise', ['ok' => $c['ok'], 'w' => $c['warn'], 'f' => $c['fail'], 'i' => $c['info']]) . "\n";
    foreach ($S as $sec => $checks) {
        $o .= "\n== " . $sec . " ==\n";
        foreach ($checks as $ch) {
            $o .= $sym[$ch['status']] . ' ' . $ch['label'] . ': ' . $ch['detail'] . ($ch['status'] !== 'ok' && $ch['hint'] !== '' ? "\n       → " . $ch['hint'] : '') . "\n";
        }
    }
    $o .= "\n== " . t('Einstellungen (ohne Geheimnisse)') . " ==\n" . json_encode(diag_redact(settings_all()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if ($withLog) {
        $o .= "\n== " . t('Debug-Protokoll (letzte 100 Einträge)') . " ==\n";
        foreach (debug_log_tail(100) as $e) {
            $o .= json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
    return $o;
}

function diag_redact(array $a): array
{
    foreach ($a as $k => $v) {
        if (is_array($v)) {
            $a[$k] = diag_redact($v);
        } elseif (preg_match('/pass|secret|token|community|key_part/i', (string)$k) && $v !== '' && $v !== null && !is_bool($v)) {
            $a[$k] = '***';
        }
    }
    return $a;
}

// --- Debug-Protokoll --------------------------------------------------------------------------

function debug_enabled(): bool
{
    return (bool)setting('app.debug', false);
}

function debug_log(array $entry): void
{
    if (!debug_enabled()) {
        return;
    }
    $entry = ['ts' => date('Y-m-d H:i:s'), 'rid' => request_id()] + $entry;
    $line = json_encode(audit_scrub($entry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    if (@filesize(DEBUG_LOG) > 2 * 1048576) {
        @rename(DEBUG_LOG, DEBUG_LOG . '.1');
    }
    @file_put_contents(DEBUG_LOG, $line, FILE_APPEND | LOCK_EX);
    @chmod(DEBUG_LOG, 0660);
}

function debug_log_tail(int $n = 200): array
{
    if (!is_file(DEBUG_LOG)) {
        return [];
    }
    $size = (int)filesize(DEBUG_LOG);
    $fh = fopen(DEBUG_LOG, 'rb');
    fseek($fh, max(0, $size - 400000));
    $lines = explode("\n", trim((string)stream_get_contents($fh)));
    fclose($fh);
    $out = [];
    foreach (array_slice($lines, -$n) as $l) {
        $d = json_decode($l, true);
        if (is_array($d)) {
            $out[] = $d;
        }
    }
    return array_reverse($out);
}

/** Wird früh in index.php aufgerufen: Laufzeit messen, Warnungen mitschreiben. */
function debug_boot(): void
{
    if (!debug_enabled()) {
        return;
    }
    $t0 = microtime(true);
    set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
        debug_log(['type' => 'php', 'level' => match ($no) { E_WARNING, E_USER_WARNING => 'warning', E_NOTICE, E_USER_NOTICE => 'notice', E_DEPRECATED, E_USER_DEPRECATED => 'deprecated', default => 'error' },
            'msg' => $msg, 'file' => str_replace(APP_ROOT . '/', '', $file) . ':' . $line]);
        return false;
    });
    register_shutdown_function(static function () use ($t0): void {
        $err = error_get_last();
        $fatal = $err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
        $p = (string)($_GET['p'] ?? '');
        $a = (string)($_GET['a'] ?? '');
        if ($a === 'diag_clientlog' || ($a === 'term_read' && !$fatal && http_response_code() === 200)) {
            return; // Long-Polling des Terminals nicht mitschreiben
        }
        debug_log(['type' => 'request', 'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''), 'route' => $a !== '' ? 'a=' . $a : 'p=' . $p,
            'status' => http_response_code(), 'ms' => (int)round((microtime(true) - $t0) * 1000), 'mem' => fmt_bytes(memory_get_peak_usage(true)),
            'user' => auth_user_soft()['username'] ?? null, 'ip' => client_ip()]
            + ($fatal ? ['fatal' => $err['message'] . ' @ ' . str_replace(APP_ROOT . '/', '', (string)$err['file']) . ':' . $err['line']] : []));
    });
}
