<?php
/**
 * System-Prüfung: PHP-Module, Systempakete (Debian/Ubuntu), Funktionsstatus
 * (PAM, PAM+YubiKey, YubiKey, LDAP/AD, iperf3, MAC-Erkennung, SSH) und
 * Installation fehlender Pakete über den root-Helfer.
 *
 * Installation: PHP läuft ohne root-Rechte. Pakete installiert der kleine
 * Helfer /usr/local/sbin/netportal-admin (system/), den ein Admin einmalig mit
 *     cd system && sudo make install
 * einrichtet. Er ist per sudoers NUR für den Webserver-Benutzer und NUR mit
 * einer festen Liste erlaubter Paketnamen aufrufbar.
 */

declare(strict_types=1);

function os_info(): array
{
    $r = ['PRETTY_NAME' => php_uname('s') . ' ' . php_uname('r'), 'ID' => '', 'ID_LIKE' => '', 'VERSION_ID' => ''];
    if (is_readable('/etc/os-release')) {
        foreach (file('/etc/os-release', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)="?(.*?)"?$/', $line, $m)) {
                $r[$m[1]] = $m[2];
            }
        }
    }
    $r['debian_like'] = in_array($r['ID'], ['debian', 'ubuntu', 'linuxmint', 'raspbian', 'pop'], true) || str_contains((string)$r['ID_LIKE'], 'debian');
    return $r;
}

function php_pkg_version(): string
{
    return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
}

/** PHP-Module: ext => [Zweck, Paket-Suffix|null (fest eingebaut), Pflicht] */
function php_module_list(): array
{
    return [
        'pdo_sqlite' => [t('System-DB (Audit, Siegel)'), 'sqlite3', true],
        'mbstring' => [t('Umlaute/Unicode'), 'mbstring', false],
        'openssl' => [t('YubiKey (WebAuthn), HTTPS-Abrufe'), null, true],
        'sodium' => [t('YubiKey mit Ed25519-Schlüssel'), null, false],
        'ldap' => [t('LDAP / Windows Active Directory'), 'ldap', false],
        'zlib' => [t('Icon-Import (.tgz)'), null, false],
        'posix' => [t('Anzeige des PHP-Benutzers'), null, false],
    ];
}

/** PHP-Kommandozeile als Paket (für das SSH-Terminal). */
function php_cli_package(): string
{
    return 'php' . php_pkg_version() . '-cli';
}

/** Systempakete: paket => [Zweck, Prüfdateien (eine genügt)] */
function sys_package_list(): array
{
    return [
        'iputils-ping' => [t('Ping (ICMP)'), ['/usr/bin/ping', '/bin/ping']],
        'openssh-client' => [t('SSH, ssh-keyscan, ssh-keygen'), ['/usr/bin/ssh']],
        php_cli_package() => [t('PHP-Kommandozeile (SSH-Terminal)'), ['/usr/bin/php' . php_pkg_version(), '/usr/bin/php']],
        'iperf3' => [t('iperf3-Durchsatztests'), ['/usr/bin/iperf3']],
        'iproute2' => [t('MAC-Ermittlung (ip neigh)'), ['/usr/sbin/ip', '/sbin/ip', '/usr/bin/ip', '/bin/ip']],
        'samba-common-bin' => [t('MAC/Name von Windows-Geräten (nmblookup)'), ['/usr/bin/nmblookup']],
        'ieee-data' => [t('Hersteller aus der MAC (OUI-Liste)'), ['/usr/share/ieee-data/oui.txt']],
        'snmp' => [t('Hardware-Kennung per SNMP (snmpget)'), ['/usr/bin/snmpget']],
        'ldap-utils' => [t('LDAP-Diagnose (ldapsearch)'), ['/usr/bin/ldapsearch']],
        'libpam-yubico' => [t('PAM + YubiKey (OTP, pam_yubico)'), ['@pam:pam_yubico.so']],
        'libpam-u2f' => [t('PAM + YubiKey (FIDO2, pam_u2f)'), ['@pam:pam_u2f.so']],
        'pamtester' => [t('PAM-Mechanismus „pamtester“'), ['/usr/bin/pamtester']],
        'build-essential' => [t('PAM-Helfer bauen (gcc, make)'), ['/usr/bin/gcc', '/usr/bin/make']],
        'libpam0g-dev' => [t('PAM-Helfer bauen (Header)'), ['/usr/include/security/pam_appl.h']],
    ];
}

