<?php
/**
 * Audit-Log (sehr detailliert, manipulationssicher).
 *
 * Jeder Eintrag hält fest: Zeit mit Mikrosekunden + Zeitzone, Anfrage-ID,
 * Benutzer, Anmeldeart, Rolle, IP, User-Agent, Sitzungs-Kennung (gehasht),
 * HTTP-Methode + Pfad, Aktion, Kategorie, Ergebnis, betroffenes Netzwerk und
 * Objekt sowie Details (bei Änderungen: alter → neuer Wert je Feld, bei
 * Löschungen: kompletter Datensatz, bei Ping/SSH: Ziel, Befehl, Exit-Code,
 * Ausgabe-Hash).
 *
 * Hash-Kette: hash = HMAC-SHA256(Schlüssel, prev_hash + Eintrag). Wer einen
 * Eintrag ändert, löscht oder einschiebt, bricht die Kette ab dieser Stelle –
 * das zeigt die Integritätsprüfung. Der Kopf der Kette steht zusätzlich in
 * meta.audit_head, damit auch das Abschneiden der letzten Einträge auffällt.
 *
 * Aufbewahrung: Einträge älter als audit.retention_days (Standard 90 Tage)
 * löscht das Portal automatisch einmal täglich. Vorher wird der zu löschende
 * Abschnitt vollständig geprüft. Der letzte gelöschte Eintrag wird als
 * signierter Anker (meta.audit_anchor, HMAC) festgehalten – ab dort beginnt
 * die Kette. Ein gefälschter Anker oder heimlich gelöschte Einträge fallen
 * weiterhin auf. meta.audit_verified merkt sich (ebenfalls signiert), bis wohin
 * die Kette zuletzt geprüft wurde; das Audit-Log prüft dann nur neue Einträge.
 */

declare(strict_types=1);

const AUDIT_CATEGORIES = [
    'login' => 'Anmeldung', 'logout' => 'Anmeldung', 'twofa' => 'Anmeldung', 'setup' => 'Anmeldung', 'csrf' => 'Sicherheit',
    'net' => 'Netzwerk', 'host' => 'Host', 'service' => 'Dienst',
    'ping' => 'Werkzeug', 'scan' => 'Werkzeug', 'svccheck' => 'Werkzeug', 'ssh' => 'SSH',
    'user' => 'Benutzer', 'key' => 'YubiKey', 'otp' => 'YubiKey', 'account' => 'Benutzer',
    'settings' => 'Einstellungen', 'integrity' => 'Integrität', 'seal' => 'Integrität',
    'iperf' => 'Werkzeug', 'detect' => 'Werkzeug', 'sys' => 'System', 'pam' => 'System', 'ldap' => 'System', 'icon' => 'Einstellungen',
    'export' => 'Export', 'download' => 'Export', 'access' => 'Sicherheit', 'audit' => 'Audit',
    'master' => 'Master', 'term' => 'SSH', 'caddy' => 'Werkzeug', 'switch' => 'Switch', 'wan' => 'WAN',
];

const AUDIT_SECRET_KEYS = ['password', 'pw', 'pw2', 'password2', 'bind_password', 'secret_key', 'otp', 'csrf', 'new_password', 'secret'];

function audit_category(string $action): string
{
    $prefix = explode('_', $action)[0];
    return AUDIT_CATEGORIES[$prefix] ?? 'Sonstiges';
}

function audit_scrub(mixed $v): mixed
{
    if (!is_array($v)) {
        return is_string($v) ? mb_substr($v, 0, 4000) : $v;
    }
    $out = [];
    foreach ($v as $k => $x) {
        $out[$k] = is_string($k) && in_array(strtolower($k), AUDIT_SECRET_KEYS, true) ? '***' : audit_scrub($x);
    }
    return $out;
}

/**
 * Schreibt einen Audit-Eintrag.
 * $ctx: ['net' => slug, 'type' => host|service|network|user|…, 'id' => …, 'label' => …]
 */
