<?php
/**
 * Anmeldung, Sitzungen, Rollen.
 *
 * Anmeldearten je Benutzer (users.auth_source in database/users.sql):
 *   local        Passwort-Hash in users.sql
 *   pam          Systemkonto über PAM (Dienst "netportal")
 *   pam_yubikey  Systemkonto über PAM inkl. YubiKey (Dienst "netportal-yubikey")
 *   ldap         Windows Active Directory (DC) oder OpenLDAP/FreeIPA
 *
 * Zweiter Faktor für jede Anmeldeart: YubiKey per WebAuthn/FIDO2 oder Yubico-OTP.
 *
 * Rollen:
 *   leser      nur lesen (ansehen, Ping, Export) – nichts anlegen, ändern, löschen
 *   poweruser  zusätzlich Werkzeuge: Dienste prüfen/erkennen, iperf3, SSH
 *   admin      Portal-Admin: anlegen, bearbeiten, löschen, Einstellungen,
 *              Audit-Log, Benutzerverwaltung, Integrität, System
 * "Administrator", per PAM angemeldeter "root", administrator@* und root@* sind immer Portal-Admin.
 */

declare(strict_types=1);

const ROLES = ['leser' => 'Leser', 'poweruser' => 'Power-User', 'admin' => 'Portal-Admin'];
const AUTH_SOURCES = ['local' => 'Lokal (users.sql)', 'pam' => 'PAM', 'pam_yubikey' => 'PAM + YubiKey', 'ldap' => 'LDAP / AD'];
const AUTH_THROTTLE_FREE_ATTEMPTS = 4;
const AUTH_2FA_TTL = 300;
const AUTH_DUMMY_HASH = '$2y$10$J5tCtIV2mCMYGD7C2bUZ..P5H3g94Lk9r1HtMRjk5DfEjt00v5N7m';

function role_label(string $r): string
{
    return t(ROLES[$r] ?? $r);
}

function source_label(string $s): string
{
    return t(AUTH_SOURCES[$s] ?? $s);
}

function auth_bootstrap(): void
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    session_name('netportal_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $scriptDir === '' ? '/' : $scriptDir . '/',
        'secure' => $https && getenv('NETPORTAL_INSECURE_COOKIE') !== '1',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    $sessDir = CACHE_DIR . '/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0770, true);
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '86400');
    session_start();

    if (!empty($_SESSION['uid'])) {
        $idle = (int)setting('auth.session_idle', 4 * 3600);
        if ((time() - (int)($_SESSION['last_activity'] ?? 0)) > $idle) {
            audit('logout', ['grund' => 'Inaktivität', 'idle_s' => $idle], (string)($_SESSION['uname'] ?? ''));
            $_SESSION = [];
            session_regenerate_id(true);
            flash(t('Sitzung wegen Inaktivität beendet – bitte erneut anmelden.'), 'info');
        } else {
            $_SESSION['last_activity'] = time();
        }
    }
}

/** Angemeldeter Benutzer (frisch aus users.sql, Hash geprüft). */
function auth_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    try {
        $row = user_get($uid);
    } catch (Throwable $e) {
        return null;
    }
    if ($row === null || (int)$row['active'] !== 1 || !user_row_ok($row)) {
        if ($row !== null && !user_row_ok($row)) {
            audit('integrity_violation', ['users_sql' => 'Eintrag des angemeldeten Benutzers verändert – Sitzung beendet'], (string)$row['username'], 'denied', ['type' => 'user', 'id' => $uid, 'label' => $row['username']]);
        }
        $_SESSION = [];
        return null;
    }
    $row['login_via'] = (string)($_SESSION['login_via'] ?? $row['auth_source']);
    $row['super'] = auth_is_superadmin((string)$row['username'], $row['login_via']);
    if ($row['role'] === 'operator') {
        $row['role'] = 'poweruser';
    }
    return $cache = $row;
}

function auth_user_soft(): ?array
{
    try {
        return isset($_SESSION) ? auth_user() : null;
    } catch (Throwable $e) {
        return null;
    }
}

function auth_is_superadmin(string $username, string $via): bool
{
    $u = strtolower($username);
    if ($u === 'administrator') {
        return true;
    }
    if ($u === 'root' && in_array($via, ['pam', 'pam_yubikey'], true)) {
        return true;
    }
    return (bool)preg_match('/^(administrator|root)@[^@\s]+$/i', $username);
}

