<?php
/**
 * Network Portal – Verwaltung von LANs und VLANs (wie eine Datenbank), mit
 * Ping, Dienst-Übersicht, iperf3 und SSH je Eintrag. Reines PHP.
 *
 * Einziger Einstiegspunkt. Seiten über ?p=…, Aktionen über ?a=… (ändernde
 * Aktionen nur per POST mit CSRF-Token).
 *
 * Daten:   database/net-<name>.sql   – je Netzwerk eine eigene SQL-Datei
 *          database/users.sql        – Benutzer des Portals + YubiKeys
 *          database/_system.sqlite   – Audit-Log, Siegel, Schlüsselteil 1
 *          config/settings.json      – Einstellungen
 *          config/integrity.key      – Schlüsselteil 2
 *          icons/                    – geladene Logos (CC0) und eigene Icons
 *
 * @copyright 2026 Lukas Geipel
 * @license   MIT (siehe LICENSE)
 */

declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('APP_VERSION', '2026.10.18');
define('SETTINGS_FILE', APP_ROOT . '/config/settings.json');
define('KEY_FILE', APP_ROOT . '/config/integrity.key');
define('DB_DIR', APP_ROOT . '/database');
define('SYS_DB_FILE', DB_DIR . '/_system.sqlite');
define('CACHE_DIR', APP_ROOT . '/.cache');
define('MANIFEST_FILE', APP_ROOT . '/manifest.sha256');

require APP_ROOT . '/includes/compat.php';

error_reporting(E_ALL);
compat_call('ini_set', 'display_errors', '0');
compat_call('ini_set', 'log_errors', '1');
mb_internal_encoding('UTF-8');
compat_set_timezone();
compat_require_environment();

// Nur der Ordner selbst und index.php sind gültige Pfade
(static function (): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    $path = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/') . '/';
    if ($path === $dir || $path === $script || $path === $dir . 'index.php') {
        return;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
})();

require APP_ROOT . '/includes/bootstrap.php';
debug_boot();

// Tägliche Wartung (Audit-Aufbewahrung) nach der Antwort – höchstens einmal pro Tag
register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    audit_maintenance();
});

try {
    sys();
    integrity_master_key();
    users_store();
} catch (IntegrityKeyMissing $e) {
    send_security_headers();
    render_error_page(t('Integritätsschlüssel fehlt'), $e->getMessage() . "\n\n"
        . t('Ohne beide Schlüsselteile können Einträge, Dateien und das Audit-Log nicht geprüft werden. Das Portal bleibt gesperrt, bis der fehlende Teil aus der Sicherung zurückgespielt ist.'), 503);
    exit;
} catch (Throwable $e) {
    error_log('netportal: ' . $e->getMessage());
    render_error_page(t('System-Datenbank nicht verfügbar'), $e->getMessage(), 503);
    exit;
}

auth_bootstrap();

$p = get_str('p', 40);
$a = get_str('a', 40);

switch ($a) {
    case 'lang':
        action_lang();
    case 'login':
        auth_handle_login();
    case 'login_otp':
        auth_handle_2fa_otp();
    case 'logout':
        auth_handle_logout();
    case 'setup':
        auth_handle_setup();
    case 'wa_login_options':
    case 'wa_login_finish':
        action_webauthn_login($a);
}

if (auth_needs_setup()) {
    auth_handle_setup();
}

$user = auth_user();
if ($user === null) {
    if ($p === 'login2fa' && ($pending = auth_pending_2fa()) !== null) {
        render_2fa_page($pending);
        exit;
    }
    if ($a !== '' && is_json_request()) {
        json_out(['error' => t('Anmeldung erforderlich')], 401);
    }
    if ($p !== '' && !is_post()) {
        $_SESSION['after_login'] = url($_GET);
    }
    render_login_page(null);
    exit;
}

// YubiKey-Pflicht ohne registrierten Key: nur "Mein Konto" erlaubt
if (!empty($_SESSION['must_enroll'])) {
    $f = auth_user_factors((int)$user['id']);
    if ($f['webauthn'] + $f['otp'] > 0) {
        $_SESSION['must_enroll'] = false;
    } elseif ($p !== 'account' && !in_array($a, ['wa_reg_options', 'wa_reg_finish', 'otp_register'], true)) {
        flash(t('Bitte zuerst einen YubiKey registrieren.'), 'warn');
        redirect(url(['p' => 'account']));
    }
}

