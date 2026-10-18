<?php
/**
 * Einstellungen (nur Portal-Admins). Jede Änderung wird feldweise (alt → neu,
 * Geheimnisse maskiert) im Audit-Log festgehalten. Dazu: SSH-Schlüssel und Icons.
 */

declare(strict_types=1);

const ROLE_OPTIONS = ['leser', 'poweruser', 'admin'];

const SETTINGS_FIELDS = [
    'app.title' => ['str', 80], 'app.company' => ['str', 120], 'app.base_url' => ['url'], 'app.trusted_proxies' => ['iplist'], 'app.http_proxy' => ['url'], 'app.debug' => ['bool'],
    'auth.session_idle' => ['minutes', 5, 1440],
    'tools.ping_path' => ['path'], 'tools.ping_count' => ['int', 1, 20], 'tools.ping_timeout' => ['int', 1, 5], 'tools.ping_parallel' => ['int', 1, 128],
    'tools.tcp_timeout' => ['float', 0.2, 10], 'tools.scan_ports' => ['ports'], 'tools.http_check' => ['bool'],
    'tools.iperf_path' => ['path'], 'tools.iperf_port' => ['int', 1, 65535], 'tools.iperf_duration' => ['int', 1, 300], 'tools.iperf_max_duration' => ['int', 1, 300],
    'tools.iperf_udp_bandwidth' => ['bw'],
    'detect.auto' => ['bool'], 'detect.nmblookup_path' => ['path'], 'detect.snmp_enabled' => ['bool'], 'detect.snmp_community' => ['secret'],
    'detect.snmpget_path' => ['path'], 'detect.ssh_hw' => ['bool'],
    'ssh.enabled' => ['bool'], 'ssh.exec_enabled' => ['bool'], 'ssh.ssh_path' => ['path'], 'ssh.keyscan_path' => ['path'], 'ssh.keygen_path' => ['path'],
    'ssh.key_file' => ['path'], 'ssh.known_hosts' => ['path'], 'ssh.strict_host_key' => ['enum', ['accept-new', 'yes']], 'ssh.default_user' => ['sshuser'],
    'ssh.timeout' => ['int', 5, 300], 'ssh.allow_free' => ['bool'], 'ssh.allowlist' => ['text', 8000],
    'ssh.terminal_enabled' => ['bool'], 'ssh.terminal_key_poweruser' => ['bool'], 'ssh.terminal_max_sessions' => ['int', 1, 20], 'ssh.terminal_idle' => ['int', 15, 3600],
    'ssh.terminal_max_minutes' => ['int', 5, 1440], 'ssh.terminal_record' => ['bool'], 'ssh.script_path' => ['path'], 'system.php_cli' => ['path'],
    'audit.retention_days' => ['int', 0, 3650],
    'auth.local.enabled' => ['bool'], 'auth.require_2fa_admin' => ['bool'],
    'auth.pam.enabled' => ['bool'], 'auth.pam.mechanism' => ['enum', ['helper', 'pamtester']], 'auth.pam.helper_path' => ['path'], 'auth.pam.pamtester_path' => ['path'],
    'auth.pam.service' => ['svc'], 'auth.pam.autoprovision' => ['bool'], 'auth.pam.default_role' => ['enum', ROLE_OPTIONS],
    'auth.pam_yubikey.enabled' => ['bool'], 'auth.pam_yubikey.service' => ['svc'], 'auth.pam_yubikey.u2f_server' => ['bool'],
    'auth.ldap.enabled' => ['bool'], 'auth.ldap.mode' => ['enum', ['ad', 'generic']], 'auth.ldap.uri' => ['str', 300], 'auth.ldap.starttls' => ['bool'],
    'auth.ldap.ca_file' => ['path'], 'auth.ldap.verify_cert' => ['bool'], 'auth.ldap.ad_domain' => ['str', 120], 'auth.ldap.ad_netbios' => ['str', 15],
    'auth.ldap.base_dn' => ['str', 300], 'auth.ldap.bind_dn' => ['str', 300], 'auth.ldap.bind_password' => ['secret'], 'auth.ldap.user_filter' => ['str', 500],
    'auth.ldap.nested_groups' => ['bool'], 'auth.ldap.allowed_group' => ['str', 300], 'auth.ldap.admin_group' => ['str', 300], 'auth.ldap.operator_group' => ['str', 300],
    'auth.ldap.autoprovision' => ['bool'], 'auth.ldap.default_role' => ['enum', ROLE_OPTIONS], 'auth.ldap.timeout' => ['int', 1, 30],
    'auth.webauthn.rp_id' => ['str', 190], 'auth.webauthn.rp_name' => ['str', 80],
    'auth.yubico_otp.enabled' => ['bool'], 'auth.yubico_otp.client_id' => ['str', 20], 'auth.yubico_otp.secret_key' => ['secret'], 'auth.yubico_otp.api_url' => ['url'],
    'system.admin_helper' => ['path'], 'system.icons_registry' => ['url'],
];