function auth_role(): string
{
    $u = auth_user();
    return $u === null ? '' : ($u['super'] ? 'admin' : (string)$u['role']);
}

/**
 * Rechte:
 *   view, ping, export                      alle (Leser = nur lesend)
 *   check, scan, iperf, ssh                 Power-User, Portal-Admin
 *   edit, delete, detect, ssh_free,
 *   users, audit, settings, integrity,
 *   reseal, system                          Portal-Admin
 */
function can(string $perm): bool
{
    $role = auth_role();
    if ($role === '') {
        return false;
    }
    return match ($perm) {
        // Leser: nur ansehen und exportieren – kein Ping, kein iperf3, kein SSH
        'view', 'export' => true,
        'ping', 'check', 'scan', 'iperf', 'ssh', 'terminal', 'caddy', 'switch_config' => in_array($role, ['poweruser', 'admin'], true),
        'edit', 'delete', 'detect', 'ssh_free', 'terminal_key', 'caddy_presets', 'wan_secret', 'users', 'audit', 'settings', 'integrity', 'reseal', 'system' => $role === 'admin',
        default => false,
    };
}

function require_perm(string $perm): void
{
    if (can($perm)) {
        return;
    }
    audit('access_denied', ['recht' => $perm, 'seite' => (string)($_GET['p'] ?? ''), 'aktion' => (string)($_GET['a'] ?? '')], null, 'denied');
    http_response_code(403);
    if (is_json_request()) {
        json_out(['error' => t('Keine Berechtigung')], 403);
    }
    render_error_page(t('Keine Berechtigung'), t('Für diese Aktion fehlt deiner Rolle die Berechtigung.'));
    exit;
}

function auth_valid_username(string $u): bool
{
    return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9._@\\\\-]{0,127}$/', $u);
}

// --- Rate-Limiting (pro IP) ---------------------------------------------------------------------

function auth_throttle(callable $mutator): array
{
    $fh = @fopen(CACHE_DIR . '/auth_throttle.json', 'c+');
    if (!$fh) {
        return [];
    }
    flock($fh, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fh), true);
    $data = is_array($data) ? $data : [];
    $now = time();
    foreach ($data as $k => $e) {
        if (!is_array($e) || $now - (int)($e['last'] ?? 0) > 3600) {
            unset($data[$k]);
        }
    }
    $data = $mutator($data, $now);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data;
}

function auth_lock_seconds(int $fails): int
{
    return match (true) {
        $fails <= AUTH_THROTTLE_FREE_ATTEMPTS => 0,
        $fails <= 7 => 30,
        $fails <= 10 => 120,
        $fails <= 14 => 600,
        default => 1800,
    };
}

function auth_locked_for(string $ip): int
{
    $remaining = 0;
    auth_throttle(function (array $d) use ($ip, &$remaining) {
        $remaining = max(0, (int)($d[$ip]['lockUntil'] ?? 0) - time());
        return $d;
    });
    return $remaining;
}

function auth_register_failure(string $ip): int
{
    $fails = 0;
    auth_throttle(function (array $d, int $now) use ($ip, &$fails) {
        $e = is_array($d[$ip] ?? null) ? $d[$ip] : ['fails' => 0];
        $e['fails'] = (int)$e['fails'] + 1;
        $fails = $e['fails'];
        $e['last'] = $now;
        $lock = auth_lock_seconds($e['fails']);
        $e['lockUntil'] = $lock > 0 ? $now + $lock : 0;
        $d[$ip] = $e;
        return $d;
    });
    return $fails;
}

function auth_register_success(string $ip): void
{
    auth_throttle(function (array $d) use ($ip) {
        unset($d[$ip]);
        return $d;
    });
}

// --- Anmeldung ----------------------------------------------------------------------------------

function auth_normalize_username(string $u): string
{
    if (setting('auth.ldap.enabled') && str_contains($u, '\\')) {
        [$dom, $name] = explode('\\', $u, 2);
        $nb = (string)setting('auth.ldap.ad_netbios', '');
        if ($nb === '' || strcasecmp($dom, $nb) === 0) {
            return $name;
        }
    }
    return $u;
}

