<?php
/**
 * System & Pakete (nur Portal-Admins): was fehlt, was geht (PAM, PAM+YubiKey,
 * YubiKey, LDAP/AD, Werkzeuge), fehlende PHP-Module und Pakete installieren,
 * PAM-Tester, LDAP-Tester, YubiKey-OTP-Tester.
 */

declare(strict_types=1);

function check_icon(?bool $ok): string
{
    return $ok === null ? '<span class="badge dim">–</span>' : ($ok ? icon('check', 14, 'txt-ok') : icon('x', 14, 'txt-bad'));
}

function page_system(): void
{
    $os = os_info();
    $helper = admin_helper_status();
    $job = sys_job_status();
    $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
    page_start(t('System & Pakete'), 'system', [[t('System & Pakete'), null]]);
    heading(t('System & Pakete'), th('Was dem Server noch fehlt – und was schon funktioniert.'));

    echo '<div class="grid-2"><section class="card"><h2 class="section-label">' . th('Server') . '</h2>' . kv_list([
        t('Betriebssystem') => h((string)$os['PRETTY_NAME']) . ($os['debian_like'] ? ' <span class="badge ok-soft">Debian/Ubuntu</span>' : ' <span class="badge warn">' . th('kein Debian/Ubuntu – Installation nur von Hand') . '</span>'),
        'PHP' => h(PHP_VERSION . ' (' . PHP_SAPI . ')') . ' · ' . th('Benutzer') . ' <span class="mono">' . h($user) . '</span>',
        t('Paketnamen für PHP') => '<span class="mono">php' . h(php_pkg_version()) . '-…</span>',
        t('Portal-Adresse') => '<span class="mono">' . h(app_base_url()) . '</span>',
    ]) . '</section>';
    echo '<section class="card"><h2 class="section-label">' . th('Installations-Helfer') . '</h2>' . kv_list([
        t('Helfer') => '<span class="mono">' . h(admin_helper()) . '</span> ' . check_icon($helper['ok']),
        t('Zustand') => h($helper['detail']),
    ]);
    if (!$helper['ok']) {
        echo '<p class="hint">' . th('Einmalig auf dem Server als root einrichten (danach geht alles hier per Klick):') . '</p>'
            . '<div class="copy-row"><code class="mono cmd">sudo php ' . h(APP_ROOT) . '/system/install.php ' . h($user) . '</code>' . copy_btn('sudo php ' . APP_ROOT . '/system/install.php ' . $user) . '</div>'
            . '<p class="hint">' . th('Der Helfer (PHP) darf nur eine feste Liste von Paketen installieren und den PAM-Helfer bauen – sonst nichts.') . '</p>';
    }
    echo '</section></div>';

    // Funktionen
    echo '<h2 class="section-label spaced">' . th('Funktionen') . '</h2><div class="feature-grid">';
    foreach (feature_checks() as $feature => $checks) {
        // Schalter aus → Funktion wird nicht genutzt: neutral statt rot
        $off = false;
        foreach ($checks as $c) {
            if (!empty($c[4]) && $c[1] === false) {
                $off = true;
            }
        }
        $known = array_filter(array_column($checks, 1), static fn($v) => $v !== null);
        $all = $known !== [] && !in_array(false, $known, true);
        $state = $off ? 'off' : ($all ? 'ok' : 'warn');
        echo '<section class="card feature-card ' . $state . '"><div class="feature-head">' . ($off ? '<span class="feature-off">' . icon('power', 14) . '</span>' : check_icon($all))
            . '<strong>' . h($feature) . '</strong>' . ($off ? '<span class="badge dim">' . th('nicht eingeschaltet') . '</span>' : '') . '</div><ul class="checks">';
        foreach ($checks as $c) {
            [$label, $ok, $hint] = $c;
            $href = $c[3] ?? null;
            if ($off && $ok === null) {
                // nicht eingeschaltet: Erreichbarkeit o. Ä. wird gar nicht erst geprüft
                echo '<li class="off">' . icon('minus', 13) . '<span>' . h($label) . '</span><em></em></li>';
                continue;
            }
            $cls = $ok === null ? 'warn' : ($ok ? 'ok' : ($off ? 'off' : 'bad'));
            $ico = $ok === null ? icon('clock', 13) : icon($ok ? 'check' : ($off ? 'minus' : 'x'), 13);
            $hintHtml = '';
            if (!$ok && $hint !== '') {
                $ext = $href !== null && str_starts_with($href, 'https://');
                $hintHtml = $href !== null ? '<a href="' . h($href) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . h($hint) . ($ext ? ' ' . icon('external', 11) : '') . '</a>' : h($hint);
            }
            echo '<li class="' . $cls . '">' . $ico . '<span>' . h($label) . '</span><em>' . $hintHtml . '</em></li>';
        }
        echo '</ul></section>';
    }
    echo '</div>';

    // Pakete
    $mods = php_module_list();
    $pkgs = sys_package_list();
    echo '<h2 class="section-label spaced">' . th('PHP-Module & Systempakete') . '</h2>';
    echo '<form id="sysInstall" data-url="' . h(url(['a' => 'sys_install'])) . '" data-status="' . h(url(['a' => 'sys_status'])) . '"><div class="table-wrap"><table class="data-table">'
        . '<thead><tr><th class="col-dot"></th><th>' . th('Komponente') . '</th><th>' . th('Wofür') . '</th><th>' . th('Paket') . '</th><th>' . th('Zustand') . '</th></tr></thead><tbody>';
    foreach ($mods as $ext => [$purpose, $suffix, $required]) {
        $ok = extension_loaded($ext);
        $pkg = $suffix !== null ? 'php' . php_pkg_version() . '-' . $suffix : null;
        echo '<tr><td class="col-dot">' . ($ok || $pkg === null ? '' : '<input type="checkbox" name="pkg" value="' . h($pkg) . '" aria-label="' . h($pkg) . '"' . ($required ? ' checked' : '') . '>') . '</td>'
            . '<td><span class="mono">php-' . h($ext) . '</span>' . ($required ? ' <span class="badge warn">' . th('Pflicht') . '</span>' : '') . '</td><td>' . h($purpose) . '</td>'
            . '<td class="mono small">' . h($pkg ?? t('in PHP enthalten')) . '</td><td>' . ($ok ? '<span class="badge ok-soft">' . th('geladen') . '</span>' : '<span class="badge bad">' . th('fehlt') . '</span>') . '</td></tr>';
    }
    foreach ($pkgs as $pkg => [$purpose, $files]) {
        $ok = pkg_installed($pkg, $files);
        echo '<tr><td class="col-dot">' . ($ok ? '' : '<input type="checkbox" name="pkg" value="' . h($pkg) . '" aria-label="' . h($pkg) . '">') . '</td>'
            . '<td><span class="mono">' . h($pkg) . '</span></td><td>' . h($purpose) . '</td><td class="mono small">apt</td>'
            . '<td>' . ($ok ? '<span class="badge ok-soft">' . th('installiert') . '</span>' : '<span class="badge bad">' . th('fehlt') . '</span>') . '</td></tr>';
    }
    echo '</tbody></table></div><div class="form-actions">'
        . '<button type="submit" class="pill-btn primary"' . ($helper['ok'] && $os['debian_like'] ? '' : ' disabled') . '>' . icon('download', 15) . th('Ausgewählte installieren') . '</button>'
        . '<button type="button" class="pill-btn secondary" id="pamHelperInstall" data-url="' . h(url(['a' => 'sys_pam_helper'])) . '"' . ($helper['ok'] ? '' : ' disabled') . '>' . icon('key', 15) . th('PAM-Helfer installieren') . '</button>'
        . '<span class="hint" id="sysManual">' . th('Ohne Helfer von Hand:') . ' <span class="mono">sudo apt install …</span></span></div></form>';
    echo '<pre class="console" id="sysLog"' . ($job['log'] === '' ? ' hidden' : '') . ' data-running="' . ($job['running'] ? '1' : '0') . '">' . h($job['log']) . '</pre>';

    // Tester
    echo '<h2 class="section-label spaced">' . th('Tester') . '</h2><div class="grid-2">';
    echo '<section class="card"><h2 class="section-label">' . th('PAM-Tester') . '</h2>'
        . '<p class="hint">' . th('Prüft Benutzer und Passwort gegen den PAM-Dienst des Portals – ohne Anmeldung. Das Passwort wird nicht protokolliert.') . '</p>'
        . '<form id="pamTest" data-url="' . h(url(['a' => 'pam_test'])) . '" class="form-grid" autocomplete="off">'
        . f_select('service', t('PAM-Dienst'), ['std' => 'netportal (' . t('Passwort') . ')', 'yubikey' => 'netportal-yubikey (' . t('Passwort + YubiKey') . ')'], 'std')
        . f_text('user', t('Benutzername'), '', ['required' => true, 'maxlength' => 128])
        . f_text('password', t('Passwort'), '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true])
        . f_text('otp', t('YubiKey-OTP (nur bei netportal-yubikey)'), '', ['type' => 'password', 'autocomplete' => 'one-time-code', 'maxlength' => 128])
        . '<div class="span2"><button class="pill-btn primary" type="submit">' . th('PAM testen') . '</button></div></form><pre class="console" id="pamTestOut" hidden></pre>'
        . '<ul class="checks small-gap">';
    foreach (pam_diagnose() as $r) {
        echo '<li class="' . ($r['ok'] ? 'ok' : 'bad') . '">' . icon($r['ok'] ? 'check' : 'x', 13) . '<span>' . h($r['label']) . '</span><em>' . h($r['detail']) . '</em></li>';
    }
    echo '</ul></section>';
    echo '<section class="card"><h2 class="section-label">' . th('LDAP-/AD-Tester') . '</h2>'
        . '<p class="hint">' . th('Testet Verbindung, Dienstkonto, Benutzersuche, Kontostatus, Gruppen und optional das Passwort – mit den gespeicherten Einstellungen.') . '</p>'
        . (extension_loaded('ldap') ? '' : '<p class="note warn">' . th('PHP-Erweiterung „ldap“ fehlt – oben auswählen und installieren.') . '</p>')
        . '<form id="ldapTestForm" data-url="' . h(url(['a' => 'ldap_test'])) . '" class="form-grid" autocomplete="off">'
        . f_text('user', t('Testbenutzer'), '', ['maxlength' => 128, 'placeholder' => 'max / max@firma.local'])
        . f_text('password', t('Passwort (optional)'), '', ['type' => 'password', 'autocomplete' => 'new-password'])
        . '<div class="span2"><button class="pill-btn primary" type="submit">' . th('LDAP testen') . '</button> ' . btn(t('LDAP-Einstellungen'), url(['p' => 'settings']) . '#ldapSection', 'ghost') . '</div></form>'
        . '<pre class="console" id="ldapTestOut" hidden></pre>';
    echo '<h2 class="section-label spaced">' . th('YubiKey-OTP-Tester') . '</h2>'
        . '<form id="otpTest" data-url="' . h(url(['a' => 'otp_test'])) . '" class="form-grid" autocomplete="off">'
        . '<label class="field span2"><span>' . th('OTP (ins Feld klicken, Key berühren)') . '</span><input type="password" name="otp" maxlength="64" autocomplete="one-time-code" required></label>'
        . '<div class="span2"><button class="pill-btn secondary" type="submit">' . th('OTP prüfen') . '</button></div></form><pre class="console" id="otpTestOut" hidden></pre></section>';
    echo '</div>';
    page_end();
}

function action_sys_install(): void
{
    $want = input()['pkgs'] ?? [];
    $allow = sys_install_allowlist();
    $pkgs = array_values(array_unique(array_filter(is_array($want) ? $want : [], static fn($p) => is_string($p) && in_array($p, $allow, true))));
    if ($pkgs === []) {
        json_out(['error' => t('Bitte Pakete auswählen')], 400);
    }
    $r = sys_job_start(['install', ...$pkgs]);
    audit('sys_install', ['pakete' => $pkgs, 'gestartet' => $r['ok'], 'fehler' => $r['error'] ?? null], null, $r['ok'] ? 'ok' : 'fail');
    json_out($r, $r['ok'] ? 200 : 400);
}

function action_sys_pam_helper(): void
{
    $r = sys_job_start(['pam-helper']);
    audit('sys_pam_helper', ['gestartet' => $r['ok'], 'fehler' => $r['error'] ?? null], null, $r['ok'] ? 'ok' : 'fail');
    json_out($r, $r['ok'] ? 200 : 400);
}

function action_sys_status(): void
{
    $s = sys_job_status();
    json_out(['running' => $s['running'], 'rc' => $s['rc'], 'log' => $s['log']]);
}

function action_pam_test(): void
{
    $user = in_str('user', 128);
    if (!auth_valid_username($user)) {
        json_out(['error' => t('Benutzername ungültig.')], 400);
    }
    session_write_close();
    $r = pam_test(in_str('service', 10), $user, (string)(input()['password'] ?? ''), in_str('otp', 128));
    audit('pam_test', ['dienst' => in_str('service', 10) === 'yubikey' ? 'netportal-yubikey' : 'netportal', 'benutzer' => $user, 'code' => $r['code'], 'dauer_ms' => $r['ms']], null, $r['ok'] ? 'ok' : 'warn');
    json_out($r);
}

function action_ldap_test(): void
{
    $user = in_str('user', 128);
    try {
        $log = ldap_test((array)setting('auth.ldap'), $user, (string)(input()['password'] ?? ''));
        audit('ldap_test', ['testbenutzer' => $user ?: null, 'ergebnis' => $log]);
        json_out(['ok' => true, 'log' => $log]);
    } catch (Throwable $e) {
        audit('ldap_test', ['testbenutzer' => $user ?: null, 'fehler' => $e->getMessage()], null, 'fail');
        json_out(['ok' => false, 'log' => ['✗ ' . $e->getMessage()]]);
    }
}

function action_otp_test(): void
{
    session_write_close();
    $otp = strtolower(in_str('otp', 64));
    $r = yubico_otp_verify($otp);
    $owner = null;
    foreach (users_store()['yubico_otp_keys'] as $k) {
        if (isset($r['public_id']) && $k['public_id'] === $r['public_id']) {
            $owner = user_get((int)$k['user_id'])['username'] ?? null;
        }
    }
    audit('pam_test', ['art' => 'yubico_otp', 'key_id' => $r['public_id'] ?? null, 'ok' => $r['ok']], null, $r['ok'] ? 'ok' : 'warn');
    json_out(['ok' => $r['ok'], 'text' => $r['ok'] ? t('OTP gültig · Key-ID {k}', ['k' => $r['public_id']]) . ($owner ? ' · ' . t('registriert für {u}', ['u' => $owner]) : ' · ' . t('keinem Benutzer zugeordnet')) : $r['error']]);
}
