<?php
/**
 * LDAP-Anmeldung – mit Schwerpunkt Windows Active Directory (Domain Controller).
 *
 * AD-Modus:
 *   - Anmeldung als "max", "max@firma.local" (UPN) oder "FIRMA\max"
 *   - Suche über sAMAccountName / userPrincipalName, nur Personen-Konten
 *   - deaktivierte (userAccountControl) und abgelaufene Konten (accountExpires) werden abgewiesen
 *   - verschachtelte Gruppen über LDAP_MATCHING_RULE_IN_CHAIN (1.2.840.113556.1.4.1941)
 *   - AD-Fehlercodes beim Bind (52e, 532, 533, 701, 773, 775 …) landen verständlich im Audit
 *   - ohne Dienstkonto: Bind direkt als Benutzer (UPN), danach Suche des eigenen Eintrags
 *   - Gruppen als DN (CN=NetAdmins,OU=Gruppen,DC=firma,DC=local) oder nur Name (NetAdmins)
 *
 * Generisch (OpenLDAP, FreeIPA, Samba): uid/mail-Filter, memberOf oder Gruppensuche.
 *
 * TLS: ldaps:// (Port 636) oder StartTLS. Eigene CA (z. B. AD-CS) über "CA-Datei".
 * Benötigt die PHP-Erweiterung "ldap" (Paket php-ldap).
 */

declare(strict_types=1);

const AD_UAC_DISABLED = 0x0002;
const AD_MATCHING_RULE_IN_CHAIN = '1.2.840.113556.1.4.1941';

const AD_BIND_ERRORS = [
    '525' => ['credentials', 'Benutzer nicht gefunden'],
    '52e' => ['credentials', 'Passwort falsch'],
    '530' => ['account', 'Anmeldung zu dieser Uhrzeit nicht erlaubt'],
    '531' => ['account', 'Anmeldung von diesem Rechner nicht erlaubt'],
    '532' => ['account', 'Passwort abgelaufen'],
    '533' => ['account', 'Konto deaktiviert'],
    '568' => ['account', 'Zu viele Sicherheitskennungen (Token zu groß)'],
    '701' => ['account', 'Konto abgelaufen'],
    '773' => ['account', 'Passwort muss beim nächsten Login geändert werden'],
    '775' => ['account', 'Konto gesperrt (zu viele Fehlversuche)'],
];

function ldap_available(): bool
{
    return function_exists('ldap_connect');
}

function ldap_cfg(?array $cfg = null): array
{
    $cfg ??= (array)setting('auth.ldap');
    if (trim((string)($cfg['user_filter'] ?? '')) === '') {
        $cfg['user_filter'] = ($cfg['mode'] ?? 'ad') === 'ad'
            ? '(&(objectCategory=person)(objectClass=user)(|(sAMAccountName=%s)(userPrincipalName=%u)))'
            : '(|(uid=%s)(mail=%u))';
    }
    return $cfg;
}