function auth_attempt(string $username, string $password, string $otp): array
{
    $ip = client_ip();
    $wait = auth_locked_for($ip);
    if ($wait > 0) {
        return ['ok' => false, 'reason' => 'locked', 'retryAfter' => $wait];
    }
    $row = user_by_name($username);
    if ($row === null && str_contains($username, '@') && setting('auth.ldap.enabled')) {
        $cand = user_by_name(explode('@', $username)[0]);
        if ($cand !== null && $cand['auth_source'] === 'ldap') {
            $row = $cand;
        }
    }

    if ($row !== null) {
        if (!user_row_ok($row)) {
            password_verify($password, AUTH_DUMMY_HASH);
            audit('integrity_violation', ['users_sql' => 'Benutzer-Eintrag verändert – Anmeldung verweigert'], $username, 'denied', ['type' => 'user', 'id' => $row['id'], 'label' => $row['username']]);
            return ['ok' => false, 'reason' => 'integrity', 'retryAfter' => 0, 'fails' => auth_register_failure($ip)];
        }
        $source = (string)$row['auth_source'];
        $res = auth_check_source($source, $username, $password, $otp, $row);
        if ($res['ok'] && (int)$row['active'] !== 1) {
            $res = ['ok' => false, 'reason' => 'account'];
        }
        if ($res['ok']) {
            auth_register_success($ip);
            if ($source === 'ldap' && !empty($res['ldap'])) {
                auth_sync_ldap_user((int)$row['id'], $res['ldap']);
            }
            return ['ok' => true, 'user' => user_get((int)$row['id']) ?? $row, 'via' => $source, 'extra' => $res['ldap']['dn'] ?? null];
        }
        if (in_array($res['reason'], ['credentials', 'account', 'otp_missing', 'not_allowed'], true)) {
            $res['fails'] = auth_register_failure($ip);
        }
        return $res + ['retryAfter' => 0];
    }

    $tries = [];
    if ($otp !== '' && setting('auth.pam_yubikey.enabled') && setting('auth.pam.autoprovision')) {
        $tries[] = 'pam_yubikey';
    } elseif (setting('auth.pam.enabled') && setting('auth.pam.autoprovision')) {
        $tries[] = 'pam';
    }
    if (setting('auth.ldap.enabled') && setting('auth.ldap.autoprovision')) {
        $tries[] = 'ldap';
    }
    $last = ['ok' => false, 'reason' => 'credentials'];
    foreach ($tries as $source) {
        $res = auth_check_source($source, $username, $password, $otp, null);
        if ($res['ok']) {
            auth_register_success($ip);
            $name = $source === 'ldap' && !empty($res['ldap']['username']) ? $res['ldap']['username'] : $username;
            $existing = user_by_name($name);
            $id = $existing !== null ? (int)$existing['id'] : auth_provision($name, $source, $res['ldap'] ?? []);
            if ($existing !== null && $source === 'ldap') {
                auth_sync_ldap_user($id, $res['ldap']);
            }
            return ['ok' => true, 'user' => user_get($id), 'via' => $source, 'extra' => $res['ldap']['dn'] ?? null];
        }
        if ($res['reason'] !== 'credentials') {
            $last = $res;
        }
    }
    if ($tries === []) {
        password_verify($password, AUTH_DUMMY_HASH);
    }
    if (in_array($last['reason'], ['credentials', 'account', 'not_allowed'], true)) {
        $last['fails'] = auth_register_failure($ip);
    }
    return $last + ['retryAfter' => 0];
}