function audit(string $action, array $detail = [], ?string $username = null, string $result = 'ok', array $ctx = []): void
{
    try {
        $u = function_exists('auth_user_soft') ? auth_user_soft() : null;
        $sid = PHP_SAPI !== 'cli' ? (string)session_id() : '';
        $uri = (string)($_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli:' . implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3)) : ''));
        $row = [
            'ts' => now_precise(),
            'request_id' => request_id(),
            'username' => $username ?? ($u['username'] ?? (PHP_SAPI === 'cli' ? 'cli:' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?') : null)),
            'auth_via' => $u['login_via'] ?? null,
            'role' => $u !== null ? ($u['super'] ? 'super' : $u['role']) : null,
            'ip' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
            'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300) ?: null,
            'session' => $sid !== '' ? substr(hash('sha256', $sid), 0, 16) : null,
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : '')),
            'uri' => mb_substr(preg_replace('/([?&]csrf=)[^&]*/', '$1***', $uri) ?? '', 0, 500),
            'action' => $action,
            'category' => audit_category($action),
            'result' => $result,
            'net' => isset($ctx['net']) ? (string)$ctx['net'] : null,
            'object_type' => isset($ctx['type']) ? (string)$ctx['type'] : null,
            'object_id' => isset($ctx['id']) ? (string)$ctx['id'] : null,
            'object_label' => isset($ctx['label']) ? mb_substr((string)$ctx['label'], 0, 200) : null,
            'detail' => $detail !== [] ? json_encode(audit_scrub($detail), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        ];
        $pdo = sys();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $last = $pdo->query('SELECT id, hash FROM audit ORDER BY id DESC LIMIT 1')->fetch();
            if ($last) {
                $prev = (string)$last['hash'];
            } else {
                // leere Tabelle: nach dem automatischen Löschen geht es am signierten Anker weiter
                // (eigene ID setzen, sonst würde SQLite wieder bei 1 beginnen)
                $a = audit_mark_get('audit_anchor');
                $prev = $a !== null && empty($a['bad']) ? $a['hash'] : 'GENESIS';
                if ($a !== null && empty($a['bad'])) {
                    $row = ['id' => $a['id'] + 1] + $row;
                }
            }
            $row['prev_hash'] = $prev;
            $row['hash'] = audit_hash($row, $prev);
            $cols = array_keys($row);
            $pdo->prepare('INSERT INTO audit (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($row));
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO meta (mkey, mvalue) VALUES ('audit_head', ?) ON CONFLICT(mkey) DO UPDATE SET mvalue = excluded.mvalue")
                ->execute([$id . ':' . $row['hash']]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('netportal audit: ' . $e->getMessage() . ' (' . $action . ')');
    }
}

/** Kanonische Form eines Eintrags für die Hash-Kette. */
function audit_hash(array $row, string $prev): string
{
    $fields = ['ts', 'request_id', 'username', 'auth_via', 'role', 'ip', 'user_agent', 'session', 'method', 'uri', 'action', 'category', 'result', 'net', 'object_type', 'object_id', 'object_label', 'detail'];
    $canon = [];
    foreach ($fields as $f) {
        $canon[$f] = isset($row[$f]) ? (string)$row[$f] : null;
    }
    return hash_hmac('sha256', $prev . "\n" . json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), integrity_key('audit'));
}

/** Signierter Merkpunkt in meta ("id:hash:hmac"). null = nicht gesetzt, ['bad' => true] = verfälscht. */
function audit_mark_get(string $name): ?array
{
    $v = meta_get($name);
    if ($v === null) {
        return null;
    }
    if (!preg_match('/^(\d+):([0-9a-f]{64}|GENESIS):([0-9a-f]{64})$/', $v, $m)
        || !hash_equals(hash_hmac('sha256', $name . "\n" . $m[1] . ':' . $m[2], integrity_key('audit')), $m[3])) {
        return ['bad' => true];
    }
    return ['id' => (int)$m[1], 'hash' => $m[2]];
}

function audit_mark_set(string $name, int $id, string $hash): void
{
    meta_set($name, $id . ':' . $hash . ':' . hash_hmac('sha256', $name . "\n" . $id . ':' . $hash, integrity_key('audit')));
}

/** Prüft die Kette von ($fromId, $prev) bis $toId (null = Ende). */
function audit_chain_walk(int $fromId, string $prev, ?int $toId = null): array
{
    $n = 0;
    $lastId = $fromId;
    $st = $toId === null
        ? sys()->prepare('SELECT * FROM audit WHERE id > ? ORDER BY id')
        : sys()->prepare('SELECT * FROM audit WHERE id > ? AND id <= ? ORDER BY id');
    $st->execute($toId === null ? [$fromId] : [$fromId, $toId]);
    while ($r = $st->fetch()) {
        $n++;
        if ((string)$r['prev_hash'] !== $prev) {
            return ['ok' => false, 'checked' => $n, 'last_id' => $lastId, 'prev' => $prev, 'broken_at' => (int)$r['id'],
                'message' => t('Kette unterbrochen vor Eintrag #{id} (Eintrag davor gelöscht oder eingeschoben)', ['id' => $r['id']])];
        }
        if (!hash_equals(audit_hash($r, $prev), (string)$r['hash'])) {
            return ['ok' => false, 'checked' => $n, 'last_id' => $lastId, 'prev' => $prev, 'broken_at' => (int)$r['id'],
                'message' => t('Eintrag #{id} wurde nachträglich verändert', ['id' => $r['id']])];
        }
        $prev = (string)$r['hash'];
        $lastId = (int)$r['id'];
    }
    return ['ok' => true, 'checked' => $n, 'last_id' => $lastId, 'prev' => $prev, 'broken_at' => null, 'message' => ''];
}

/** Anfang der Kette: GENESIS oder der signierte Anker nach dem automatischen Löschen. */
function audit_chain_start(): array
{
    $a = audit_mark_get('audit_anchor');
    if ($a === null) {
        return ['ok' => true, 'id' => 0, 'hash' => 'GENESIS'];
    }
    if (!empty($a['bad'])) {
        return ['ok' => false, 'id' => 0, 'hash' => 'GENESIS'];
    }
    return ['ok' => true, 'id' => $a['id'], 'hash' => $a['hash']];
}

/**
 * Prüft die Kette. $full = false: nur Einträge nach dem zuletzt geprüften
 * Merkpunkt (schnell, für das Audit-Log). Liefert ['ok', 'count', 'checked', 'broken_at', 'message'].
 */
function audit_verify(bool $full = true): array
{
    $count = (int)sys_val('SELECT COUNT(*) FROM audit');
    $start = audit_chain_start();
    if (!$start['ok']) {
        return ['ok' => false, 'count' => $count, 'checked' => 0, 'broken_at' => (int)(sys_val('SELECT MIN(id) FROM audit') ?? 0) ?: null,
            'message' => t('Anker der Kette ist ungültig (meta.audit_anchor verändert)')];
    }
    $fromId = $start['id'];
    $prev = $start['hash'];
    $incremental = false;
    if (!$full) {
        $cp = audit_mark_get('audit_verified');
        if ($cp !== null && empty($cp['bad']) && $cp['id'] > $fromId) {
            $h = sys_val('SELECT hash FROM audit WHERE id = ?', [$cp['id']]);
            if ($h !== null && hash_equals($cp['hash'], (string)$h)) {
                $fromId = $cp['id'];
                $prev = $cp['hash'];
                $incremental = true;
            }
        }
    }
    $r = audit_chain_walk($fromId, $prev);
    if (!$r['ok']) {
        return ['ok' => false, 'count' => $count, 'checked' => $r['checked'], 'broken_at' => $r['broken_at'], 'message' => $r['message']];
    }
    $head = meta_get('audit_head');
    if ($head !== null && $head !== $r['last_id'] . ':' . $r['prev']) {
        return ['ok' => false, 'count' => $count, 'checked' => $r['checked'], 'broken_at' => $r['last_id'],
            'message' => t('Ende der Kette passt nicht zum gespeicherten Kopf – Einträge am Ende wurden entfernt')];
    }
    if ($r['last_id'] > 0 && $r['prev'] !== 'GENESIS') {
        try {
            audit_mark_set('audit_verified', $r['last_id'], $r['prev']);
        } catch (Throwable $e) {
            // nur Merkpunkt – Prüfergebnis bleibt gültig
        }
        if ($full) {
            meta_set('audit_full_verified_at', now_str());
        }
    }
    $msg = t('{n} Einträge, Kette lückenlos', ['n' => fmt_num($count)]);
    if ($incremental) {
        $msg .= ' · ' . t('{m} neue geprüft', ['m' => fmt_num($r['checked'])]);
    }
    if ($start['id'] > 0) {
        $msg .= ' · ' . t('ältere Einträge automatisch gelöscht (bis #{id})', ['id' => $start['id']]);
    }
    return ['ok' => true, 'count' => $count, 'checked' => $r['checked'], 'broken_at' => null, 'message' => $msg];
}

/** Aufbewahrungsdauer in Tagen (0 = nie löschen). */
function audit_retention_days(): int
{
    return max(0, min(3650, (int)setting('audit.retention_days', 90)));
}

/**
 * Löscht Audit-Einträge (und SSH-Aufzeichnungen), die älter als die
 * Aufbewahrungsdauer sind. Der zu löschende Abschnitt wird vorher vollständig
 * geprüft – ist er verfälscht, wird nichts gelöscht.
 * $maxSeconds begrenzt die Laufzeit (Web: wenige Sekunden, CLI: unbegrenzt).
 */
function audit_prune(?int $days = null, float $maxSeconds = 0.0, int $batch = 100000): array
{
    $days ??= audit_retention_days();
    $out = ['deleted' => 0, 'recordings' => 0, 'until_id' => null, 'error' => null, 'more' => false, 'days' => $days];
    if ($days <= 0) {
        return $out;
    }
    $t0 = microtime(true);
    $cutoff = (new DateTimeImmutable('now'))->modify('-' . $days . ' days');
    $cut = $cutoff->format('Y-m-d H:i:s');
    $pdo = sys();
    while (true) {
        $start = audit_chain_start();
        if (!$start['ok']) {
            $out['error'] = t('Anker der Kette ist ungültig (meta.audit_anchor verändert)');
            break;
        }
        $maxId = (int)(sys_val('SELECT MAX(id) FROM audit WHERE ts < ? AND id > ?', [$cut, $start['id']]) ?? 0);
        if ($maxId <= $start['id']) {
            break;
        }
        $lim = sys_val('SELECT id FROM audit WHERE id > ? AND id <= ? ORDER BY id LIMIT 1 OFFSET ' . ($batch - 1), [$start['id'], $maxId]);
        if ($lim !== null && (int)$lim < $maxId) {
            $maxId = (int)$lim;
            $out['more'] = true;
        } else {
            $out['more'] = false;
        }
        // den zu löschenden Abschnitt vollständig prüfen – Verfälschtes wird nicht „weggeräumt“
        $w = audit_chain_walk($start['id'], $start['hash'], $maxId);
        if (!$w['ok']) {
            $out['error'] = $w['message'];
            break;
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $n = sys_exec('DELETE FROM audit WHERE id > ? AND id <= ?', [$start['id'], $maxId]);
            audit_mark_set('audit_anchor', $maxId, $w['prev']);
            $cp = audit_mark_get('audit_verified');
            if ($cp === null || !empty($cp['bad']) || $cp['id'] < $maxId) {
                audit_mark_set('audit_verified', $maxId, $w['prev']);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            $out['error'] = $e->getMessage();
            break;
        }
        $out['deleted'] += $n;
        $out['until_id'] = $maxId;
        if (!$out['more'] || ($maxSeconds > 0 && microtime(true) - $t0 > $maxSeconds)) {
            break;
        }
    }
    // SSH-Aufzeichnungen derselben Aufbewahrung unterwerfen
    foreach (glob(DB_DIR . '/terminal/*.log') ?: [] as $f) {
        if (@filemtime($f) < $cutoff->getTimestamp() && @unlink($f)) {
            $out['recordings']++;
        }
    }
    if ($out['deleted'] > 0) {
        try {
            $pdo->exec('PRAGMA incremental_vacuum');
        } catch (Throwable $e) {
            // nur bei auto_vacuum=INCREMENTAL wirksam
        }
    }
    if ($out['deleted'] > 0 || $out['recordings'] > 0 || $out['error'] !== null) {
        audit('audit_prune', ['aufbewahrung_tage' => $days, 'aelter_als' => $cut, 'geloescht' => $out['deleted'], 'bis_eintrag' => $out['until_id'],
            'aufzeichnungen_geloescht' => $out['recordings'], 'weitere_offen' => $out['more'], 'fehler' => $out['error']], PHP_SAPI === 'cli' ? null : 'system',
            $out['error'] !== null ? 'fail' : 'ok', ['type' => 'audit', 'label' => '_system.sqlite']);
    }
    return $out;
}

/**
 * Tägliche Wartung (Web: nach der Antwort, CLI: am Ende eines Befehls).
 * Läuft höchstens einmal pro Tag; der erste Aufruf des Tages „gewinnt“.
 */
function audit_maintenance(): void
{
    try {
        if (!is_file(SYS_DB_FILE) || audit_retention_days() === 0) {
            return;
        }
        $today = date('Y-m-d');
        $pdo = sys();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $last = $pdo->query("SELECT mvalue FROM meta WHERE mkey = 'audit_prune_at'")->fetchColumn();
            $due = $last === false || substr((string)$last, 0, 10) !== $today;
            if ($due) {
                $pdo->prepare("INSERT INTO meta (mkey, mvalue) VALUES ('audit_prune_at', ?) ON CONFLICT(mkey) DO UPDATE SET mvalue = excluded.mvalue")->execute([now_str()]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        if ($due) {
            audit_prune(null, PHP_SAPI === 'cli' ? 0.0 : 5.0);
        }
    } catch (Throwable $e) {
        error_log('netportal audit maintenance: ' . $e->getMessage());
    }
}

/** Täglich einmal true je Schlüssel (z. B. Tageszusammenfassung eines cron-Jobs). */
function daily_once(string $key): bool
{
    try {
        $today = date('Y-m-d');
        $pdo = sys();
        $pdo->exec('BEGIN IMMEDIATE');
        $last = $pdo->prepare('SELECT mvalue FROM meta WHERE mkey = ?');
        $last->execute(['daily_' . $key]);
        $v = $last->fetchColumn();
        $due = $v === false || (string)$v !== $today;
        if ($due) {
            $pdo->prepare('INSERT INTO meta (mkey, mvalue) VALUES (?, ?) ON CONFLICT(mkey) DO UPDATE SET mvalue = excluded.mvalue')->execute(['daily_' . $key, $today]);
        }
        $pdo->exec('COMMIT');
        return $due;
    } catch (Throwable $e) {
        try {
            sys()->exec('ROLLBACK');
        } catch (Throwable $x) {
        }
        return false;
    }
}

/** Feldweiser Vergleich für Änderungs-Einträge: [feld => [alt, neu]] */
function audit_diff(array $before, array $after, array $ignore = ['updated_at', 'updated_by', 'row_hash']): array
{
    $d = [];
    foreach ($after as $k => $v) {
        if (in_array($k, $ignore, true)) {
            continue;
        }
        $old = $before[$k] ?? null;
        if ((string)($old ?? '') !== (string)($v ?? '')) {
            $d[$k] = [$old, $v];
        }
    }
    return $d;
}

const AUDIT_ACTION_LABELS = [
    'login_success' => 'Anmeldung erfolgreich', 'login_failure' => 'Anmeldung fehlgeschlagen', 'logout' => 'Abmeldung',
    'twofa_success' => '2. Faktor bestätigt', 'twofa_failure' => '2. Faktor fehlgeschlagen', 'twofa_required' => '2. Faktor angefordert',
    'setup' => 'Ersteinrichtung', 'csrf_reject' => 'CSRF abgewiesen', 'access_denied' => 'Zugriff verweigert',
    'net_create' => 'Netzwerk angelegt', 'net_update' => 'Netzwerk geändert', 'net_delete' => 'Netzwerk gelöscht',
    'net_view' => 'Netzwerk angesehen', 'net_write_blocked' => 'Schreiben blockiert (Integrität)',
    'host_create' => 'Host angelegt', 'host_update' => 'Host geändert', 'host_delete' => 'Host gelöscht', 'host_view' => 'Host angesehen',
    'service_create' => 'Dienst angelegt', 'service_update' => 'Dienst geändert', 'service_delete' => 'Dienst gelöscht',
    'ping_host' => 'Ping', 'ping_target' => 'Ping (frei)', 'ping_network' => 'Netzwerk gepingt',
    'scan_ports' => 'Dienst-Erkennung', 'svccheck' => 'Dienste geprüft',
    'ssh_banner' => 'SSH-Banner/Fingerprint', 'ssh_exec' => 'SSH-Befehl', 'ssh_exec_denied' => 'SSH-Befehl verweigert', 'ssh_open' => 'SSH-Verbindung geöffnet',
    'user_create' => 'Benutzer angelegt', 'user_update' => 'Benutzer geändert', 'user_delete' => 'Benutzer gelöscht',
    'account_password' => 'Passwort geändert', 'key_register' => 'YubiKey registriert', 'key_delete' => 'YubiKey entfernt',
    'otp_register' => 'Yubico-OTP-Key registriert', 'otp_delete' => 'Yubico-OTP-Key entfernt',
    'settings_update' => 'Einstellungen geändert', 'settings_ldap_test' => 'LDAP-Test',
    'integrity_check' => 'Integritätsprüfung', 'integrity_violation' => 'Integritätsverletzung', 'seal_renew' => 'Neu versiegelt',
    'iperf_test' => 'iperf3-Test', 'detect_host' => 'MAC/Hardware ermittelt', 'sys_install' => 'Pakete installieren', 'sys_pam_helper' => 'PAM-Helfer installieren',
    'pam_test' => 'PAM-Test', 'ldap_test' => 'LDAP-Test', 'icon_import' => 'Icons importiert', 'icon_upload' => 'Icon hochgeladen', 'icon_delete' => 'Icon gelöscht',
    'svccheck_network' => 'Dienste des Netzwerks geprüft',
    'master_create' => 'Master angelegt', 'master_update' => 'Master geändert', 'master_delete' => 'Master gelöscht', 'master_sync' => 'Master abgeglichen',
    'term_open' => 'SSH-Terminal geöffnet', 'term_close' => 'SSH-Terminal beendet', 'term_denied' => 'SSH-Terminal verweigert',
    'sys_diagnose' => 'Diagnose ausgeführt',
    'caddy_download' => 'Caddyfile heruntergeladen', 'caddy_preset' => 'Caddy-Vorlage gespeichert', 'caddy_preset_delete' => 'Caddy-Vorlage gelöscht',
    'switch_create' => 'Switch angelegt', 'switch_update' => 'Switch geändert', 'switch_delete' => 'Switch gelöscht',
    'switch_port' => 'Switch-Port geändert', 'switch_apply' => 'Switch-Vorlage angewendet', 'switch_import' => 'Switch-Konfiguration importiert',
    'switch_config' => 'Switch-Konfiguration erzeugt', 'switch_tpl_save' => 'Switch-Vorlage gespeichert', 'switch_tpl_delete' => 'Switch-Vorlage gelöscht',
    'wan_create' => 'WAN-Anschluss angelegt', 'wan_update' => 'WAN-Anschluss geändert', 'wan_delete' => 'WAN-Anschluss gelöscht',
    'wan_apply' => 'WAN-Vorlage angewendet', 'wan_check' => 'WAN-Anschluss geprüft', 'wan_secret' => 'WAN-Zugangsdaten angezeigt',
    'wan_tpl_save' => 'WAN-Vorlage gespeichert', 'wan_tpl_delete' => 'WAN-Vorlage gelöscht',
    'export_csv' => 'CSV-Export', 'download_sql' => 'SQL-Datei heruntergeladen', 'audit_export' => 'Audit exportiert', 'audit_verify' => 'Audit-Kette geprüft',
    'audit_prune' => 'Audit-Log bereinigt (Aufbewahrung)',
    'ping_change' => 'Erreichbarkeit geändert', 'ping_daily' => 'Ping – Tageszusammenfassung',
    'svccheck_change' => 'Dienst-Zustand geändert', 'svccheck_daily' => 'Dienste – Tageszusammenfassung',
    'wan_change' => 'WAN-Zustand geändert', 'wan_daily' => 'WAN – Tageszusammenfassung',
];

function audit_label(string $action): string
{
    return isset(AUDIT_ACTION_LABELS[$action]) ? t(AUDIT_ACTION_LABELS[$action]) : $action;
}