// --- Aktionen: [Funktion, Recht, Art (get|form|json)] ----------------------------------------
const ACTIONS = [
    'net_download' => ['action_net_download', 'export', 'get'],
    'net_csv' => ['action_net_csv', 'export', 'get'],
    'audit_csv' => ['action_audit_csv', 'audit', 'get'],
    'icon' => ['action_icon', 'view', 'get'],
    'net_create_multi' => ['action_net_create_multi', 'edit', 'form'],
    'net_save' => ['action_net_save', 'edit', 'form'],
    'net_delete' => ['action_net_delete', 'delete', 'form'],
    'host_save' => ['action_host_save', 'edit', 'form'],
    'host_delete' => ['action_host_delete', 'delete', 'form'],
    'service_save' => ['action_service_save', 'edit', 'form'],
    'service_delete' => ['action_service_delete', 'edit', 'form'],
    'user_save' => ['action_user_save', 'users', 'form'],
    'user_delete' => ['action_user_delete', 'users', 'form'],
    'user_key_delete' => ['action_user_key_delete', 'users', 'form'],
    'account_save' => ['action_account_save', 'view', 'form'],
    'account_key_delete' => ['action_account_key_delete', 'view', 'form'],
    'otp_register' => ['action_otp_register', 'view', 'form'],
    'settings_save' => ['action_settings_save', 'settings', 'form'],
    'ssh_keygen' => ['action_ssh_keygen', 'settings', 'form'],
    'icons_import' => ['action_icons_import', 'settings', 'form'],
    'icon_delete' => ['action_icon_delete', 'settings', 'form'],
    'seal_renew' => ['action_seal_renew', 'reseal', 'form'],
    'manifest_write' => ['action_manifest_write', 'reseal', 'form'],
    'master_save' => ['action_master_save', 'edit', 'form'],
    'master_sync' => ['action_master_sync', 'edit', 'form'],
    'master_delete' => ['action_master_delete', 'delete', 'form'],
    'caddy_download' => ['action_caddy_download', 'caddy', 'form'],
    'diag_report' => ['action_diag_report', 'system', 'get'],
    'diag_debug' => ['action_diag_debug', 'system', 'form'],
    'diag_clearlog' => ['action_diag_clearlog', 'system', 'form'],
    'caddy_preset_save' => ['action_caddy_preset_save', 'caddy_presets', 'form'],
    'caddy_preset_delete' => ['action_caddy_preset_delete', 'caddy_presets', 'form'],
    'switch_save' => ['action_switch_save', 'edit', 'form'],
    'switch_delete' => ['action_switch_delete', 'delete', 'form'],
    'switch_port_save' => ['action_switch_port_save', 'edit', 'form'],
    'switch_ports_bulk' => ['action_switch_ports_bulk', 'edit', 'form'],
    'switch_vlan_save' => ['action_switch_vlan_save', 'edit', 'form'],
    'switch_apply' => ['action_switch_apply', 'edit', 'form'],
    'switch_import' => ['action_switch_import', 'edit', 'form'],
    'switch_tpl_save' => ['action_switch_tpl_save', 'edit', 'form'],
    'switch_tpl_delete' => ['action_switch_tpl_delete', 'delete', 'form'],
    'switch_cfg_download' => ['action_switch_cfg_download', 'switch_config', 'get'],
    'wan_save' => ['action_wan_save', 'edit', 'form'],
    'wan_delete' => ['action_wan_delete', 'delete', 'form'],
    'wan_apply' => ['action_wan_apply', 'edit', 'form'],
    'wan_tpl_save' => ['action_wan_tpl_save', 'edit', 'form'],
    'wan_tpl_delete' => ['action_wan_tpl_delete', 'delete', 'form'],
    'wan_csv' => ['action_wan_csv', 'export', 'get'],
    // JSON (fetch)
    'ping_host' => ['action_ping_host', 'ping', 'json'],
    'ping_target' => ['action_ping_target', 'ping', 'json'],
    'ping_network' => ['action_ping_network', 'ping', 'json'],
    'scan_host' => ['action_scan_host', 'scan', 'json'],
    'scan_adopt' => ['action_scan_adopt', 'edit', 'json'],
    'svc_check' => ['action_svc_check', 'check', 'json'],
    'svc_check_network' => ['action_svc_check_network', 'check', 'json'],
    'iperf_run' => ['action_iperf_run', 'iperf', 'json'],
    'detect_host' => ['action_detect_host', 'detect', 'json'],
    'ssh_probe' => ['action_ssh_probe', 'ssh', 'json'],
    'ssh_exec' => ['action_ssh_exec', 'ssh', 'json'],
    'wa_reg_options' => ['action_wa_reg_options', 'view', 'json'],
    'wa_reg_finish' => ['action_wa_reg_finish', 'view', 'json'],
    'ldap_test' => ['action_ldap_test', 'system', 'json'],
    'pam_test' => ['action_pam_test', 'system', 'json'],
    'otp_test' => ['action_otp_test', 'system', 'json'],
    'sys_install' => ['action_sys_install', 'system', 'json'],
    'sys_pam_helper' => ['action_sys_pam_helper', 'system', 'json'],
    'sys_status' => ['action_sys_status', 'system', 'json'],
    'integrity_run' => ['action_integrity_run', 'integrity', 'json'],
    'term_open' => ['action_term_open', 'terminal', 'json'],
    'term_read' => ['action_term_read', 'terminal', 'json'],
    'term_write' => ['action_term_write', 'terminal', 'json'],
    'term_resize' => ['action_term_resize', 'terminal', 'json'],
    'term_close' => ['action_term_close', 'terminal', 'json'],
    'caddy_preview' => ['action_caddy_preview', 'caddy', 'json'],
    'diag_clientlog' => ['action_diag_clientlog', 'view', 'json'],
    'wan_check' => ['action_wan_check', 'ping', 'json'],
    'wan_secret' => ['action_wan_secret', 'wan_secret', 'json'],
];