/** Verbindung aufbauen (TLS-Optionen vor dem Verbinden setzen). */
function ldap_connect_cfg(array $cfg)
{
    if (!ldap_available()) {
        throw new RuntimeException(t('PHP-Erweiterung „ldap“ fehlt (Paket php-ldap installieren).'));
    }
    $uri = trim((string)($cfg['uri'] ?? ''));
    foreach (preg_split('/\s+/', $uri) ?: [] as $u) {
        if (!preg_match('#^ldaps?://[A-Za-z0-9.\-\[\]:]+/?$#i', $u)) {
            throw new RuntimeException(t('LDAP-URI ungültig: „{u}“ (z. B. ldaps://dc01.firma.local)', ['u' => $u]));
        }
    }
    if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT')) {
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, !empty($cfg['verify_cert']) ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER);
    }
    if (!empty($cfg['ca_file']) && defined('LDAP_OPT_X_TLS_CACERTFILE')) {
        if (!is_readable((string)$cfg['ca_file'])) {
            throw new RuntimeException(t('CA-Datei nicht lesbar: {f}', ['f' => $cfg['ca_file']]));
        }
        @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, (string)$cfg['ca_file']);
    }
    $conn = @ldap_connect($uri);
    if (!$conn) {
        throw new RuntimeException(t('LDAP-Verbindung konnte nicht vorbereitet werden.'));
    }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0); // AD: Referrals nicht folgen (sonst Hänger)
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)($cfg['timeout'] ?? 5));
    if (defined('LDAP_OPT_TIMELIMIT')) {
        ldap_set_option($conn, LDAP_OPT_TIMELIMIT, (int)($cfg['timeout'] ?? 5));
    }
    if (!empty($cfg['starttls']) && stripos($uri, 'ldaps://') !== 0) {
        if (!@ldap_start_tls($conn)) {
            throw new RuntimeException(t('StartTLS fehlgeschlagen:') . ' ' . ldap_error($conn) . ldap_diag($conn));
        }
    }
    return $conn;
}

function ldap_diag($conn): string
{
    $d = '';
    if (defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
        @ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $d);
    }
    return $d !== '' ? ' (' . $d . ')' : '';
}

/** AD-Unterfehler aus "… data 52e, v4563" herauslesen. */
function ldap_ad_subcode($conn): ?string
{
    $d = '';
    if (defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
        @ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $d);
    }
    return preg_match('/data ([0-9a-f]{3,4})/i', (string)$d, $m) ? strtolower($m[1]) : null;
}

/** Login-Namen für Bind/Suche zerlegen. */
function ldap_names(array $cfg, string $username): array
{
    $u = $username;
    if (str_contains($u, '\\')) {
        $u = explode('\\', $u, 2)[1];
    }
    if (str_contains($u, '@')) {
        $sam = explode('@', $u)[0];
        $upn = $u;
    } else {
        $sam = $u;
        $dom = trim((string)($cfg['ad_domain'] ?? ''));
        $upn = $dom !== '' ? $u . '@' . $dom : $u;
    }
    return ['sam' => $sam, 'upn' => $upn];
}

function ldap_build_filter(array $cfg, array $names): string
{
    $f = (string)$cfg['user_filter'];
    return strtr($f, [
        '%s' => ldap_escape($names['sam'], '', LDAP_ESCAPE_FILTER),
        '%u' => ldap_escape($names['upn'], '', LDAP_ESCAPE_FILTER),
    ]);
}

function ldap_find_user($conn, array $cfg, array $names): ?array
{
    $attrs = ['dn', 'cn', 'displayName', 'mail', 'memberOf', 'uid', 'sAMAccountName', 'userPrincipalName', 'userAccountControl', 'accountExpires', 'objectSid'];
    $sr = @ldap_search($conn, (string)$cfg['base_dn'], ldap_build_filter($cfg, $names), $attrs, 0, 2);
    if (!$sr) {
        throw new RuntimeException(t('LDAP-Suche fehlgeschlagen:') . ' ' . ldap_error($conn) . ldap_diag($conn));
    }
    $entries = ldap_get_entries($conn, $sr);
    if (!is_array($entries) || (int)($entries['count'] ?? 0) !== 1) {
        return null; // keiner oder mehrdeutig
    }
    return $entries[0];
}

function ldap_first(array $entry, string $attr): string
{
    $a = strtolower($attr);
    return isset($entry[$a][0]) ? (string)$entry[$a][0] : '';
}