function sf(string $path, string $label, array $o = []): string
{
    [$type] = SETTINGS_FIELDS[$path];
    $name = 's[' . str_replace('.', '][', $path) . ']';
    $v = setting($path);
    $hint = $o['hint'] ?? null;
    $cls = $o['class'] ?? '';
    return match ($type) {
        'bool' => '<div class="field check-field' . ($cls ? ' ' . $cls : '') . '"><label class="check"><input type="hidden" name="' . h($name) . '" value="0"><input type="checkbox" name="' . h($name) . '" value="1"' . ($v ? ' checked' : '') . '><span>' . h($label) . '</span>' . ($hint ? '<small>' . $hint . '</small>' : '') . '</label></div>',
        'enum' => f_select($name, $label, $o['options'] ?? array_combine(SETTINGS_FIELDS[$path][1], SETTINGS_FIELDS[$path][1]), $v, ['hint' => $hint, 'class' => $cls]),
        'secret' => f_text($name, $label, '', ['type' => 'password', 'autocomplete' => 'new-password', 'placeholder' => (string)$v !== '' ? t('•••••• (gesetzt – leer lassen = unverändert)') : t('nicht gesetzt'), 'hint' => $hint, 'class' => $cls]),
        'text' => f_textarea($name, $label, $v, 8, ['mono' => true, 'hint' => $hint, 'class' => $cls]),
        'iplist' => f_text($name, $label, implode(', ', (array)$v), ['hint' => $hint, 'class' => $cls]),
        'minutes' => f_text($name, $label, (string)intdiv((int)$v, 60), ['type' => 'number', 'min' => 5, 'max' => 1440, 'hint' => $hint, 'class' => $cls]),
        default => f_text($name, $label, is_bool($v) ? (int)$v : $v, ['hint' => $hint, 'class' => $cls . (in_array($type, ['path', 'ports'], true) ? ' mono-input' : '')]),
    };
}

function role_options(): array
{
    $o = [];
    foreach (ROLE_OPTIONS as $r) {
        $o[$r] = role_label($r);
    }
    return $o;
}