if ($a !== '') {
    if (!isset(ACTIONS[$a])) {
        render_error_page(t('Unbekannte Aktion'), t('Diese Aktion gibt es nicht.'), 404);
        exit;
    }
    [$fn, $perm, $kind] = ACTIONS[$a];
    require_perm($perm);
    if ($kind !== 'get') {
        require_post();
    }
    try {
        $fn();
    } catch (StoreWriteBlocked $e) {
        if ($kind === 'json') {
            json_out(['error' => $e->getMessage()], 423);
        }
        flash($e->getMessage(), 'error');
        redirect(back_url());
    } catch (NetNotFound $e) {
        if ($kind === 'json') {
            json_out(['error' => $e->getMessage()], 404);
        }
        render_error_page(t('Nicht gefunden'), $e->getMessage(), 404);
    }
    exit;
}

// --- Seiten ----------------------------------------------------------------------------------
const PAGES = [
    '' => ['page_dashboard', 'view'],
    'net' => ['page_net', 'view'],
    'net_new' => ['page_net_new', 'edit'],
    'net_edit' => ['page_net_edit', 'edit'],
    'host' => ['page_host', 'view'],
    'host_edit' => ['page_host_edit', 'edit'],
    'search' => ['page_search', 'view'],
    'audit' => ['page_audit', 'audit'],
    'users' => ['page_users', 'users'],
    'user_edit' => ['page_user_edit', 'users'],
    'account' => ['page_account', 'view'],
    'settings' => ['page_settings', 'settings'],
    'system' => ['page_system', 'system'],
    'integrity' => ['page_integrity', 'integrity'],
    'masters' => ['page_masters', 'view'],
    'master_edit' => ['page_master_edit', 'edit'],
    'terminal' => ['page_terminal', 'terminal'],
    'caddy' => ['page_caddy', 'caddy'],
    'diagnose' => ['page_diagnose', 'system'],
    'netmap' => ['page_netmap', 'view'],
    'switches' => ['page_switches', 'view'],
    'switch' => ['page_switch', 'view'],
    'switch_edit' => ['page_switch_edit', 'edit'],
    'switch_port' => ['page_switch_port', 'edit'],
    'switch_config' => ['page_switch_config', 'switch_config'],
    'switch_templates' => ['page_switch_templates', 'view'],
    'switch_template' => ['page_switch_template', 'view'],
    'switch_template_edit' => ['page_switch_template_edit', 'edit'],
    'wans' => ['page_wans', 'view'],
    'wan' => ['page_wan', 'view'],
    'wan_edit' => ['page_wan_edit', 'edit'],
    'wan_templates' => ['page_wan_templates', 'view'],
    'wan_template' => ['page_wan_template', 'view'],
    'wan_template_edit' => ['page_wan_template_edit', 'edit'],
];

if (!isset(PAGES[$p])) {
    render_error_page(t('Nicht gefunden'), t('Diese Seite gibt es nicht.'), 404);
    exit;
}
[$fn, $perm] = PAGES[$p];
require_perm($perm);
try {
    $fn();
} catch (NetNotFound $e) {
    render_error_page(t('Nicht gefunden'), $e->getMessage(), 404);
}