function auth_check_source(string $source, string $username, string $password, string $otp, ?array $row): array
{
    switch ($source) {
        case 'local':
            if (!setting('auth.local.enabled', true) && strtolower($username) !== 'administrator') {
                return ['ok' => false, 'reason' => 'method_disabled'];
            }
            $hash = (string)($row['password_hash'] ?? '');
            if ($hash === '' || !password_verify($password, $hash)) {
                return ['ok' => false, 'reason' => 'credentials'];
            }
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                try {
                    user_update((int)$row['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                } catch (StoreWriteBlocked $e) {
                    // Login trotzdem erlauben; die Abweichung meldet die Integritätsprüfung
                }
            }
            return ['ok' => true];

        case 'pam':
        case 'pam_yubikey':
            if (!setting('auth.' . $source . '.enabled')) {
                return ['ok' => false, 'reason' => 'method_disabled'];
            }
            if ($source === 'pam_yubikey' && $otp === '' && !setting('auth.pam_yubikey.u2f_server', false)) {
                return ['ok' => false, 'reason' => 'otp_missing'];
            }
            $code = pam_authenticate_user($source === 'pam' ? 'std' : 'yubikey', $username, $password, $otp);
            return match ($code) {
                0 => ['ok' => true],
                1 => ['ok' => false, 'reason' => 'credentials'],
                2 => ['ok' => false, 'reason' => 'account'],
                default => ['ok' => false, 'reason' => 'system'],
            };

        case 'ldap':
            if (!setting('auth.ldap.enabled')) {
                return ['ok' => false, 'reason' => 'method_disabled'];
            }
            $r = ldap_authenticate_user($username, $password);
            return $r['ok'] ? ['ok' => true, 'ldap' => $r] : ['ok' => false, 'reason' => $r['reason'], 'ldap_detail' => $r['detail'] ?? null];
    }
    return ['ok' => false, 'reason' => 'system'];
}

function auth_provision(string $username, string $source, array $ldap): int
{
    $role = (string)setting($source === 'ldap' ? 'auth.ldap.default_role' : 'auth.pam.default_role', 'leser');
    if (!empty($ldap['role'])) {
        $role = $ldap['role'];
    }
    if (auth_is_superadmin($username, $source)) {
        $role = 'admin';
    }
    $role = isset(ROLES[$role]) ? $role : 'leser';
    $id = user_insert([
        'username' => $username, 'display_name' => $ldap['display_name'] ?? null, 'email' => $ldap['email'] ?? null,
        'auth_source' => $source, 'password_hash' => null, 'role' => $role, 'active' => 1, 'must_change_pw' => 0, 'require_2fa' => 0,
        'created_at' => now_str(), 'created_by' => 'auto:' . $source, 'updated_at' => now_str(), 'updated_by' => 'auto:' . $source,
    ]);
    audit('user_create', ['quelle' => $source, 'automatisch' => true, 'rolle' => $role, 'ldap_dn' => $ldap['dn'] ?? null, 'gruppen' => $ldap['groups_matched'] ?? null],
        $username, 'ok', ['type' => 'user', 'id' => $id, 'label' => $username]);
    return $id;
}

function auth_sync_ldap_user(int $id, array $ldap): void
{
    $old = user_get($id);
    if ($old === null) {
        return;
    }
    $sets = [];
    foreach (['display_name', 'email'] as $k) {
        if (!empty($ldap[$k])) {
            $sets[$k] = $ldap[$k];
        }
    }
    if (!empty($ldap['role']) && isset(ROLES[$ldap['role']])) {
        $sets['role'] = $ldap['role'];
    }
    $diff = audit_diff($old, $sets);
    if ($diff !== []) {
        try {
            user_update($id, $sets + ['updated_at' => now_str(), 'updated_by' => 'ldap-sync']);
            audit('user_update', ['quelle' => 'LDAP-Abgleich', 'aenderungen' => $diff], (string)$old['username'], 'ok', ['type' => 'user', 'id' => $id, 'label' => $old['username']]);
        } catch (StoreWriteBlocked $e) {
            // users.sql gesperrt – Abgleich beim nächsten Login
        }
    }
}

function auth_user_factors(int $uid): array
{
    return [
        'webauthn' => count(user_keys($uid, 'webauthn_credentials')),
        'otp' => setting('auth.yubico_otp.enabled') ? count(user_keys($uid, 'yubico_otp_keys')) : 0,
    ];
}

function auth_requires_2fa(array $user): bool
{
    return (int)$user['require_2fa'] === 1
        || (setting('auth.require_2fa_admin') && ($user['role'] === 'admin' || auth_is_superadmin((string)$user['username'], (string)$user['auth_source'])));
}

function auth_complete_login(array $user, string $via, array $extra = []): never
{
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa']);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['uname'] = (string)$user['username'];
    $_SESSION['login_via'] = $via;
    $_SESSION['last_activity'] = time();
    $_SESSION['login_at'] = time();
    $f = auth_user_factors((int)$user['id']);
    $_SESSION['must_enroll'] = auth_requires_2fa($user) && $f['webauthn'] + $f['otp'] === 0;
    try {
        user_update((int)$user['id'], ['last_login' => now_str()]);
    } catch (StoreWriteBlocked $e) {
        // users.sql ist schreibgeschützt (Integrität) – Anmeldung trotzdem, Hinweis für Admins
    }
    audit('login_success', ['via' => $via, 'zweiter_faktor' => $extra['factor'] ?? 'keiner', 'key' => $extra['key'] ?? null,
        'ldap_dn' => $extra['dn'] ?? null, 'muss_key_registrieren' => $_SESSION['must_enroll']], (string)$user['username'], 'ok',
        ['type' => 'user', 'id' => $user['id'], 'label' => $user['username']]);
    $target = $_SESSION['after_login'] ?? url();
    unset($_SESSION['after_login']);
    if (!is_string($target) || !str_starts_with($target, './')) {
        $target = url();
    }
    if ($_SESSION['must_enroll']) {
        flash(t('Für dein Konto ist ein YubiKey Pflicht – bitte jetzt registrieren.'), 'warn');
        $target = url(['p' => 'account']);
    } elseif ((int)$user['must_change_pw'] === 1 && $via === 'local') {
        flash(t('Bitte jetzt ein neues Passwort festlegen.'), 'info');
        $target = url(['p' => 'account']);
    }
    if (is_json_request()) {
        json_out(['ok' => true, 'redirect' => $target]);
    }
    redirect($target);
}

function auth_reason_text(string $reason, int $retryAfter = 0): string
{
    return match ($reason) {
        'locked' => t('Zu viele Fehlversuche. Bitte in {s} Sekunden erneut versuchen.', ['s' => max(1, $retryAfter)]),
        'account' => t('Dieses Konto ist gesperrt, deaktiviert oder abgelaufen.'),
        'not_allowed' => t('Dieses Konto ist nicht für das Network Portal freigegeben (Gruppe fehlt).'),
        'integrity' => t('Dieses Konto ist gesperrt: sein Eintrag in users.sql wurde verändert. Bitte einen Portal-Admin informieren.'),
        'system' => t('Anmeldung derzeit nicht möglich (Systemfehler – Details im Audit-Log/Server-Log).'),
        'otp_missing' => t('Für dieses Konto ist der YubiKey nötig: ins Feld „YubiKey“ klicken und den Key berühren.'),
        'method_disabled' => t('Die Anmeldeart dieses Kontos ist derzeit deaktiviert.'),
        'csrf' => t('Sitzung abgelaufen – bitte erneut versuchen.'),
        'invalid_input' => t('Bitte Benutzername und Passwort eingeben.'),
        default => t('Benutzername oder Passwort falsch.'),
    };
}

// --- Routen -------------------------------------------------------------------------------------

function auth_handle_login(): never
{
    if (auth_user() !== null) {
        redirect(url());
    }
    if (!is_post()) {
        render_login_page(null);
        exit;
    }
    $user = auth_normalize_username(trim((string)($_POST['username'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $otp = trim((string)($_POST['otp'] ?? ''));
    $logUser = $user !== '' ? mb_substr($user, 0, 128) : null;

    if (!csrf_valid(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        audit('login_failure', ['grund' => 'csrf'], $logUser, 'fail');
        render_login_page(auth_reason_text('csrf'));
        exit;
    }
    if ($user === '' || $pass === '' || strlen($pass) > 512 || !auth_valid_username($user) || strlen($otp) > 128) {
        audit('login_failure', ['grund' => 'invalid_input'], $logUser, 'fail');
        render_login_page(auth_reason_text('invalid_input'), $user);
        exit;
    }
    $res = auth_attempt($user, $pass, $otp);
    if (!$res['ok']) {
        audit('login_failure', array_filter(['grund' => $res['reason'], 'fehlversuche_ip' => $res['fails'] ?? null,
            'sperre_s' => $res['reason'] === 'locked' ? $res['retryAfter'] : null, 'ldap' => $res['ldap_detail'] ?? null,
            'otp_angegeben' => $otp !== ''], static fn($v) => $v !== null), $user, 'fail');
        render_login_page(auth_reason_text($res['reason'], (int)($res['retryAfter'] ?? 0)), $user);
        exit;
    }
    $row = $res['user'];
    $f = auth_user_factors((int)$row['id']);
    if ($f['webauthn'] + $f['otp'] > 0) {
        session_regenerate_id(true);
        $_SESSION['pending_2fa'] = ['uid' => (int)$row['id'], 'via' => $res['via'], 'ts' => time(), 'dn' => $res['extra'] ?? null, 'tries' => 0];
        audit('twofa_required', ['via' => $res['via'], 'webauthn_keys' => $f['webauthn'], 'otp_keys' => $f['otp']], (string)$row['username']);
        redirect(url(['p' => 'login2fa']));
    }
    auth_complete_login($row, $res['via'], ['dn' => $res['extra'] ?? null]);
}

function auth_pending_2fa(): ?array
{
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!is_array($p) || time() - (int)($p['ts'] ?? 0) > AUTH_2FA_TTL || (int)($p['tries'] ?? 0) >= 5) {
        unset($_SESSION['pending_2fa']);
        return null;
    }
    $u = user_get((int)$p['uid']);
    return $u === null || (int)$u['active'] !== 1 || !user_row_ok($u) ? null : ['user' => $u, 'via' => (string)$p['via'], 'dn' => $p['dn'] ?? null];
}

function auth_handle_2fa_otp(): never
{
    $pending = auth_pending_2fa();
    if ($pending === null || !is_post() || !csrf_valid(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        redirect(url());
    }
    $u = $pending['user'];
    $r = yubico_otp_verify_for_user((int)$u['id'], trim((string)($_POST['otp'] ?? '')));
    if (!$r['ok']) {
        $_SESSION['pending_2fa']['tries'] = (int)($_SESSION['pending_2fa']['tries'] ?? 0) + 1;
        auth_register_failure(client_ip());
        audit('twofa_failure', ['methode' => 'yubico_otp', 'grund' => $r['error'], 'key_id' => $r['public_id'] ?? null], (string)$u['username'], 'fail');
        $_SESSION['twofa_error'] = $r['error'];
        redirect(url(['p' => 'login2fa']));
    }
    audit('twofa_success', ['methode' => 'yubico_otp', 'key_id' => $r['public_id']], (string)$u['username']);
    auth_complete_login($u, $pending['via'], ['factor' => 'yubico_otp', 'key' => $r['public_id'], 'dn' => $pending['dn']]);
}

function auth_handle_logout(): never
{
    $u = auth_user();
    if ($u !== null) {
        audit('logout', ['dauer_min' => isset($_SESSION['login_at']) ? round((time() - (int)$_SESSION['login_at']) / 60, 1) : null], (string)$u['username']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    redirect(url());
}

function auth_needs_setup(): bool
{
    return users_count() === 0;
}

function auth_handle_setup(): never
{
    if (!auth_needs_setup()) {
        redirect(url());
    }
    $error = null;
    if (is_post()) {
        $pw = (string)($_POST['password'] ?? '');
        $pw2 = (string)($_POST['password2'] ?? '');
        if (!csrf_valid(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $error = t('Sitzung abgelaufen – bitte erneut versuchen.');
        } elseif (($e = password_policy_error($pw)) !== null) {
            $error = $e;
        } elseif ($pw !== $pw2) {
            $error = t('Die Passwörter stimmen nicht überein.');
        } else {
            $id = user_insert([
                'username' => 'Administrator', 'display_name' => 'Administrator', 'auth_source' => 'local',
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1, 'must_change_pw' => 0, 'require_2fa' => 0,
                'created_at' => now_str(), 'created_by' => 'setup', 'updated_at' => now_str(), 'updated_by' => 'setup',
            ]);
            audit('setup', ['schluessel_fingerprint' => integrity_key_fingerprint(), 'php' => PHP_VERSION, 'benutzerdatei' => 'database/users.sql'], 'Administrator');
            auth_complete_login(user_get($id), 'local');
        }
    }
    render_setup_page($error);
    exit;
}

function password_policy_error(string $pw): ?string
{
    if (mb_strlen($pw) < 12) {
        return t('Das Passwort muss mindestens 12 Zeichen lang sein.');
    }
    if (strlen($pw) > 512) {
        return t('Das Passwort ist zu lang.');
    }
    $classes = (int)preg_match('/[a-z]/', $pw) + (int)preg_match('/[A-Z]/', $pw) + (int)preg_match('/\d/', $pw) + (int)preg_match('/[^A-Za-z0-9]/', $pw);
    if ($classes < 3) {
        return t('Das Passwort braucht mindestens drei von: Kleinbuchstaben, Großbuchstaben, Ziffern, Sonderzeichen.');
    }
    return null;
}