function page_settings(): void
{
    page_start(t('Einstellungen'), 'settings', [[t('Einstellungen'), null]]);
    heading(t('Einstellungen'), th('gespeichert in config/settings.json · nur für Portal-Admins'), btn(t('System & Pakete'), url(['p' => 'system']), 'secondary', 'box'));
    echo '<form class="form-layout settings" method="post" action="' . h(url(['a' => 'settings_save'])) . '" autocomplete="off">' . csrf_field();

    echo '<section class="card"><h2 class="section-label">' . th('Allgemein') . '</h2><div class="form-grid cols-4">'
        . sf('app.title', t('Titel')) . sf('app.company', t('Firma / Standort')) . sf('app.base_url', t('Basis-URL'), ['hint' => th('leer = automatisch; für WebAuthn hinter Proxy setzen')])
        . sf('auth.session_idle', t('Abmeldung nach Inaktivität (Minuten)'))
        . sf('app.trusted_proxies', t('Vertrauenswürdige Proxys'), ['class' => 'span2', 'hint' => th('nur deren X-Forwarded-For wird für die Client-IP im Audit übernommen')])
        . sf('app.http_proxy', t('HTTP-Proxy für ausgehende Abrufe'), ['class' => 'span2', 'hint' => th('Yubico-Validierung und Icon-Import, z. B. http://proxy:3128')])
        . sf('app.debug', t('Debug-Modus'), ['class' => 'span2', 'hint' => th('Anfragen, PHP-Warnungen und Browser-Fehler protokollieren – ansehen unter „Diagnose & Debug“')])
        . '</div></section>';

    echo '<section class="card"><h2 class="section-label">' . th('Ping & Dienste') . '</h2><div class="form-grid cols-4">'
        . sf('tools.ping_path', t('ping-Programm'), ['hint' => th('leer = automatisch ({p})', ['p' => ping_bin() ?? t('nicht gefunden')])])
        . sf('tools.ping_count', t('Pakete je Ping')) . sf('tools.ping_timeout', t('Wartezeit je Antwort (s)')) . sf('tools.ping_parallel', t('„Alle pingen“ gleichzeitig'))
        . sf('tools.tcp_timeout', t('TCP-Wartezeit (s)')) . sf('tools.http_check', t('Dienste mit URL per HTTP(S) prüfen'), ['hint' => th('Status, Server, Zertifikatslaufzeit')])
        . sf('tools.scan_ports', t('Ports für „Dienste erkennen“'), ['class' => 'span4', 'hint' => th('Komma-getrennt; TCP')])
        . '</div></section>';

    echo '<section class="card"><h2 class="section-label">iperf3</h2><div class="form-grid cols-4">'
        . sf('tools.iperf_path', t('iperf3-Programm'), ['hint' => iperf_bin() ? '' : '<span class="txt-bad">' . th('nicht gefunden') . '</span>'])
        . sf('tools.iperf_port', t('Standard-Port'), ['hint' => th('je Eintrag und je Test änderbar')])
        . sf('tools.iperf_duration', t('Standard-Dauer (s)')) . sf('tools.iperf_max_duration', t('Höchstdauer (s)'))
        . sf('tools.iperf_udp_bandwidth', t('Standard-UDP-Bandbreite'), ['hint' => th('z. B. 100M')])
        . '</div></section>';

    echo '<section class="card" id="detectSection"><h2 class="section-label">' . th('MAC- und Hardware-Erkennung') . '</h2><div class="form-grid cols-4">'
        . sf('detect.auto', t('Beim Anlegen automatisch ermitteln'), ['hint' => th('MAC, Hersteller, Hardware-Kennung; sonst „not found“')])
        . sf('detect.nmblookup_path', 'nmblookup', ['hint' => th('MAC/Name von Windows-Geräten, auch über Router hinweg')])
        . sf('detect.ssh_hw', t('Hardware-Kennung per SSH'), ['hint' => th('machine-id / DMI-UUID; braucht SSH-Befehle')])
        . '<span></span>'
        . sf('detect.snmp_enabled', t('SNMP-Abfrage')) . sf('detect.snmp_community', t('SNMP-Community')) . sf('detect.snmpget_path', 'snmpget')
        . '</div></section>';

    $pub = ssh_public_key();
    echo '<section class="card" id="sshSection"><h2 class="section-label">SSH</h2><div class="form-grid cols-4">'
        . sf('ssh.enabled', t('Banner & Host-Key-Abfrage')) . sf('ssh.exec_enabled', t('Befehle über SSH ausführen'), ['hint' => th('mit dem Portal-Schlüssel, nur Power-User/Portal-Admins')])
        . sf('ssh.allow_free', t('Freie Befehle für Portal-Admins')) . sf('ssh.strict_host_key', t('Host-Key-Prüfung'), ['options' => ['accept-new' => t('neue Hosts annehmen, Änderungen ablehnen'), 'yes' => t('nur bekannte Hosts (known_hosts)')]])
        . sf('ssh.default_user', t('Standard-Benutzer')) . sf('ssh.timeout', t('Zeitlimit (s)')) . sf('ssh.key_file', t('Privater Schlüssel')) . sf('ssh.known_hosts', 'known_hosts')
        . sf('ssh.ssh_path', 'ssh') . sf('ssh.keyscan_path', 'ssh-keyscan') . sf('ssh.keygen_path', 'ssh-keygen') . '<span></span>'
        . sf('ssh.allowlist', t('Freigabeliste (ein Befehl je Zeile, exakter Vergleich)'), ['class' => 'span4'])
        . '</div><div class="field"><span>' . th('Öffentlicher Schlüssel des Portals (auf den Zielen in ~/.ssh/authorized_keys eintragen)') . '</span>'
        . ($pub ? '<div class="copy-row"><code class="mono cmd break">' . h($pub) . '</code>' . copy_btn($pub) . '</div>' : '<p class="dim">' . th('Noch kein Schlüssel vorhanden.') . '</p>') . '</div></section>';
    $tp = terminal_problems();
    echo '<section class="card" id="terminalSection"><h2 class="section-label">' . th('SSH-Terminal im Browser') . '</h2>'
        . ($tp !== [] && setting('ssh.terminal_enabled', true) ? '<p class="note warn">' . h(implode(' · ', $tp)) . '</p>' : '')
        . '<div class="form-grid cols-4">'
        . sf('ssh.terminal_enabled', t('SSH-Terminal einschalten'), ['hint' => th('für Power-User und Portal-Admins; die Verbindung baut der Server auf')])
        . sf('ssh.terminal_key_poweruser', t('Portal-Schlüssel auch für Power-User'), ['hint' => th('sonst nur Portal-Admins; alle anderen melden sich mit Passwort an')])
        . sf('ssh.terminal_record', t('Bildschirmausgabe aufzeichnen'), ['hint' => th('database/terminal/ – Eingaben (Passwörter) werden nie aufgezeichnet')])
        . '<span></span>'
        . sf('ssh.terminal_max_sessions', t('Sitzungen je Benutzer')) . sf('ssh.terminal_idle', t('Ende ohne Browser-Verbindung (s)'))
        . sf('ssh.terminal_max_minutes', t('Höchstdauer (Minuten)')) . '<span></span>'
        . sf('ssh.script_path', t('script (Pseudo-Terminal)'), ['class' => 'span2']) . sf('system.php_cli', t('PHP-Kommandozeile'), ['class' => 'span2', 'hint' => th('leer = automatisch ({p})', ['p' => terminal_php_cli() ?? t('nicht gefunden')])])
        . '</div></section>';

    echo '<section class="card" id="authSection"><h2 class="section-label">' . th('Anmeldung: lokal & PAM') . '</h2><div class="form-grid cols-4">'
        . sf('auth.local.enabled', t('Lokale Konten (users.sql)'), ['hint' => th('„Administrator“ bleibt immer lokal möglich')])
        . sf('auth.require_2fa_admin', t('YubiKey Pflicht für Portal-Admins'))
        . sf('auth.pam.enabled', t('PAM (Systemkonten)')) . sf('auth.pam_yubikey.enabled', t('PAM + YubiKey'), ['hint' => th('Dienst netportal-yubikey (pam_yubico / pam_u2f)')])
        . sf('auth.pam.mechanism', t('PAM-Mechanismus'), ['options' => ['helper' => t('eigener Helfer (empfohlen)'), 'pamtester' => t('pamtester (ohne eigenen C-Helfer)')],
            'hint' => th('pamtester kann lokale Unix-Passwörter nur prüfen, wenn er als root läuft; für sssd/AD-Konten genügt er.')])
        . sf('auth.pam.helper_path', t('Helfer')) . sf('auth.pam.pamtester_path', 'pamtester') . sf('auth.pam_yubikey.u2f_server', t('pam_u2f mit Key am Server'), ['hint' => th('kein OTP-Feld nötig (Kiosk)')])
        . sf('auth.pam.service', t('pamtester-Dienst')) . sf('auth.pam_yubikey.service', t('pamtester-Dienst (YubiKey)'))
        . sf('auth.pam.autoprovision', t('PAM-Benutzer automatisch anlegen')) . sf('auth.pam.default_role', t('Rolle neuer PAM-Benutzer'), ['options' => role_options()])
        . '</div><p class="hint"><a href="' . h(url(['p' => 'system'])) . '">' . th('PAM-Tester und Paketstatus unter „System & Pakete“') . '</a></p></section>';

    echo '<section class="card" id="ldapSection"><h2 class="section-label">' . th('LDAP / Windows Active Directory') . '</h2>'
        . (ldap_available() ? '' : '<p class="note warn">' . th('PHP-Erweiterung „ldap“ fehlt – unter „System & Pakete“ installieren.') . '</p>')
        . '<div class="form-grid cols-4">'
        . sf('auth.ldap.enabled', t('LDAP-Anmeldung')) . sf('auth.ldap.mode', t('Verzeichnis'), ['options' => ['ad' => t('Windows Active Directory (DC)'), 'generic' => 'OpenLDAP / FreeIPA / Samba']])
        . sf('auth.ldap.uri', t('Server-URI'), ['class' => 'span2', 'hint' => th('ldaps://dc01.firma.local – mehrere durch Leerzeichen (Failover)')])
        . sf('auth.ldap.ad_domain', t('AD-Domäne (UPN-Suffix)'), ['hint' => th('firma.local → max@firma.local')]) . sf('auth.ldap.ad_netbios', t('NetBIOS-Domäne'), ['hint' => th('FIRMA → FIRMA\max')])
        . sf('auth.ldap.base_dn', t('Basis-DN'), ['class' => 'span2', 'hint' => 'DC=firma,DC=local'])
        . sf('auth.ldap.bind_dn', t('Dienstkonto'), ['class' => 'span2', 'hint' => th('svc-netportal@firma.local · leer = Bind direkt als Benutzer (nur AD)')])
        . sf('auth.ldap.bind_password', t('Passwort Dienstkonto'), ['class' => 'span2'])
        . sf('auth.ldap.starttls', t('StartTLS (bei ldap://)')) . sf('auth.ldap.verify_cert', t('Zertifikat prüfen')) . sf('auth.ldap.ca_file', t('CA-Datei'), ['class' => 'span2', 'hint' => th('z. B. /etc/ssl/certs/firma-root-ca.pem')])
        . sf('auth.ldap.allowed_group', t('Zugangsgruppe'), ['class' => 'span2', 'hint' => th('nur Mitglieder dürfen sich anmelden (DN oder Name, leer = alle)')])
        . sf('auth.ldap.nested_groups', t('Verschachtelte Gruppen (AD)')) . sf('auth.ldap.timeout', t('Zeitlimit (s)'))
        . sf('auth.ldap.admin_group', t('Gruppe → Portal-Admin'), ['class' => 'span2']) . sf('auth.ldap.operator_group', t('Gruppe → Power-User'), ['class' => 'span2'])
        . sf('auth.ldap.autoprovision', t('AD-Benutzer automatisch anlegen')) . sf('auth.ldap.default_role', t('Rolle ohne Gruppe'), ['options' => role_options()])
        . sf('auth.ldap.user_filter', t('Eigener Suchfilter'), ['class' => 'span2', 'hint' => th('leer = Standard; %s = Kurzname, %u = UPN')])
        . '</div><p class="hint"><a href="' . h(url(['p' => 'system'])) . '">' . th('LDAP-/AD-Tester unter „System & Pakete“') . '</a> · ' . th('Der Test verwendet die gespeicherten Einstellungen – vorher speichern.') . '</p></section>';

    echo '<section class="card" id="yubikeySection"><h2 class="section-label">' . th('YubiKey (zweiter Faktor)') . '</h2><div class="form-grid cols-4">'
        . sf('auth.webauthn.rp_id', t('WebAuthn rpId'), ['hint' => th('leer = Hostname; nachträglich ändern macht registrierte Keys ungültig')])
        . sf('auth.webauthn.rp_name', t('WebAuthn Anzeigename'))
        . sf('auth.yubico_otp.enabled', t('Yubico-OTP erlauben')) . '<span></span>'
        . sf('auth.yubico_otp.client_id', t('Yubico Client-ID'), ['hint' => '<a href="https://upgrade.yubico.com/getapikey/" target="_blank" rel="noopener noreferrer">' . th('API-Zugang bei Yubico holen') . '</a>'])
        . sf('auth.yubico_otp.secret_key', t('Yubico Secret-Key'), ['hint' => th('Pflicht – damit wird die Antwort von Yubico geprüft')])
        . sf('auth.yubico_otp.api_url', t('Validierungs-URL'), ['class' => 'span2'])
        . '</div></section>';

    $aCount = (int)sys_val('SELECT COUNT(*) FROM audit');
    $aOldest = sys_val('SELECT ts FROM audit ORDER BY id LIMIT 1');
    $aPrune = meta_get('audit_prune_at');
    echo '<section class="card" id="auditSection"><h2 class="section-label">' . th('Audit-Log') . '</h2><div class="form-grid cols-4">'
        . sf('audit.retention_days', t('Aufbewahrung (Tage)'), ['hint' => th('ältere Einträge und SSH-Aufzeichnungen werden täglich automatisch gelöscht · 0 = nie löschen')])
        . '<div class="field span3"><span>' . th('Aktueller Stand') . '</span><p class="hint">'
        . th('{n} Einträge', ['n' => fmt_num($aCount)]) . ($aOldest ? ' · ' . th('ältester vom {d}', ['d' => fmt_datetime(substr((string)$aOldest, 0, 19))]) : '')
        . ' · ' . th('zuletzt bereinigt: {d}', ['d' => $aPrune ? fmt_datetime($aPrune) : t('noch nie')]) . '</p></div>'
        . '</div></section>';

    echo '<section class="card"><h2 class="section-label">' . th('System') . '</h2><div class="form-grid cols-4">'
        . sf('system.admin_helper', t('root-Helfer'), ['class' => 'span2']) . sf('system.icons_registry', t('Icon-Quelle (npm-Registry)'), ['class' => 'span2'])
        . '</div></section>';

    echo '<div class="form-actions sticky"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Einstellungen speichern') . '</button></div></form>';

    echo '<section class="card" id="sshKeySection"><h2 class="section-label">' . th('SSH-Schlüssel des Portals') . '</h2><p class="hint">' . th('Erzeugt ein neues Ed25519-Paar unter {p}. Ein vorhandenes Paar wird umbenannt (*.alt-Datum).', ['p' => (string)setting('ssh.key_file')]) . '</p>'
        . post_button($pub ? t('Neues Schlüsselpaar erzeugen') : t('Schlüsselpaar erzeugen'), ['a' => 'ssh_keygen'], $pub ? 'danger' : 'primary', $pub ? t('Das bisherige Schlüsselpaar wird ersetzt – auf allen Zielen muss der neue Schlüssel eingetragen werden. Fortfahren?') : '', 'key') . '</section>';

    // Icons
    $brands = brand_icons();
    $custom = custom_icons();
    echo '<section class="card" id="icons"><h2 class="section-label">' . th('Icons') . '</h2>'
        . '<p class="hint">' . th('Eigene Symbole (Server, Netzwerk, Steuerung …) sind mitgeliefert. Logos von Betriebssystemen und Software (Ubuntu, Linux Mint, Debian, OPNsense, Caddy, Proxmox …) stammen aus „Simple Icons“ unter der freien Lizenz CC0 1.0 und werden hier aus dem offiziellen npm-Paket geladen (Prüfsumme wird kontrolliert). Die Marken gehören ihren Inhabern; die Logos dienen nur zur Kennzeichnung.') . '</p>';
    echo '<form method="post" enctype="multipart/form-data" action="' . h(url(['a' => 'icons_import'])) . '">' . csrf_field()
        . '<div class="icon-catalog"><label class="check"><input type="checkbox" id="brandAll"><span>' . th('alle auswählen') . '</span></label>';
    foreach (BRAND_CATALOG as $slug => $name) {
        echo '<label class="check brand-opt' . (isset($brands[$slug]) ? ' have' : '') . '"><input type="checkbox" name="slugs[]" value="' . h($slug) . '"' . (isset($brands[$slug]) ? '' : ' checked') . '><span>' . h($name) . (isset($brands[$slug]) ? ' ✓' : '') . '</span></label>';
    }
    echo '</div><div class="form-grid cols-4">'
        . '<label class="field span2"><span>' . th('Offline: simple-icons-Paket (.tgz) hochladen') . '</span><input type="file" name="tgz" accept=".tgz,.tar.gz,application/gzip"></label>'
        . '<div class="field span2"><span>&nbsp;</span><button class="pill-btn primary" type="submit">' . icon('download', 15) . th('Ausgewählte Logos laden') . '</button></div></div>'
        . '<p class="hint">' . th('{b} Logos geladen · {s} fest eingebaut · {c} eigene Icons', ['b' => count(brand_icons_dynamic()), 's' => count(brand_icons_static()), 'c' => count($custom)]) . '</p></form>';
    if ($custom !== []) {
        echo '<div class="custom-icons">';
        foreach ($custom as $f) {
            echo '<div class="custom-icon-item"><img src="' . h(url(['a' => 'icon', 'f' => $f])) . '" width="28" height="28" alt=""><span class="mono small">' . h(substr($f, 0, 10)) . '…</span>'
                . post_button('', ['a' => 'icon_delete'], 'icon-only', t('Icon löschen? Einträge, die es nutzen, zeigen danach das Typ-Symbol.'), 'trash', ['f' => $f], t('Löschen')) . '</div>';
        }
        echo '</div>';
    }
    echo '</section>';
    page_end();
}

