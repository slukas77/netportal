<?php
/**
 * Einstellungen liegen als JSON in config/settings.json (nicht in einer
 * Datenbank). Ändern dürfen sie nur Portal-Admins.
 */

declare(strict_types=1);

function settings_defaults(): array
{
    return [
        'app' => [
            'title' => 'Network Portal',
            'company' => '',
            'base_url' => '',
            'trusted_proxies' => ['127.0.0.1', '::1'],
            'http_proxy' => '',              // für Yubico-Validierung und Icon-Import, z. B. http://proxy:3128
            'debug' => false,                // Debug-Protokoll (.cache/debug.log), siehe „Diagnose & Debug“
        ],
        'tools' => [
            'ping_path' => '',               // leer = automatisch (/usr/bin/ping, /bin/ping)
            'ping_count' => 4,
            'ping_timeout' => 1,             // Sekunden je Antwort
            'ping_parallel' => 32,           // "Alle pingen": gleichzeitige Prozesse
            'tcp_timeout' => 1.5,
            'scan_ports' => '21,22,23,25,53,80,88,110,135,139,143,161,389,443,445,465,587,623,636,993,995,1433,1883,3306,3389,5060,5432,5900,5985,5986,6379,8006,8080,8443,9100,9200,10000',
            'http_check' => true,            // Dienste mit URL zusätzlich per HTTP(S) abfragen
            'iperf_path' => '/usr/bin/iperf3',
            'iperf_port' => 5201,            // Standard-Port (je Eintrag und je Test änderbar)
            'iperf_duration' => 5,
            'iperf_udp_bandwidth' => '100M',
            'iperf_max_duration' => 60,
        ],
        'detect' => [
            'auto' => true,                  // beim Anlegen MAC/Hersteller/Hardware-Kennung ermitteln
            'nmblookup_path' => '/usr/bin/nmblookup',
            'snmp_enabled' => false,
            'snmp_community' => 'public',
            'snmpget_path' => '/usr/bin/snmpget',
            'ssh_hw' => false,               // Hardware-Kennung per SSH (machine-id / DMI) abfragen
        ],
        'audit' => [
            'retention_days' => 90,          // Audit-Einträge und SSH-Aufzeichnungen nach so vielen Tagen löschen (0 = nie)
        ],
        'system' => [
            'admin_helper' => '/usr/local/sbin/netportal-admin',
            'icons_registry' => 'https://registry.npmjs.org/simple-icons',
            'php_cli' => '',                 // leer = automatisch (/usr/bin/php…)
        ],
        'ssh' => [
            'enabled' => true,               // Banner + Fingerprint (nur lesend)
            'exec_enabled' => false,         // Befehle über SSH ausführen
            'ssh_path' => '/usr/bin/ssh',
            'keyscan_path' => '/usr/bin/ssh-keyscan',
            'keygen_path' => '/usr/bin/ssh-keygen',
            'key_file' => 'config/ssh/id_ed25519',
            'known_hosts' => 'config/ssh/known_hosts',
            'strict_host_key' => 'accept-new', // yes | accept-new
            'default_user' => 'root',
            'timeout' => 30,
            'allow_free' => false,           // beliebige Befehle (nur Administratoren)
            'terminal_enabled' => true,      // SSH-Terminal im Browser (Power-User, Portal-Admins)
            'terminal_key_poweruser' => false, // Portal-Schlüssel im Terminal auch für Power-User
            'terminal_max_sessions' => 4,    // gleichzeitige Sitzungen je Benutzer
            'terminal_idle' => 120,          // Sekunden ohne Browser-Verbindung → Sitzung endet
            'terminal_max_minutes' => 480,   // Höchstdauer einer Sitzung
            'terminal_record' => false,      // Bildschirmausgabe aufzeichnen (database/terminal/)
            'script_path' => '/usr/bin/script',
            'allowlist' => "uptime\nhostname -f\nuname -a\ncat /etc/os-release\ndf -h\nfree -m\nip -br address\nip route\nss -tulpen\nsystemctl --failed --no-pager\nwho\nlast -n 10",
        ],
        'auth' => [
            'session_idle' => 4 * 3600,
            'local' => ['enabled' => true],
            'require_2fa_admin' => false,    // Administratoren müssen einen YubiKey nutzen
            'pam' => [
                'enabled' => false,
                'mechanism' => 'helper',
                'helper_path' => '/usr/local/sbin/netportal-pam-check',
                'pamtester_path' => '/usr/bin/pamtester',
                'service' => 'netportal',
                'autoprovision' => true,
                'default_role' => 'leser',
            ],
            'pam_yubikey' => [
                'enabled' => false,
                'service' => 'netportal-yubikey',
                'u2f_server' => false,       // pam_u2f mit Key am Server (Kiosk)
            ],
            'ldap' => [
                'enabled' => false,
                'mode' => 'ad',              // ad (Windows AD DC) | generic (OpenLDAP, FreeIPA …)
                'uri' => 'ldaps://dc01.firma.local',
                'starttls' => false,
                'ca_file' => '',
                'verify_cert' => true,
                'ad_domain' => 'firma.local',  // UPN-Suffix (max@firma.local)
                'ad_netbios' => 'FIRMA',       // FIRMA\max
                'base_dn' => 'DC=firma,DC=local',
                'bind_dn' => '',             // z. B. svc-netportal@firma.local
                'bind_password' => '',
                'user_filter' => '',         // leer = Standard je Modus
                'nested_groups' => true,     // AD: LDAP_MATCHING_RULE_IN_CHAIN
                'allowed_group' => '',       // nur Mitglieder dürfen sich anmelden (leer = alle)
                'admin_group' => '',
                'operator_group' => '',      // Gruppe → Power-User
                'autoprovision' => true,
                'default_role' => 'leser',
                'timeout' => 5,
            ],
            'webauthn' => [
                'rp_id' => '',
                'rp_name' => 'Network Portal',
            ],
            'yubico_otp' => [
                'enabled' => false,
                'client_id' => '',
                'secret_key' => '',
                'api_url' => 'https://api.yubico.com/wsapi/2.0/verify',
            ],
        ],
    ];
}

function settings_merge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($base[$k])) {
            $base[$k] = settings_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function settings_all(bool $reload = false): array
{
    static $s = null;
    if ($s !== null && !$reload) {
        return $s;
    }
    $stored = [];
    if (is_file(SETTINGS_FILE)) {
        $j = json_decode((string)file_get_contents(SETTINGS_FILE), true);
        $stored = is_array($j) ? $j : [];
    }
    return $s = settings_merge(settings_defaults(), $stored);
}

/** setting('auth.ldap.uri') */
function setting(string $path, mixed $default = null): mixed
{
    $cur = settings_all();
    foreach (explode('.', $path) as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) {
            return $default;
        }
        $cur = $cur[$p];
    }
    return $cur;
}

function settings_save(array $all): bool
{
    if (!is_dir(dirname(SETTINGS_FILE))) {
        @mkdir(dirname(SETTINGS_FILE), 0770, true);
    }
    $ok = file_put_atomic(SETTINGS_FILE, (string)json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0640);
    settings_all(true);
    return $ok;
}

/** Pfad relativ zum Programmordner auflösen. */
function app_path(string $p): string
{
    return str_starts_with($p, '/') ? $p : APP_ROOT . '/' . $p;
}