function pam_module_path(string $mod): ?string
{
    foreach (array_merge(glob('/lib/*/security/' . $mod) ?: [], glob('/usr/lib/*/security/' . $mod) ?: [], ['/lib/security/' . $mod, '/lib64/security/' . $mod, '/usr/lib/security/' . $mod, '/usr/lib64/security/' . $mod]) as $p) {
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/** Ist ein Paket installiert? dpkg-query, sonst über die Prüfdateien. */
function pkg_installed(string $pkg, array $files = []): bool
{
    foreach ($files as $f) {
        if (str_starts_with($f, '@pam:') ? pam_module_path(substr($f, 5)) !== null : is_file($f)) {
            return true;
        }
    }
    static $dpkg = null;
    $dpkg ??= is_executable('/usr/bin/dpkg-query') ? '/usr/bin/dpkg-query' : '';
    if ($dpkg === '') {
        return false;
    }
    [$code, $out] = proc_run([$dpkg, '-W', '-f=${Status}', $pkg], '', 5);
    return $code === 0 && str_contains($out, 'install ok installed');
}

/** Alle Paketnamen, die das Portal installieren darf (muss zum Helfer passen). */
function sys_install_allowlist(): array
{
    $list = array_keys(sys_package_list());
    foreach (php_module_list() as [$_, $pkg]) {
        if ($pkg !== null) {
            $list[] = 'php' . php_pkg_version() . '-' . $pkg;
        }
    }
    return $list;
}

function admin_helper(): string
{
    return (string)setting('system.admin_helper', '/usr/local/sbin/netportal-admin');
}

/** Kann das Portal den root-Helfer aufrufen? ['ok', 'detail'] */
function admin_helper_status(): array
{
    $h = admin_helper();
    if (!is_file($h)) {
        return ['ok' => false, 'detail' => t('Helfer nicht installiert')];
    }
    if (!is_executable('/usr/bin/sudo')) {
        return ['ok' => false, 'detail' => t('sudo fehlt')];
    }
    [$code, $out, $err] = proc_run(['/usr/bin/sudo', '-n', $h, 'status'], '', 8);
    if ($code === 0 && str_contains($out, 'netportal-admin ok')) {
        return ['ok' => true, 'detail' => trim($out)];
    }
    return ['ok' => false, 'detail' => t('sudo-Freigabe fehlt oder falsch ({e})', ['e' => trim($err) ?: 'exit ' . $code])];
}

/** Funktionsübersicht: [Funktion => [[Prüfung, ok|null, Hinweis], …]] */
function feature_checks(): array
{
    $mech = (string)setting('auth.pam.mechanism', 'helper');
    $helper = (string)setting('auth.pam.helper_path');
    $helperOk = is_file($helper) && (fileperms($helper) & 04000) && fileowner($helper) === 0;
    $https = str_starts_with(app_base_url(), 'https://') || in_array(parse_url(app_base_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
    $ldapUri = (string)setting('auth.ldap.uri', '');
    $ldapReach = null;
    if (setting('auth.ldap.enabled') && preg_match('#^(ldaps?)://([^/:\s]+)(?::(\d+))?#i', $ldapUri, $m)) {
        $port = (int)($m[3] ?? 0) ?: (strtolower($m[1]) === 'ldaps' ? 636 : 389);
        $ip = net_resolve($m[2]);
        $ldapReach = $ip !== null && (net_scan_ports($ip, [$port], 2.0, false)[$port]['state'] ?? '') === 'open';
    }
    // Zeile: [Bezeichnung, ok (true/false/null), Hinweis, Link (optional), Schalter (optional)]
    // Ist der Schalter einer Funktion aus, gilt sie als „nicht genutzt“ und wird nicht als Fehler angezeigt.
    $set = static fn(string $anchor): string => url(['p' => 'settings']) . '#' . $anchor;
    return [
        'PAM' => [
            [t('Anmeldung über PAM aktiviert'), (bool)setting('auth.pam.enabled'), t('Einstellungen → Anmeldung'), $set('authSection'), true],
            [$mech === 'helper' ? t('PAM-Helfer (setuid root)') : 'pamtester', $mech === 'helper' ? $helperOk : is_file((string)setting('auth.pam.pamtester_path')), t('unten „PAM-Helfer installieren“')],
            ['/etc/pam.d/netportal', is_file('/etc/pam.d/netportal'), t('wird mit dem PAM-Helfer installiert')],
        ],
        'PAM + YubiKey' => [
            [t('Aktiviert'), (bool)setting('auth.pam_yubikey.enabled'), t('Einstellungen → Anmeldung'), $set('authSection'), true],
            [t('PAM-Modul pam_yubico (OTP)'), pam_module_path('pam_yubico.so') !== null, 'libpam-yubico'],
            [t('PAM-Modul pam_u2f (FIDO2)'), pam_module_path('pam_u2f.so') !== null, 'libpam-u2f'],
            ['/etc/pam.d/netportal-yubikey', is_file('/etc/pam.d/netportal-yubikey'), t('Vorlage pam-helper/netportal-yubikey.pam anpassen')],
        ],
        t('YubiKey (FIDO2/WebAuthn, für jede Anmeldeart)') => [
            [t('PHP openssl'), extension_loaded('openssl'), ''],
            [t('PHP sodium (Ed25519-Keys)'), extension_loaded('sodium'), t('optional')],
            [t('HTTPS (sicherer Kontext im Browser)'), $https, t('Portal über https:// aufrufen bzw. Basis-URL setzen')],
        ],
        'Yubico-OTP' => [
            [t('Aktiviert'), (bool)setting('auth.yubico_otp.enabled'), t('Einstellungen → YubiKey'), $set('yubikeySection'), true],
            [t('Client-ID eingetragen'), preg_match('/^\d{1,10}$/', trim((string)setting('auth.yubico_otp.client_id'))) === 1, t('API-Zugang bei Yubico holen'), 'https://upgrade.yubico.com/getapikey/'],
            [t('Secret-Key eingetragen'), trim((string)setting('auth.yubico_otp.secret_key')) !== '', t('Einstellungen → YubiKey'), $set('yubikeySection')],
            [t('Validierungsserver erreichbar'), yubico_reachable(), t('Internet/Proxy prüfen (Einstellungen → Allgemein → HTTP-Proxy)')],
        ],
        t('LDAP / Active Directory') => [
            [t('Aktiviert und Server eingetragen'), setting('auth.ldap.enabled') && $ldapUri !== '', t('Einstellungen → LDAP'), $set('ldapSection'), true],
            [t('PHP-Erweiterung ldap'), extension_loaded('ldap'), 'php' . php_pkg_version() . '-ldap'],
            [t('Server erreichbar ({u})', ['u' => $ldapUri ?: '–']), $ldapReach, t('Firewall / DNS prüfen')],
        ],
        t('Werkzeuge') => [
            ['ping', ping_bin() !== null, 'iputils-ping'],
            ['iperf3', iperf_bin() !== null, 'iperf3'],
            ['ssh / ssh-keyscan', is_file((string)setting('ssh.ssh_path')) && is_file((string)setting('ssh.keyscan_path')), 'openssh-client'],
            [t('SSH-Schlüssel des Portals'), ssh_public_key() !== null, t('Einstellungen → SSH-Schlüssel erzeugen'), $set('sshKeySection')],
        ],
        t('SSH-Terminal im Browser') => [
            [t('Eingeschaltet'), (bool)setting('ssh.terminal_enabled', true), t('Einstellungen → SSH'), $set('sshSection'), true],
            ['ssh', is_file((string)setting('ssh.ssh_path')) && is_executable((string)setting('ssh.ssh_path')), 'openssh-client'],
            ['script (util-linux)', is_file((string)setting('ssh.script_path', '/usr/bin/script')), 'bsdutils'],
            [t('PHP-Kommandozeile'), terminal_php_cli() !== null, 'php' . php_pkg_version() . '-cli'],
            ['proc_open', function_exists('proc_open'), 'php.ini disable_functions'],
        ],
        t('MAC- und Hardware-Erkennung') => [
            ['/proc/net/arp', is_readable('/proc/net/arp'), ''],
            ['ip neigh', pkg_installed('iproute2', sys_package_list()['iproute2'][1]), 'iproute2'],
            ['nmblookup (NetBIOS)', is_file((string)setting('detect.nmblookup_path')), 'samba-common-bin'],
            [t('Hersteller-Liste (OUI)'), oui_vendor('00:50:56:00:00:00') !== null, 'ieee-data'],
            ['SNMP', setting('detect.snmp_enabled') ? is_file((string)setting('detect.snmpget_path')) : null, t('optional, Einstellungen → Erkennung'), $set('detectSection')],
        ],
    ];
}

// --- Installation (über den root-Helfer, im Hintergrund) ----------------------------------------

function sys_job_file(): string
{
    return CACHE_DIR . '/sys-job.json';
}

function sys_job_log(): string
{
    return CACHE_DIR . '/sys-job.log';
}

/** Startet den Helfer im Hintergrund. $cmd: ['install', pkg…] oder ['pam-helper'] */
function sys_job_start(array $cmd): array
{
    $st = admin_helper_status();
    if (!$st['ok']) {
        return ['ok' => false, 'error' => $st['detail']];
    }
    $job = sys_job_status();
    if ($job['running']) {
        return ['ok' => false, 'error' => t('Es läuft bereits eine Installation.')];
    }
    @unlink(sys_job_log());
    $env = ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C'];
    $proc = @proc_open(['/usr/bin/sudo', '-n', admin_helper(), ...$cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['file', sys_job_log(), 'a'], 2 => ['file', sys_job_log(), 'a']],
        $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => t('Helfer konnte nicht gestartet werden.')];
    }
    $pid = (int)(proc_get_status($proc)['pid'] ?? 0);
    file_put_atomic(sys_job_file(), (string)json_encode(['pid' => $pid, 'cmd' => $cmd, 'started' => time(), 'by' => current_actor()]), 0660);
    // Prozess bewusst NICHT schließen (proc_close würde warten) – er läuft im Hintergrund weiter.
    return ['ok' => true, 'pid' => $pid];
}

function sys_job_status(): array
{
    $j = is_file(sys_job_file()) ? json_decode((string)file_get_contents(sys_job_file()), true) : null;
    $log = is_file(sys_job_log()) ? (string)file_get_contents(sys_job_log(), false, null, max(0, (int)@filesize(sys_job_log()) - 20000)) : '';
    $rc = preg_match('/NETPORTAL-DONE rc=(\d+)/', $log, $m) ? (int)$m[1] : null;
    $alive = is_array($j) && !empty($j['pid']) && $rc === null && is_dir('/proc/' . (int)$j['pid']) && time() - (int)$j['started'] < 1800;
    return ['job' => $j, 'running' => $alive, 'rc' => $rc, 'log' => $log];
}