function action_settings_save(): void
{
    $in = input()['s'] ?? [];
    $in = is_array($in) ? $in : [];
    $all = settings_all();
    $errors = [];
    $changes = [];
    foreach (SETTINGS_FIELDS as $path => $def) {
        $type = $def[0];
        $cur = $in;
        $present = true;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                $present = false;
                break;
            }
            $cur = $cur[$k];
        }
        if (!$present || is_array($cur)) {
            continue;
        }
        $raw = trim(str_replace("\0", '', (string)$cur));
        $old = setting($path);
        switch ($type) {
            case 'bool':
                $val = $raw === '1';
                break;
            case 'int':
            case 'minutes':
                if ($raw === '' || !preg_match('/^\d+$/', $raw) || (int)$raw < $def[1] || (int)$raw > $def[2]) {
                    $errors[] = t('{p}: Zahl zwischen {a} und {b} erwartet', ['p' => $path, 'a' => $def[1], 'b' => $def[2]]);
                    continue 2;
                }
                $val = $type === 'minutes' ? (int)$raw * 60 : (int)$raw;
                break;
            case 'float':
                $f = (float)str_replace(',', '.', $raw);
                if ($f < $def[1] || $f > $def[2]) {
                    $errors[] = t('{p}: Wert zwischen {a} und {b} erwartet', ['p' => $path, 'a' => $def[1], 'b' => $def[2]]);
                    continue 2;
                }
                $val = $f;
                break;
            case 'enum':
                if (!in_array($raw, $def[1], true)) {
                    continue 2;
                }
                $val = $raw;
                break;
            case 'secret':
                if ($raw === '') {
                    continue 2;
                }
                $val = $raw;
                break;
            case 'path':
                if ($raw !== '' && !preg_match('#^[A-Za-z0-9_./-]+$#', $raw)) {
                    $errors[] = t('{p}: ungültiger Pfad', ['p' => $path]);
                    continue 2;
                }
                $val = $raw;
                break;
            case 'url':
                if ($raw !== '' && !preg_match('#^https?://[^\s]+$#', $raw)) {
                    $errors[] = t('{p}: URL muss mit http:// oder https:// beginnen', ['p' => $path]);
                    continue 2;
                }
                $val = $raw;
                break;
            case 'bw':
                if (!preg_match('/^\d{1,5}(\.\d{1,2})?[KMGkmg]?$/', $raw)) {
                    $errors[] = t('{p}: z. B. 100M', ['p' => $path]);
                    continue 2;
                }
                $val = strtoupper($raw);
                break;
            case 'iplist':
                $list = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
                foreach ($list as $ip) {
                    if (!ip_valid($ip)) {
                        $errors[] = t('{p}: „{v}“ ist keine IP', ['p' => $path, 'v' => $ip]);
                        continue 3;
                    }
                }
                $val = $list;
                break;
            case 'ports':
                $ports = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', $raw) ?: []), static fn($p) => $p > 0 && $p < 65536)));
                $val = implode(',', array_slice($ports, 0, 300));
                break;
            case 'svc':
                if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $raw)) {
                    $errors[] = t('{p}: ungültiger PAM-Dienstname', ['p' => $path]);
                    continue 2;
                }
                $val = $raw;
                break;
            case 'sshuser':
                if (!ssh_valid_user($raw)) {
                    $errors[] = t('{p}: ungültiger Benutzername', ['p' => $path]);
                    continue 2;
                }
                $val = $raw;
                break;
            case 'text':
                $val = mb_substr(str_replace("\r", '', (string)$cur), 0, $def[1]);
                break;
            default:
                $val = mb_substr($raw, 0, $def[1] ?? 255);
        }
        if ($val !== $old) {
            $changes[$path] = $type === 'secret' ? ['***', 'neu gesetzt'] : [$old, $val];
            $ref = &$all;
            foreach (explode('.', $path) as $k) {
                $ref = &$ref[$k];
            }
            $ref = $val;
            unset($ref);
        }
    }
    // Yubico-OTP nur mit vollständigem, signiertem Zugang einschalten
    $yo = $all['auth']['yubico_otp'] ?? [];
    if (!empty($yo['enabled'])) {
        if (!preg_match('/^\d{1,10}$/', trim((string)($yo['client_id'] ?? '')))) {
            $errors[] = t('Yubico-OTP: Client-ID fehlt oder ist keine Zahl.');
        }
        $sk = trim((string)($yo['secret_key'] ?? ''));
        if ($sk === '' || base64_decode($sk, true) === false || strlen((string)base64_decode($sk, true)) < 16) {
            $errors[] = t('Yubico-OTP: Secret-Key fehlt oder ist ungültig (Base64 von Yubico).');
        }
    }
    if ($errors !== []) {
        foreach ($errors as $e) {
            flash($e, 'error');
        }
        audit('settings_update', ['fehler' => $errors], null, 'fail');
        redirect(url(['p' => 'settings']));
    }
    if ($changes === []) {
        flash(t('Keine Änderungen.'), 'info');
        redirect(url(['p' => 'settings']));
    }
    if (!settings_save($all)) {
        flash(t('config/settings.json konnte nicht geschrieben werden.'), 'error');
        redirect(url(['p' => 'settings']));
    }
    audit('settings_update', ['aenderungen' => $changes]);
    flash(t('{n} Einstellung(en) gespeichert.', ['n' => count($changes)]));
    redirect(url(['p' => 'settings']));
}