/** Gruppen-DN auflösen (Angabe als DN oder nur als Name). */
function ldap_group_dn($conn, array $cfg, string $group): ?string
{
    $group = trim($group);
    if ($group === '') {
        return null;
    }
    if (str_contains($group, '=')) {
        return $group;
    }
    $f = ($cfg['mode'] ?? 'ad') === 'ad'
        ? '(&(objectClass=group)(|(cn=' . ldap_escape($group, '', LDAP_ESCAPE_FILTER) . ')(sAMAccountName=' . ldap_escape($group, '', LDAP_ESCAPE_FILTER) . ')))'
        : '(&(|(objectClass=groupOfNames)(objectClass=groupOfUniqueNames)(objectClass=posixGroup))(cn=' . ldap_escape($group, '', LDAP_ESCAPE_FILTER) . '))';
    $sr = @ldap_search($conn, (string)$cfg['base_dn'], $f, ['dn'], 0, 2);
    $e = $sr ? ldap_get_entries($conn, $sr) : null;
    return is_array($e) && (int)($e['count'] ?? 0) === 1 ? (string)$e[0]['dn'] : null;
}

function ldap_in_group($conn, array $cfg, array $entry, string $group, string $username): bool
{
    $groupDn = ldap_group_dn($conn, $cfg, $group);
    if ($groupDn === null) {
        return false;
    }
    foreach ($entry['memberof'] ?? [] as $k => $g) {
        if ($k !== 'count' && strcasecmp((string)$g, $groupDn) === 0) {
            return true;
        }
    }
    $gEsc = ldap_escape($groupDn, '', LDAP_ESCAPE_FILTER);
    if (($cfg['mode'] ?? 'ad') === 'ad' && !empty($cfg['nested_groups'])) {
        // Verschachtelte Gruppen: Eintrag des Benutzers mit Matching-Rule-In-Chain lesen
        $sr = @ldap_read($conn, (string)$entry['dn'], '(memberOf:' . AD_MATCHING_RULE_IN_CHAIN . ':=' . $gEsc . ')', ['dn']);
        $e = $sr ? ldap_get_entries($conn, $sr) : null;
        return is_array($e) && (int)($e['count'] ?? 0) > 0;
    }
    $dn = ldap_escape((string)$entry['dn'], '', LDAP_ESCAPE_FILTER);
    $uid = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
    $sr = @ldap_read($conn, $groupDn, "(|(member=$dn)(uniqueMember=$dn)(memberUid=$uid))", ['dn']);
    $e = $sr ? ldap_get_entries($conn, $sr) : null;
    return is_array($e) && (int)($e['count'] ?? 0) > 0;
}

/** AD: deaktiviert / abgelaufen? Liefert Grund oder null. */
function ldap_ad_account_problem(array $entry): ?string
{
    $uac = (int)ldap_first($entry, 'userAccountControl');
    if ($uac & AD_UAC_DISABLED) {
        return t('Konto im AD deaktiviert');
    }
    $exp = ldap_first($entry, 'accountExpires');
    if ($exp !== '' && $exp !== '0' && $exp !== '9223372036854775807') {
        // Windows FILETIME (100 ns seit 1601) -> Unix
        $unix = (int)((float)$exp / 10000000 - 11644473600);
        if ($unix > 0 && $unix < time()) {
            return t('Konto im AD abgelaufen ({d})', ['d' => fmt_dt($unix, false)]);
        }
    }
    return null;
}

/**
 * Anmeldung prüfen. Rückgabe:
 *   ['ok' => true, 'username', 'display_name', 'email', 'role', 'dn', 'groups_matched']
 *   ['ok' => false, 'reason' => credentials|account|not_allowed|system, 'detail' => …]
 */