function action_ssh_keygen(): void
{
    $r = ssh_generate_key();
    $pub = ssh_public_key();
    audit('settings_update', ['ssh_schluessel' => $r['ok'] ? 'neu erzeugt' : 'fehlgeschlagen', 'public_key_sha256' => $pub ? hash('sha256', $pub) : null, 'ausgabe' => $r['output']], null, $r['ok'] ? 'ok' : 'fail');
    flash($r['ok'] ? t('Neues SSH-Schlüsselpaar erzeugt.') : t('Schlüssel konnte nicht erzeugt werden: {e}', ['e' => $r['output']]), $r['ok'] ? 'ok' : 'error');
    redirect(url(['p' => 'settings']));
}

function action_icons_import(): void
{
    $slugs = input()['slugs'] ?? [];
    $slugs = is_array($slugs) ? array_values(array_filter($slugs, 'is_string')) : [];
    if ($slugs === []) {
        flash(t('Bitte Logos auswählen.'), 'error');
        redirect(url(['p' => 'settings']) . '#icons');
    }
    @set_time_limit(300);
    try {
        if (!empty($_FILES['tgz']) && ($_FILES['tgz']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES['tgz']['tmp_name'])) {
            $r = brand_import_tgz((string)file_get_contents((string)$_FILES['tgz']['tmp_name']), $slugs, 'Upload ' . basename((string)$_FILES['tgz']['name']));
        } else {
            $r = brand_import_online($slugs);
        }
        audit('icon_import', ['quelle' => 'simple-icons (CC0)', 'version' => $r['version'], 'importiert' => $r['imported'], 'nicht_im_paket' => $r['missing']]);
        flash(t('{n} Logos geladen (simple-icons {v}).', ['n' => count($r['imported']), 'v' => $r['version']]));
        if ($r['missing'] !== []) {
            flash(t('Nicht im Paket enthalten: {l}', ['l' => implode(', ', $r['missing'])]), 'warn');
        }
    } catch (Throwable $e) {
        audit('icon_import', ['fehler' => $e->getMessage()], null, 'fail');
        flash($e->getMessage(), 'error');
    }
    redirect(url(['p' => 'settings']) . '#icons');
}

function action_icon_delete(): void
{
    $f = in_str('f', 60);
    if (preg_match('/^[0-9a-f]{40}\.(png|jpg|webp|svg)$/', $f) && is_file(ICON_DIR . '/custom/' . $f)) {
        @unlink(ICON_DIR . '/custom/' . $f);
        audit('icon_delete', ['datei' => $f]);
        flash(t('Icon gelöscht.'));
    }
    redirect(url(['p' => 'settings']) . '#icons');
}