function ldap_authenticate_user(string $username, string $password): array
{
    $cfg = ldap_cfg();
    if ($password === '') {
        return ['ok' => false, 'reason' => 'credentials', 'detail' => t('leeres Passwort')];
    }
    try {
        $conn = ldap_connect_cfg($cfg);
        $names = ldap_names($cfg, $username);
        $isAd = ($cfg['mode'] ?? 'ad') === 'ad';
        $bindDn = (string)($cfg['bind_dn'] ?? '');

        if ($bindDn === '' && $isAd) {
            // Kein Dienstkonto: direkt als Benutzer binden (UPN), dann eigenen Eintrag suchen
            if (!@ldap_bind($conn, $names['upn'], $password)) {
                return ldap_bind_failure($conn, t('Bind als') . ' ' . $names['upn']);
            }
            $entry = ldap_find_user($conn, $cfg, $names);
            if ($entry === null) {
                return ['ok' => false, 'reason' => 'credentials', 'detail' => t('Eintrag nach Bind nicht gefunden')];
            }
        } else {
            $ok = $bindDn !== '' ? @ldap_bind($conn, $bindDn, (string)($cfg['bind_password'] ?? '')) : @ldap_bind($conn);
            if (!$ok) {
                throw new RuntimeException(t('Bind mit Dienstkonto fehlgeschlagen:') . ' ' . ldap_error($conn) . ldap_diag($conn));
            }
            $entry = ldap_find_user($conn, $cfg, $names);
            if ($entry === null) {
                return ['ok' => false, 'reason' => 'credentials', 'detail' => t('Benutzer nicht (eindeutig) gefunden')];
            }
            if ($isAd && ($p = ldap_ad_account_problem($entry)) !== null) {
                return ['ok' => false, 'reason' => 'account', 'detail' => $p];
            }
            if (!@ldap_bind($conn, (string)$entry['dn'], $password)) {
                return ldap_bind_failure($conn, t('Bind als') . ' ' . $entry['dn']);
            }
        }
        if ($isAd && ($p = ldap_ad_account_problem($entry)) !== null) {
            return ['ok' => false, 'reason' => 'account', 'detail' => $p];
        }
        $canon = $isAd ? (ldap_first($entry, 'sAMAccountName') ?: $names['sam']) : (ldap_first($entry, 'uid') ?: $names['sam']);
        $matched = [];
        if (trim((string)$cfg['allowed_group']) !== '') {
            if (!ldap_in_group($conn, $cfg, $entry, (string)$cfg['allowed_group'], $canon)) {
                return ['ok' => false, 'reason' => 'not_allowed', 'detail' => t('nicht Mitglied von {g}', ['g' => $cfg['allowed_group']])];
            }
            $matched[] = $cfg['allowed_group'];
        }
        $role = null;
        if (ldap_in_group($conn, $cfg, $entry, (string)$cfg['admin_group'], $canon)) {
            $role = 'admin';
            $matched[] = $cfg['admin_group'];
        } elseif (ldap_in_group($conn, $cfg, $entry, (string)$cfg['operator_group'], $canon)) {
            $role = 'poweruser';
            $matched[] = $cfg['operator_group'];
        }
        @ldap_unbind($conn);
        return [
            'ok' => true,
            'username' => $canon,
            'display_name' => ldap_first($entry, 'displayName') ?: ldap_first($entry, 'cn'),
            'email' => ldap_first($entry, 'mail'),
            'role' => $role,
            'dn' => (string)$entry['dn'],
            'groups_matched' => $matched,
        ];
    } catch (Throwable $e) {
        error_log('netportal LDAP: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'system', 'detail' => $e->getMessage()];
    }
}

function ldap_bind_failure($conn, string $what): array
{
    $sub = ldap_ad_subcode($conn);
    if ($sub !== null && isset(AD_BIND_ERRORS[$sub])) {
        [$reason, $text] = AD_BIND_ERRORS[$sub];
        return ['ok' => false, 'reason' => $reason, 'detail' => $what . ': AD ' . $sub . ' – ' . t($text)];
    }
    $errno = ldap_errno($conn);
    if ($errno === -1) {
        return ['ok' => false, 'reason' => 'system', 'detail' => $what . ': ' . t('Server nicht erreichbar') . ldap_diag($conn)];
    }
    return ['ok' => false, 'reason' => 'credentials', 'detail' => $what . ': ' . ldap_error($conn)];
}

/** Verbindungstest für die Einstellungsseite (optional mit Testbenutzer und -passwort). */
function ldap_test(array $cfg, string $testUser = '', string $testPw = ''): array
{
    $cfg = ldap_cfg($cfg);
    $log = [];
    $conn = ldap_connect_cfg($cfg);
    $bindDn = (string)$cfg['bind_dn'];
    if ($bindDn !== '') {
        if (!@ldap_bind($conn, $bindDn, (string)$cfg['bind_password'])) {
            throw new RuntimeException(t('Bind als {d} fehlgeschlagen:', ['d' => $bindDn]) . ' ' . ldap_error($conn) . ldap_diag($conn));
        }
        $log[] = '✓ ' . t('Verbindung und Bind als {d}', ['d' => $bindDn]);
    } elseif ($testUser !== '' && $testPw !== '') {
        $n = ldap_names($cfg, $testUser);
        if (!@ldap_bind($conn, $n['upn'], $testPw)) {
            $f = ldap_bind_failure($conn, t('Bind als') . ' ' . $n['upn']);
            throw new RuntimeException($f['detail']);
        }
        $log[] = '✓ ' . t('Verbindung und Bind direkt als {d} (ohne Dienstkonto)', ['d' => $n['upn']]);
    } else {
        $log[] = '• ' . t('Kein Dienstkonto – für den Test Benutzer UND Passwort angeben');
        @ldap_unbind($conn);
        return $log;
    }
    if (!empty($cfg['ca_file'])) {
        $log[] = '✓ ' . t('CA-Datei') . ' ' . $cfg['ca_file'];
    }
    if ($testUser !== '') {
        $names = ldap_names($cfg, $testUser);
        $e = ldap_find_user($conn, $cfg, $names);
        if ($e === null) {
            $log[] = '✗ ' . t('Benutzer „{u}“ nicht (eindeutig) gefunden – Filter: {f}', ['u' => $testUser, 'f' => ldap_build_filter($cfg, $names)]);
        } else {
            $log[] = '✓ ' . t('Gefunden:') . ' ' . $e['dn'];
            $log[] = '  ' . t('Anzeigename') . ': ' . (ldap_first($e, 'displayName') ?: '–') . ' · ' . t('E-Mail') . ': ' . (ldap_first($e, 'mail') ?: '–');
            if (($cfg['mode'] ?? 'ad') === 'ad') {
                $p = ldap_ad_account_problem($e);
                $log[] = ($p === null ? '✓ ' . t('Konto aktiv') : '✗ ' . $p) . ' (userAccountControl ' . ldap_first($e, 'userAccountControl') . ')';
            }
            $canon = ldap_first($e, 'sAMAccountName') ?: ldap_first($e, 'uid') ?: $names['sam'];
            foreach (['allowed_group' => t('Zugangsgruppe'), 'admin_group' => t('Gruppe → Portal-Admin'), 'operator_group' => t('Gruppe → Power-User')] as $k => $label) {
                if (trim((string)$cfg[$k]) === '') {
                    continue;
                }
                $dn = ldap_group_dn($conn, $cfg, (string)$cfg[$k]);
                if ($dn === null) {
                    $log[] = '✗ ' . t('{l} „{g}“ nicht gefunden', ['l' => $label, 'g' => $cfg[$k]]);
                    continue;
                }
                $in = ldap_in_group($conn, $cfg, $e, (string)$cfg[$k], $canon);
                $log[] = ($in ? '✓ ' . t('Mitglied:') . ' ' : '• ' . t('kein Mitglied:') . ' ') . $label . ' (' . $dn . ')';
            }
            if ($testPw !== '' && $bindDn !== '') {
                $ok = @ldap_bind($conn, (string)$e['dn'], $testPw);
                $log[] = $ok ? '✓ ' . t('Passwort des Testbenutzers korrekt') : '✗ ' . t('Passwort:') . ' ' . ldap_bind_failure($conn, 'Bind')['detail'];
            }
        }
    }
    @ldap_unbind($conn);
    return $log;
}
