<?php
/**
 * Network Portal – Kommandozeile (als Webserver-Benutzer ausführen, z. B.
 *   sudo -u www-data php bin/netportal-cli.php check)
 * Sprache: NETPORTAL_LANG=en bzw. LANG=en_US.UTF-8 → Englisch
 *
 *   check                         komplette Integritätsprüfung (Exit-Code 1 bei Abweichung)
 *   list                          Netzwerke auflisten
 *   reseal <kurzname|@users|@masters|@switches|@wan> "<grund>" [--rows]
 *                                 Datei nach geprüfter Abweichung neu versiegeln
 *   ping [kurzname]               überwachte Hosts pingen (für cron, aktualisiert den Live-Status;
 *                                 Audit nur bei Zustandswechsel + einmal täglich eine Zusammenfassung)
 *   services [kurzname]           Dienste mit Status-Check prüfen (für cron, Audit wie bei ping)
 *   manifest                      manifest.sha256 der Programmdateien neu schreiben
 *   audit:verify                  nur die Audit-Hash-Kette prüfen (vollständig)
 *   audit:prune [--days N] [--vacuum]
 *                                 Audit-Einträge älter als die Aufbewahrung löschen
 *                                 (läuft sonst automatisch einmal täglich); --vacuum gibt Platz frei
 *   user:list                     Benutzer aus database/users.sql auflisten
 *   user:passwd <benutzer>        Passwort eines lokalen Kontos setzen (fragt nach)
 *   user:add <benutzer> [rolle]   lokales Konto anlegen (rolle: leser|poweruser|admin)
 *   user:role <benutzer> <rolle>  Rolle ändern
 *   diagnose [--log]              Selbsttest aller Funktionen (Exit-Code 1 bei Fehlern)
 *   master:list                   Master-Vorlagen auflisten
 *   master:sync [id] [--adopt]    Master mit den Netzwerken abgleichen (alle oder einer)
 *   switch:list                   Switches mit Ports, Netzwerken und Geräten auflisten
 *   switch:ports <id>             Portplan eines Switches (Port, Netzwerk, Gerät)
 *   switch:config <id>            Konfiguration (ProCurve running-config) ausgeben
 *   netmap                        Wer hängt wo: Weg jedes Eintrags bis ins Internet
 *   wan:list                      WAN-Anschlüsse auflisten (mit Vertragshinweisen)
 *   wan:check                     WAN-Anschlüsse anpingen (für cron, Exit-Code 1 bei Ausfall)
 *   demo                          Beispiel-Netzwerke anlegen (nur wenn noch keine existieren)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '2026.10.18');
define('SETTINGS_FILE', APP_ROOT . '/config/settings.json');
define('KEY_FILE', APP_ROOT . '/config/integrity.key');
define('DB_DIR', APP_ROOT . '/database');
define('SYS_DB_FILE', DB_DIR . '/_system.sqlite');
define('CACHE_DIR', APP_ROOT . '/.cache');
define('MANIFEST_FILE', APP_ROOT . '/manifest.sha256');

require APP_ROOT . '/includes/compat.php';
mb_internal_encoding('UTF-8');
compat_set_timezone();
require APP_ROOT . '/includes/bootstrap.php';
foreach ([CACHE_DIR, DB_DIR] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0770, true);
    }
}

function out(string $s = ''): void
{
    // Ausgabe in eine geschlossene Pipe (z. B. „| head“) still beenden
    if (@fwrite(STDOUT, $s . "\n") === false) {
        exit(0);
    }
}

function fail(string $s, int $code = 1): never
{
    fwrite(STDERR, $s . "\n");
    exit($code);
}

function read_password(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        system('stty -echo 2>/dev/null');
    }
    $pw = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) {
        system('stty echo 2>/dev/null');
    }
    out();
    return $pw;
}

$cmd = $argv[1] ?? 'help';
try {
    sys();
    integrity_master_key();
    users_store();
} catch (Throwable $e) {
    fail(t('Fehler: {e}', ['e' => $e->getMessage()]), 2);
}

// cron-Befehle übernehmen auch die tägliche Audit-Aufbewahrung (falls niemand das Portal öffnet)
if (in_array($cmd, ['check', 'ping', 'services', 'wan:check'], true)) {
    register_shutdown_function('audit_maintenance');
}

try {
    switch ($cmd) {
        case 'check':
            $r = integrity_run_all();
            $files = $r['nets'];
            if (isset($r['users'])) {
                $extra = [];
                foreach ($r['stores'] as $sr) {
                    $extra[$sr['file']] = $sr;
                }
                $files = ['users.sql' => $r['users']] + ($r['masters'] !== null ? ['masters.sql' => $r['masters']] : []) + $extra + $files;
            }
            foreach ($files as $slug => $n) {
                out(($n['ok'] ? '[ OK ] ' : '[FAIL] ') . str_pad((string)$slug, 28) . t('{a}/{b} Einträge gültig', ['a' => $n['rows_ok'], 'b' => $n['rows_total']]));
                foreach ($n['checks'] as $c) {
                    if (!$c['ok']) {
                        out('         ✗ ' . $c['label'] . ': ' . $c['detail']);
                    }
                }
                foreach (array_slice($n['bad_rows'], 0, 20) as $b) {
                    out('         ✗ ' . $b['table'] . '#' . $b['id'] . ' ' . $b['label']);
                }
            }
            foreach ($r['inventory']['unsealed'] as $f) {
                out('[FAIL] database/' . $f . ' – ' . t('ohne Siegel'));
            }
            foreach ($r['inventory']['missing'] as $f) {
                out('[FAIL] database/' . $f . ' – ' . t('Datei fehlt'));
            }
            out(($r['audit']['ok'] ? '[ OK ] ' : '[FAIL] ') . t('Audit-Kette') . ': ' . $r['audit']['message']);
            out(($r['code']['ok'] === null ? '[ -- ] ' : ($r['code']['ok'] ? '[ OK ] ' : '[FAIL] ')) . t('Programmdateien') . ': ' . $r['code']['detail']
                . ($r['code']['changed'] ? ' · ' . t('geändert') . ': ' . implode(', ', $r['code']['changed']) : ''));
            audit('integrity_check', ['ok' => $r['ok'], 'quelle' => 'cli'], null, $r['ok'] ? 'ok' : 'warn');
            out($r['ok'] ? t('Ergebnis: alles in Ordnung.') : t('Ergebnis: ABWEICHUNGEN GEFUNDEN.'));
            exit($r['ok'] ? 0 : 1);

        case 'list':
            foreach (net_list() as $n) {
                out(str_pad($n['slug'], 28) . str_pad((string)$n['kind'] . ($n['vlan_id'] !== null ? ' ' . $n['vlan_id'] : ''), 12)
                    . str_pad((string)$n['cidr'], 20) . str_pad(t('{n} Hosts', ['n' => $n['hosts']]), 10) . $n['name']);
            }
            exit(0);

        case 'reseal':
            $slug = $argv[2] ?? '';
            $reason = $argv[3] ?? '';
            $okTarget = match ($slug) {
                '@users' => is_file(users_file()),
                '@masters' => is_file(masters_file()),
                '@switches', '@wan' => is_file(store_file(substr($slug, 1))),
                default => net_valid_slug($slug) && is_file(net_file($slug)),
            };
            if (!$okTarget || mb_strlen($reason) < 5) {
                fail(t('Aufruf: reseal <kurzname|@users|@masters|@switches|@wan> "<begründung>" [--rows]'));
            }
            $after = integrity_reseal($slug, in_array('--rows', $argv, true), $reason . ' (CLI)');
            out($after['ok'] ? t('Neu versiegelt – Integrität OK.') : t('Versiegelt, es bleiben Abweichungen.'));
            exit($after['ok'] ? 0 : 1);

        case 'manifest':
            $n = integrity_write_manifest();
            audit('seal_renew', ['manifest' => $n . ' Dateien', 'quelle' => 'cli'], null);
            out(t('manifest.sha256 mit {n} Dateien geschrieben.', ['n' => $n]));
            exit(0);

        case 'ping':
            // Alle überwachten Hosts aller (oder eines) Netzwerke pingen – z. B. per cron alle 5 Minuten.
            // Audit nur, wenn ein Eintrag offline geht oder wieder erreichbar ist, plus einmal täglich eine Zusammenfassung.
            $only = $argv[2] ?? '';
            $sum = ['netzwerke' => 0, 'hosts' => 0, 'online' => 0, 'offline' => 0, 'netzwerke_mit_ausfall' => []];
            foreach (net_list() as $slug => $meta) {
                if ($only !== '' && $only !== $slug) {
                    continue;
                }
                $net = net_load($slug);
                $targets = [];
                foreach ($net['hosts'] as $h) {
                    if ((int)$h['monitor'] === 1 && ($t = host_target($h)) !== null) {
                        $targets[(int)$h['id']] = $t;
                    }
                }
                $before = status_get($slug)['hosts'];
                $res = net_ping_many($targets);
                $upd = [];
                $wentDown = $cameBack = [];
                foreach ($res as $id => $r) {
                    $upd[$id] = ['ok' => $r['ok'], 'avg' => $r['avg'], 'loss' => $r['loss'] ?? null, 'ts' => time(), 'method' => $r['method']];
                    $was = $before[$id]['ok'] ?? null;
                    $label = (string)($net['hosts'][$id]['hostname'] ?? ('#' . $id)) . ' (' . $targets[$id] . ')';
                    if ($r['ok'] && $was === false) {
                        $cameBack[] = $label;
                    } elseif (!$r['ok'] && $was !== false) {
                        $wentDown[] = $label;
                    }
                }
                status_put($slug, ['hosts' => $upd]);
                $up = count(array_filter($res, static fn($r) => $r['ok']));
                if ($wentDown !== [] || $cameBack !== []) {
                    audit('ping_change', ['offline' => array_slice($wentDown, 0, 50), 'wieder_online' => array_slice($cameBack, 0, 50),
                        'anzahl_offline' => count($wentDown), 'anzahl_wieder_online' => count($cameBack), 'jetzt_online' => $up, 'hosts' => count($targets), 'quelle' => 'cli'],
                        null, $wentDown !== [] ? 'warn' : 'ok', ['net' => $slug, 'type' => 'network', 'label' => $meta['name']]);
                }
                $sum['netzwerke']++;
                $sum['hosts'] += count($targets);
                $sum['online'] += $up;
                $sum['offline'] += count($targets) - $up;
                if ($up < count($targets) && count($sum['netzwerke_mit_ausfall']) < 50) {
                    $sum['netzwerke_mit_ausfall'][$slug] = count($targets) - $up;
                }
                out(str_pad($slug, 28) . t('{a}/{b} online', ['a' => $up, 'b' => count($targets)]));
            }
            if ($only === '' && daily_once('ping')) {
                audit('ping_daily', $sum + ['quelle' => 'cli'], null, $sum['offline'] > 0 ? 'warn' : 'ok', ['type' => 'network', 'label' => t('alle Netzwerke')]);
            }
            exit(0);

        case 'services':
            // Audit nur bei Zustandswechsel eines Dienstes plus einmal täglich eine Zusammenfassung
            $only = $argv[2] ?? '';
            $sum = ['netzwerke' => 0, 'dienste' => 0, 'verfuegbar' => 0, 'nicht_verfuegbar' => 0, 'unbekannt' => 0];
            foreach (net_list() as $slug => $meta) {
                if ($only !== '' && $only !== $slug) {
                    continue;
                }
                $net = net_load($slug);
                $before = status_get($slug)['services'];
                $upd = svc_check_network($net);
                $cnt = ['up' => 0, 'down' => 0, 'unknown' => 0];
                $wentDown = $cameBack = [];
                foreach ($upd as $sid => $r) {
                    $cnt[$r['ok'] === null ? 'unknown' : ($r['ok'] ? 'up' : 'down')]++;
                    if ($r['ok'] === null) {
                        continue;
                    }
                    $was = $before[$sid]['ok'] ?? null;
                    $sv = $net['services'][$sid] ?? [];
                    $label = (string)($sv['name'] ?? '#' . $sid) . ' @ ' . (string)($net['hosts'][(int)($sv['host_id'] ?? 0)]['hostname'] ?? '?') . ((int)($sv['port'] ?? 0) > 0 ? ':' . (int)$sv['port'] : '');
                    if ($r['ok'] && $was === false) {
                        $cameBack[] = $label;
                    } elseif (!$r['ok'] && $was !== false) {
                        $wentDown[] = $label;
                    }
                }
                if ($wentDown !== [] || $cameBack !== []) {
                    audit('svccheck_change', ['nicht_verfuegbar' => array_slice($wentDown, 0, 50), 'wieder_verfuegbar' => array_slice($cameBack, 0, 50),
                        'anzahl_nicht_verfuegbar' => count($wentDown), 'anzahl_wieder_verfuegbar' => count($cameBack), 'quelle' => 'cli'],
                        null, $wentDown !== [] ? 'warn' : 'ok', ['net' => $slug, 'type' => 'network', 'label' => $meta['name']]);
                }
                $sum['netzwerke']++;
                $sum['dienste'] += count($upd);
                $sum['verfuegbar'] += $cnt['up'];
                $sum['nicht_verfuegbar'] += $cnt['down'];
                $sum['unbekannt'] += $cnt['unknown'];
                out(str_pad($slug, 28) . t('{ok} verfügbar · {bad} nicht verfügbar · {unk} unbekannt', ['ok' => $cnt['up'], 'bad' => $cnt['down'], 'unk' => $cnt['unknown']]));
            }
            if ($only === '' && daily_once('services')) {
                audit('svccheck_daily', $sum + ['quelle' => 'cli'], null, $sum['nicht_verfuegbar'] > 0 ? 'warn' : 'ok', ['type' => 'network', 'label' => t('alle Netzwerke')]);
            }
            exit(0);

        case 'audit:verify':
            $r = audit_verify(true);
            out($r['message']);
            exit($r['ok'] ? 0 : 1);

        case 'audit:prune':
            $days = null;
            if (($i = array_search('--days', $argv, true)) !== false) {
                $days = max(0, (int)($argv[$i + 1] ?? 0));
            }
            $r = audit_prune($days, 0.0);
            meta_set('audit_prune_at', now_str());
            if ($r['error'] !== null) {
                fail('[FAIL] ' . $r['error']);
            }
            out(t('{n} Audit-Einträge gelöscht (älter als {d} Tage), {r} SSH-Aufzeichnungen', ['n' => fmt_num($r['deleted']), 'd' => $r['days'], 'r' => $r['recordings']]));
            if (in_array('--vacuum', $argv, true)) {
                $before = (int)filesize(SYS_DB_FILE);
                sys()->exec('PRAGMA auto_vacuum = INCREMENTAL');
                sys()->exec('VACUUM');
                clearstatcache();
                out(t('Datenbank verkleinert: {a} → {b}', ['a' => fmt_bytes($before), 'b' => fmt_bytes((int)filesize(SYS_DB_FILE))]));
            }
            exit(0);

        case 'user:list':
            foreach (users_list() as $u) {
                out(str_pad((string)$u['username'], 28) . mb_str_pad(role_label((string)$u['role']), 14) . mb_str_pad(source_label((string)$u['auth_source']), 20)
                    . ((int)$u['active'] === 1 ? t('aktiv') : t('gesperrt')) . (user_row_ok($u, 'users') ? '' : '  ✗ ' . t('Hash ungültig')));
            }
            exit(0);

        case 'user:role':
            $u = user_by_name($argv[2] ?? '') ?? fail(t('Benutzer nicht gefunden.'));
            $role = $argv[3] ?? '';
            if (!isset(ROLES[$role])) {
                fail(t('Rolle ungültig (leser|poweruser|admin).'));
            }
            user_update((int)$u['id'], ['role' => $role, 'updated_at' => now_str(), 'updated_by' => 'cli']);
            audit('user_update', ['aenderungen' => ['role' => [$u['role'], $role]], 'quelle' => 'cli'], null, 'ok', ['type' => 'user', 'id' => $u['id'], 'label' => $u['username']]);
            out(t('Rolle gesetzt.'));
            exit(0);

        case 'user:passwd':
        case 'user:add':
            $name = $argv[2] ?? '';
            if (!auth_valid_username($name)) {
                fail(t('Benutzername ungültig.'));
            }
            $u = user_by_name($name);
            if ($cmd === 'user:passwd' && $u === null) {
                fail(t('Benutzer nicht gefunden.'));
            }
            if ($cmd === 'user:add' && $u !== null) {
                fail(t('Benutzer existiert bereits.'));
            }
            $role = $argv[3] ?? 'leser';
            if ($cmd === 'user:add' && !isset(ROLES[$role])) {
                fail(t('Rolle ungültig (leser|poweruser|admin).'));
            }
            $pw = read_password(t('Neues Passwort: '));
            if (($e = password_policy_error($pw)) !== null) {
                fail($e);
            }
            if ($u !== null) {
                user_update((int)$u['id'], ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'auth_source' => 'local', 'active' => 1, 'updated_at' => now_str(), 'updated_by' => 'cli']);
                audit('user_update', ['aenderungen' => ['passwort' => ['***', 'per CLI neu gesetzt']]], null, 'ok', ['type' => 'user', 'id' => $u['id'], 'label' => $u['username']]);
                out(t('Passwort gesetzt.'));
            } else {
                $id = user_insert(['username' => $name, 'auth_source' => 'local', 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                    'role' => $role, 'active' => 1, 'created_at' => now_str(), 'created_by' => 'cli', 'updated_at' => now_str(), 'updated_by' => 'cli']);
                audit('user_create', ['rolle' => $role, 'quelle' => 'cli'], null, 'ok', ['type' => 'user', 'id' => $id, 'label' => $name]);
                out(t('Benutzer angelegt.'));
            }
            exit(0);

        case 'diagnose':
            $S = diag_run(true);
            out(diag_report($S, in_array('--log', $argv, true)));
            exit(diag_summary($S)['fail'] > 0 ? 1 : 0);

        case 'master:list':
            foreach (masters_list() as $m) {
                out(str_pad('#' . $m['id'], 6) . mb_str_pad((string)$m['name'], 28) . mb_str_pad(master_rule_label($m), 30) . t('{n} Netzwerke', ['n' => count(master_nets((int)$m['id']))]));
            }
            exit(0);

        case 'master:sync':
            $ids = isset($argv[2]) && ctype_digit($argv[2]) ? [(int)$argv[2]] : array_map(static fn($m) => (int)$m['id'], masters_list());
            $bad = 0;
            foreach ($ids as $mid) {
                if (master_get($mid) === null) {
                    fail(t('Master nicht gefunden.'));
                }
                foreach (master_sync($mid, in_array('--adopt', $argv, true)) as $slug => $r) {
                    out(str_pad('#' . $mid, 6) . str_pad($slug, 28) . str_pad((string)$r['ip'], 18) . master_state_done($r['state']) . ($r['detail'] !== '' ? ' – ' . $r['detail'] : ''));
                    $bad += in_array($r['state'], ['conflict', 'blocked', 'error', 'nocidr'], true) ? 1 : 0;
                }
            }
            exit($bad ? 1 : 0);

        case 'switch:list':
            $vn = vid_networks();
            foreach (switches_list() as $sw) {
                $tpl = switch_template((string)$sw['template']);
                $ports = switch_ports((int)$sw['id']);
                $vids = array_map(static fn($v) => (int)$v['vid'] . (isset($vn[(int)$v['vid']]) ? '=' . $vn[(int)$v['vid']]['slug'] : ''), switch_vlans((int)$sw['id']));
                out(str_pad('#' . $sw['id'], 6) . mb_str_pad((string)$sw['name'], 20) . mb_str_pad(($tpl['name'] ?? '?') . ' ' . ($tpl['product_no'] ?? ''), 30)
                    . str_pad((string)$sw['mgmt_ip'], 16) . str_pad(t('{n} Ports', ['n' => count($ports)]), 10) . 'VLAN ' . implode(' ', $vids));
            }
            exit(0);

        case 'switch:ports':
            $sw = switch_get((int)($argv[2] ?? 0)) ?? fail(t('Switch nicht gefunden.'));
            $vn = vid_networks();
            $names = [];
            foreach (switch_ports((int)$sw['id']) as $p) {
                $dev = '';
                if ($p['device_net'] && $p['device_host']) {
                    $names[$p['device_net']] ??= net_load((string)$p['device_net'])['hosts'];
                    $hh = $names[$p['device_net']][(int)$p['device_host']] ?? null;
                    $dev = $hh ? $hh['hostname'] . ' (' . $hh['ip'] . ')' : '#' . $p['device_host'];
                }
                $u = $p['untagged'] !== null ? (int)$p['untagged'] . (isset($vn[(int)$p['untagged']]) ? ' ' . $vn[(int)$p['untagged']]['name'] : '') : '-';
                out(str_pad((string)$p['pnum'], 5) . str_pad((int)$p['enabled'] ? 'an' : 'AUS', 5) . mb_str_pad((string)$p['name'], 18) . mb_str_pad($u, 20)
                    . str_pad((string)$p['tagged'] !== '' ? 'T:' . $p['tagged'] : '', 22) . str_pad((string)$p['trunk'], 6) . $dev);
            }
            exit(0);

        case 'switch:config':
            $sw = switch_get((int)($argv[2] ?? 0)) ?? fail(t('Switch nicht gefunden.'));
            echo switch_config((int)$sw['id']);
            exit(0);

        case 'netmap':
            $g = netmap_build();
            $tree = netmap_tree($g);
            $rows = array_filter($g['nodes'], static fn($n) => $n['type'] === 'host');
            uasort($rows, static fn($a, $b) => strnatcasecmp((string)$a['label'], (string)$b['label']));
            foreach ($rows as $id => $n) {
                $path = netmap_path_text($g, $tree, $id);
                out(mb_str_pad((string)$n['label'], 22) . mb_str_pad((string)$n['sub'], 16) . ($path !== '' ? '→ ' . $path : '(' . t('ohne Anschluss-Information') . ')'));
            }
            exit(0);

        case 'wan:list':
            $stt = wan_status_all();
            foreach (wan_links() as $w) {
                $s = $stt[(string)$w['id']] ?? null;
                out(str_pad('#' . $w['id'], 5) . mb_str_pad((string)$w['name'], 22) . mb_str_pad((string)$w['site'], 14) . mb_str_pad(trim($w['provider'] . ' ' . $w['product']), 34)
                    . str_pad((string)$w['down_mbit'] . '/' . (string)$w['up_mbit'], 11) . str_pad('P' . (int)$w['priority'], 4)
                    . ($s === null ? '?' : ($s['ok'] ? 'online ' . $s['avg'] . ' ms' : 'offline')));
                foreach (wan_warnings($w) as [$cls, $msg]) {
                    out('       ' . ($cls === 'bad' ? '✗ ' : '! ') . $msg);
                }
            }
            exit(0);

        case 'wan:check':
            // Audit nur bei Zustandswechsel eines Anschlusses plus einmal täglich eine Zusammenfassung
            $down = 0;
            $prevAll = wan_status_all();
            $sum = ['anschluesse' => 0, 'erreichbar' => 0, 'ausgefallen' => [], 'ohne_pruefziel' => 0];
            foreach (wan_links() as $w) {
                if (!in_array($w['status'], ['aktiv', 'backup'], true)) {
                    continue;
                }
                $was = $prevAll[(string)$w['id']]['ok'] ?? ($prevAll[(int)$w['id']]['ok'] ?? null);
                $r = wan_check($w);
                $down += $r['ok'] === false ? 1 : 0;
                $sum['anschluesse']++;
                if ($r['ok'] === null) {
                    $sum['ohne_pruefziel']++;
                } elseif ($r['ok']) {
                    $sum['erreichbar']++;
                } else {
                    $sum['ausgefallen'][] = (string)$w['name'];
                }
                out(($r['ok'] === null ? '[ -- ] ' : ($r['ok'] ? '[ OK ] ' : '[FAIL] ')) . mb_str_pad((string)$w['name'], 22) . ($r['target'] ?? $r['error']) . ($r['avg'] !== null ? ' · ' . $r['avg'] . ' ms' : ''));
                if ($r['ok'] !== null && (($r['ok'] && $was === false) || (!$r['ok'] && $was !== false))) {
                    audit('wan_change', ['anschluss' => $w['name'], 'ziel' => $r['target'], 'jetzt' => $r['ok'] ? 'erreichbar' : 'ausgefallen', 'avg_ms' => $r['avg'], 'verlust_pct' => $r['loss'], 'quelle' => 'cli'],
                        null, $r['ok'] ? 'ok' : 'warn', ['type' => 'wan', 'id' => $w['id'], 'label' => $w['name']]);
                }
            }
            if (daily_once('wan')) {
                audit('wan_daily', $sum + ['quelle' => 'cli'], null, $sum['ausgefallen'] !== [] ? 'warn' : 'ok', ['type' => 'wan', 'label' => t('alle Anschlüsse')]);
            }
            exit($down ? 1 : 0);

        case 'demo':
            if (net_list() !== []) {
                fail(t('Es gibt bereits Netzwerke – Demo wird nicht angelegt.'));
            }
            demo_create();
            out(t('Beispiel-Netzwerke angelegt.'));
            exit(0);

        default:
            out(trim(preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', (string)file_get_contents(__FILE__), 2)[1])[0]) ?? ''));
            exit($cmd === 'help' ? 0 : 1);
    }
} catch (StoreWriteBlocked $e) {
    fail($e->getMessage(), 3);
}

function demo_create(): void
{
    $nets = [
        ['slug' => 'mgmt-10', 'name' => 'Management', 'kind' => 'VLAN', 'vlan_id' => 10, 'cidr' => '10.10.10.0/24', 'gateway' => '10.10.10.1', 'dns' => '10.10.20.10, 10.10.20.11', 'domain' => 'firma.local', 'location' => 'RZ Keller', 'description' => 'Switches, Firewalls, iLO/iDRAC'],
        ['slug' => 'server-20', 'name' => 'Server', 'kind' => 'VLAN', 'vlan_id' => 20, 'cidr' => '10.10.20.0/24', 'gateway' => '10.10.20.1', 'dns' => '10.10.20.10, 10.10.20.11', 'dhcp_range' => '', 'domain' => 'firma.local', 'location' => 'RZ Keller', 'description' => 'Hypervisor, DC, Fileserver'],
        ['slug' => 'clients-30', 'name' => 'Clients', 'kind' => 'VLAN', 'vlan_id' => 30, 'cidr' => '10.10.30.0/23', 'gateway' => '10.10.30.1', 'dns' => '10.10.20.10', 'dhcp_range' => '10.10.30.100 - 10.10.31.200', 'domain' => 'firma.local', 'location' => 'Büro 1. OG'],
        ['slug' => 'produktion-ot', 'name' => 'Produktion', 'kind' => 'OT', 'vlan_id' => 50, 'cidr' => '10.50.0.0/24', 'gateway' => '10.50.0.1', 'location' => 'Halle 2', 'description' => 'SPS, HMI, Sensorik – nur aus MGMT erreichbar'],
        ['slug' => 'gaeste-wlan', 'name' => 'Gäste-WLAN', 'kind' => 'WLAN', 'vlan_id' => 90, 'cidr' => '192.168.90.0/24', 'gateway' => '192.168.90.1', 'dns' => '9.9.9.9', 'dhcp_range' => '192.168.90.10 - 192.168.90.250', 'description' => 'Isoliert, nur Internet'],
        ['slug' => 'dmz', 'name' => 'DMZ', 'kind' => 'DMZ', 'vlan_id' => 100, 'cidr' => '172.16.100.0/28', 'gateway' => '172.16.100.1', 'description' => 'Reverse-Proxy, Mail-Relay'],
    ];
    // [IP, Name, Art, OS, Hersteller, Modell, Icon, [[Dienst, Port, Proto, Status-Check]]]
    $hosts = [
        'mgmt-10' => [
            ['127.0.0.1', 'localhost (Portal)', 'Server', 'Debian 13', 'Dell', 'R650', 'b:debian', [['SSH', 22], ['HTTPS', 443], ['iperf3', 5201, 'tcp', 0]]],
            ['10.10.10.1', 'fw01.firma.local', 'Firewall', 'OPNsense 25.7', 'Deciso', 'DEC2750', 'b:opnsense', [['HTTPS', 443], ['SSH', 22]]],
            ['10.10.10.2', 'sw-core01', 'Switch', 'Cisco IOS-XE 17', 'Cisco', 'C9300-48P', 'g:switch', [['SSH', 22], ['SNMP', 161, 'udp']]],
            ['10.10.10.3', 'sw-access01', 'Switch', 'ProCurve R.11.122', 'HP', 'ProCurve 2610-24 (J9085A)', 'g:switch', [['SSH', 22], ['SNMP', 161, 'udp']]],
            ['10.10.10.4', 'sw-halle2', 'Switch', 'ProCurve Q.11.17', 'HP', 'ProCurve 2510-24 (J9019B)', 'g:switch', [['SSH', 22], ['SNMP', 161, 'udp']]],
            ['10.10.10.21', 'idrac-pve01', 'BMC / iLO / IPMI', 'iDRAC 9', 'Dell', 'iDRAC', 'g:bmc', [['HTTPS', 443], ['IPMI', 623, 'udp', 0]]],
            ['10.10.10.40', 'usv01', 'USV', 'NMC3', 'APC', 'Smart-UPS 3000', 'g:ups', [['HTTPS', 443]]],
        ],
        'server-20' => [
            ['10.10.20.5', 'pve01.firma.local', 'Hypervisor', 'Proxmox VE 9', 'Dell', 'R650', 'b:proxmox', [['Proxmox', 8006], ['SSH', 22]]],
            ['10.10.20.6', 'pve02.firma.local', 'Hypervisor', 'Proxmox VE 9', 'Dell', 'R650', 'b:proxmox', [['Proxmox', 8006], ['SSH', 22]]],
            ['10.10.20.10', 'dc01.firma.local', 'Server', 'Windows Server 2025', 'VM', '', 'g:server', [['LDAPS', 636], ['Kerberos', 88], ['DNS', 53, 'udp'], ['RDP', 3389]]],
            ['10.10.20.11', 'dc02.firma.local', 'Server', 'Windows Server 2025', 'VM', '', 'g:server', [['LDAPS', 636], ['Kerberos', 88], ['DNS', 53, 'udp']]],
            ['10.10.20.20', 'fs01.firma.local', 'NAS / Storage', 'TrueNAS SCALE', 'iXsystems', 'X20', 'b:truenas', [['SMB', 445], ['HTTPS', 443]]],
            ['10.10.20.30', 'web01.firma.local', 'VM', 'Ubuntu 24.04 LTS', 'VM', '', 'b:ubuntu', [['HTTPS', 443], ['SSH', 22]]],
            ['10.10.20.31', 'proxy-int', 'VM', 'Linux Mint 22', 'VM', '', 'b:linuxmint', [['Caddy', 443], ['HTTP', 80, 'tcp', 0]]],
        ],
        'clients-30' => [
            ['10.10.30.20', 'drucker-og1', 'Drucker', 'Firmware 4.2', 'Kyocera', 'TASKalfa 3554ci', 'g:printer', [['HTTP', 80], ['IPP', 631], ['Drucker', 9100, 'tcp', 0]]],
            ['10.10.30.21', 'ap-og1-ost', 'Access Point', 'UniFi 6.6', 'Ubiquiti', 'U6-Pro', 'g:ap', []],
            ['10.10.30.22', 'ap-og1-west', 'Access Point', 'UniFi 6.6', 'Ubiquiti', 'U6-Pro', 'g:ap', []],
        ],
        'produktion-ot' => [
            ['10.50.0.10', 'sps-linie1', 'Steuerung / SPS', 'Firmware V3.1', 'Siemens', 'S7-1500', 'g:plc', [['S7comm', 102], ['HTTPS', 443, 'tcp', 0]]],
            ['10.50.0.11', 'hmi-linie1', 'Steuerung / SPS', 'WinCC Unified', 'Siemens', 'TP1200', 'g:hmi', [['VNC', 5900, 'tcp', 0]]],
            ['10.50.0.20', 'modbus-gw', 'IoT', '', 'Moxa', 'MGate 5105', 'g:sensor', [['Modbus/TCP', 502]]],
        ],
        'dmz' => [
            ['172.16.100.5', 'proxy01', 'VM', 'Debian 13', 'VM', '', 'b:caddy', [['HTTPS', 443], ['HTTP', 80]]],
        ],
    ];
    foreach ($nets as $n) {
        net_create($n);
        audit('net_create', ['daten' => $n, 'quelle' => 'demo'], null, 'ok', ['net' => $n['slug'], 'type' => 'network', 'label' => $n['name']]);
        if (!isset($hosts[$n['slug']])) {
            continue;
        }
        net_write($n['slug'], static function (array &$net) use ($hosts, $n): void {
            $mac = 0x0050569a0000 + crc32($n['slug']) % 0xffff * 16;
            foreach ($hosts[$n['slug']] as $i => [$ip, $name, $kind, $os, $vendor, $model, $icon, $svcs]) {
                $hid = net_put($net, 'hosts', ['hostname' => $name, 'ip' => $ip,
                    'mac' => $i === 2 ? MAC_NOT_FOUND : implode(':', str_split(str_pad(dechex($mac + $i), 12, '0', STR_PAD_LEFT), 2)),
                    'hw_id' => null, 'kind' => $kind, 'icon' => $icon, 'os' => $os, 'vendor' => $vendor, 'model' => $model, 'location' => $n['location'] ?? null,
                    'owner' => 'IT', 'ssh_user' => null, 'ssh_port' => null, 'iperf_port' => null,
                    'monitor' => 1, 'tags' => $i === 0 ? 'produktiv' : null, 'created_at' => now_str(), 'created_by' => 'demo', 'updated_at' => now_str(), 'updated_by' => 'demo']);
                foreach ($svcs as $s) {
                    $port = $s[1];
                    $url = in_array($port, [443, 8006], true) ? 'https://' . $ip . ($port !== 443 ? ':' . $port : '') . '/' : ($port === 80 ? 'http://' . $ip . '/' : null);
                    net_put($net, 'services', ['host_id' => $hid, 'name' => $s[0], 'proto' => $s[2] ?? 'tcp', 'port' => $port, 'url' => $url,
                        'status_check' => $s[3] ?? 1,
                        'created_at' => now_str(), 'created_by' => 'demo', 'updated_at' => now_str(), 'updated_by' => 'demo']);
                }
            }
        });
    }
    demo_infrastructure();
}

/** Beispiel-Switches (ProCurve 2610-24 / 2510-24) mit Ports, Netzwerken und Geräten sowie WAN-Anschlüsse. */
function demo_infrastructure(): void
{
    net_list(true);
    $hostId = static function (string $slug, string $name): ?int {
        foreach (net_load($slug, true)['hosts'] as $h) {
            if ($h['hostname'] === $name) {
                return (int)$h['id'];
            }
        }
        return null;
    };
    $u = 'demo';
    $now = now_str();
    $base = ['created_at' => $now, 'created_by' => $u, 'updated_at' => $now, 'updated_by' => $u];
    $set = ['ssh' => '1', 'telnet' => '0', 'web' => '0', 'stp' => '1', 'timezone' => '60', 'dst' => 'Western-Europe', 'sntp' => '10.10.20.10', 'gateway' => '10.10.10.1',
        'snmp_community' => 'np-monitor', 'snmp_rw' => '0', 'snmp_location' => '', 'snmp_contact' => 'it@firma.local', 'syslog' => '10.10.20.30', 'managers' => '10.10.10.0/24', 'console_timeout' => '15'];
    $sw = [
        ['name' => 'sw-access01', 'tpl' => 'custom', 'ip' => '10.10.10.3', 'loc' => 'Büro 1. OG · Verteiler V1', 'serial' => 'CN84DZ1234', 'fw' => 'R.11.122',
            'ports' => [
                '1' => ['dev' => ['clients-30', 'drucker-og1'], 'name' => 'Drucker OG1'],
                '5' => ['dev' => ['clients-30', 'ap-og1-ost'], 'name' => 'AP Ost', 'tagged' => '90'],
                '6' => ['dev' => ['clients-30', 'ap-og1-west'], 'name' => 'AP West', 'tagged' => '90'],
                '23' => ['untagged' => 10, 'name' => 'Mgmt-Notebook', 'enabled' => 1],
                '25' => ['dev' => ['mgmt-10', 'sw-core01'], 'name' => 'Uplink Core 1/1/1'],
                '26' => ['dev' => ['mgmt-10', 'sw-core01'], 'name' => 'Uplink Core 2/1/1'],
            ]],
        ['name' => 'sw-halle2', 'tpl' => 'o:J9019B', 'ip' => '10.10.10.4', 'loc' => 'Halle 2 · Schaltschrank =H2+S1', 'serial' => 'CN73BX5678', 'fw' => 'Q.11.17',
            'presets' => [['1-20', 50, '', 'Produktion'], ['21-22', 30, '', 'Büro Halle'], ['23-24', null, '', 'frei', 0], ['25-26', null, '10,30,50', 'Uplink', 1, 'Trk1']],
            'ports' => [
                '1' => ['dev' => ['produktion-ot', 'sps-linie1'], 'name' => 'SPS Linie 1'],
                '2' => ['dev' => ['produktion-ot', 'hmi-linie1'], 'name' => 'HMI Linie 1'],
                '3' => ['dev' => ['produktion-ot', 'modbus-gw'], 'name' => 'Modbus-Gateway'],
                '24' => ['untagged' => 10, 'name' => 'Service-Laptop', 'enabled' => 1],
                '25' => ['dev' => ['mgmt-10', 'sw-core01'], 'name' => 'Uplink Core 1/1/51'],
                '26' => ['dev' => ['mgmt-10', 'sw-core01'], 'name' => 'Uplink Core 1/1/52'],
            ]],
        ['name' => 'sw-core01', 'tpl' => 'core', 'ip' => '10.10.10.2', 'loc' => 'RZ Keller · Rack A1', 'serial' => 'FOC2741X0AB', 'fw' => '17.12',
            'presets' => [['1-48', null, '', 'frei', 0], ['49-50', null, '10,20,30,90', 'Uplink sw-access01', 1, 'Trk1'], ['51-52', null, '10,30,50', 'Uplink sw-halle2', 1, 'Trk2']],
            'ports' => [
                '1' => ['dev' => ['mgmt-10', 'fw01.firma.local'], 'name' => 'Firewall LAN', 'untagged' => 10, 'tagged' => '20,30,50,90,100', 'enabled' => 1],
                '2' => ['dev' => ['server-20', 'pve01.firma.local'], 'name' => 'pve01', 'untagged' => 20, 'enabled' => 1],
                '3' => ['dev' => ['server-20', 'pve02.firma.local'], 'name' => 'pve02', 'untagged' => 20, 'enabled' => 1],
                '4' => ['dev' => ['server-20', 'fs01.firma.local'], 'name' => 'fs01', 'untagged' => 20, 'enabled' => 1],
                '5' => ['dev' => ['mgmt-10', 'idrac-pve01'], 'name' => 'iDRAC pve01', 'untagged' => 10, 'enabled' => 1],
                '6' => ['dev' => ['mgmt-10', 'usv01'], 'name' => 'USV', 'untagged' => 10, 'enabled' => 1],
                '8' => ['dev' => ['dmz', 'proxy01'], 'name' => 'DMZ Proxy', 'untagged' => 100, 'enabled' => 1],
                '49' => ['dev' => ['mgmt-10', 'sw-access01']],
                '50' => ['dev' => ['mgmt-10', 'sw-access01']],
                '51' => ['dev' => ['mgmt-10', 'sw-halle2']],
                '52' => ['dev' => ['mgmt-10', 'sw-halle2']],
            ]],
    ];
    // eigene Vorlage: Kopie des Originals J9085A mit Port-Vorgaben fürs Büro
    $tid = store_write('switches', static function (array &$st) use ($set, $base): int {
        $o = SWITCH_ORIGINALS['J9085A'];
        return store_put('switches', $st, 'switch_templates', [
            'name' => 'Büro-Access ProCurve 2610-24', 'vendor' => $o['vendor'], 'model' => $o['model'], 'product_no' => $o['product_no'], 'software' => $o['software'],
            'cli' => 'procurve', 'base' => 'J9085A', 'ports' => $o['ports'], 'layout' => 'oddeven', 'console' => $o['console'], 'default_hostname' => $o['default_hostname'],
            'settings' => json_encode($set, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'port_presets' => "# Ports;untagged;tagged;Name;an/aus;Trunk\n1-18;30;;Arbeitsplatz;an\n19-20;90;;Gäste-WLAN;an\n21-22;none;;frei;aus\n23-24;10;;Management;aus\n25-26;none;10,20,30,90;Uplink Core;an;Trk1\n27-28;none;;SFP frei;aus",
            'description' => 'Kopie von J9085A: Arbeitsplätze VLAN 30, Gäste VLAN 90, Uplink als LACP-Trunk Trk1 mit allen Büro-VLANs.',
        ] + $base);
    });
    // eigene Vorlage für den Core-Switch (allgemeiner Portplan, kein ProCurve)
    $tidCore = store_write('switches', static function (array &$st) use ($base): int {
        return store_put('switches', $st, 'switch_templates', [
            'name' => 'Core 48 × 1G + 4 × SFP+ (eigene Vorlage)', 'vendor' => 'Cisco', 'model' => 'C9300-48P', 'product_no' => 'C9300-48P', 'software' => 'IOS-XE 17',
            'cli' => 'generic', 'base' => '', 'ports' => "1-48;10/100/1000Base-T PoE+;rj45\n49-52;SFP+ 10G (Uplink-Modul);sfpplus", 'layout' => 'oddeven', 'console' => 'RJ-45 / USB',
            'default_hostname' => 'Switch', 'settings' => '{}', 'port_presets' => '',
            'description' => 'Eigene Vorlage für den Core-Switch im Rechenzentrum: 48 Kupfer-Ports mit PoE+, 4 SFP+-Uplinks. Konfiguration als Portplan.',
        ] + $base);
    });
    store_write('switches', static function (array &$st) use ($sw, $set, $base, $hostId, $tid, $tidCore): void {
        foreach ($sw as $d) {
            $ref = $d['tpl'] === 'custom' ? 'c:' . $tid : ($d['tpl'] === 'core' ? 'c:' . $tidCore : $d['tpl']);
            $hid = $hostId('mgmt-10', $d['name']);
            $sid = store_put('switches', $st, 'switches', ['name' => $d['name'], 'template' => $ref, 'net' => 'mgmt-10', 'host_id' => $hid, 'mgmt_vlan' => 10,
                'mgmt_ip' => $d['ip'], 'mgmt_mask' => '255.255.255.0', 'serial' => $d['serial'], 'firmware' => $d['fw'], 'location' => $d['loc'],
                'settings' => json_encode(['hostname' => $d['name'], 'snmp_location' => $d['loc']] + $set, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'notes' => null] + $base);
            switch_apply_template($st, $sid, $ref, true, false);
            $byLabel = [];
            foreach ($st['switch_ports'] as $pid => $p) {
                if ((int)$p['switch_id'] === $sid) {
                    $byLabel[(string)$p['pnum']] = $pid;
                }
            }
            foreach ($d['presets'] ?? [] as $pr) {
                foreach (port_range_expand($pr[0]) as $l) {
                    $row = $st['switch_ports'][$byLabel[$l]];
                    store_put('switches', $st, 'switch_ports', array_merge($row, ['untagged' => $pr[1], 'tagged' => $pr[2] ?: null, 'name' => $pr[3], 'enabled' => $pr[4] ?? 1, 'trunk' => $pr[5] ?? null]));
                }
            }
            foreach ($d['ports'] as $l => $cfg) {
                $row = $st['switch_ports'][$byLabel[(string)$l]];
                if (isset($cfg['dev'])) {
                    $row['device_net'] = $cfg['dev'][0];
                    $row['device_host'] = $hostId($cfg['dev'][0], $cfg['dev'][1]);
                }
                foreach (['name', 'untagged', 'tagged', 'enabled'] as $k) {
                    if (array_key_exists($k, $cfg)) {
                        $row[$k] = $cfg[$k];
                    }
                }
                store_put('switches', $st, 'switch_ports', $row);
            }
            switch_ensure_vlans($st, $sid);
        }
    });
    audit('switch_create', ['quelle' => 'demo', 'switches' => array_column($sw, 'name')], null, 'ok', ['type' => 'switch', 'label' => 'demo']);

    $fw = $hostId('mgmt-10', 'fw01.firma.local');
    $end = static fn(string $mod): string => (new DateTimeImmutable('today'))->modify($mod)->format('Y-m-d');
    $links = [
        ['name' => 'WAN1 Glasfaser', 'template' => 'o:tk-ftth', 'site' => 'Zentrale', 'status' => 'aktiv', 'username' => '002012345678551234567890#0001@t-online.de', 'secret' => 'Demo-Passwort-123',
            'router_iface' => 'igb1', 'monitor_target' => '127.0.0.1', 'customer_no' => '5501234567', 'contract_no' => 'V-88231', 'line_id' => 'DEU.DTAG.FTTH.00412',
            'hotline' => '0800 330 1000', 'contract_start' => '2025-01-15', 'contract_end' => $end('+70 days'), 'notice' => '1 Monat', 'cost_month' => '79,95',
            'notes' => 'Demo: Prüfziel lokal, damit die Erreichbarkeit sichtbar ist.'],
        ['name' => 'WAN2 LTE-Backup', 'template' => 'o:lte-backup', 'site' => 'Zentrale', 'status' => 'backup', 'provider' => 'Vodafone', 'product' => 'Business LTE 150',
            'router_iface' => 'igb2', 'monitor_target' => '9.9.9.9', 'customer_no' => 'VF-102938', 'hotline' => '0800 172 1234', 'contract_start' => '2024-06-01',
            'contract_end' => $end('+400 days'), 'notice' => '3 Monate', 'cost_month' => '29,90'],
        ['name' => 'WAN3 Business /29', 'template' => 'o:business-static', 'site' => 'RZ Keller', 'status' => 'aktiv', 'provider' => 'Stadtwerke Netz', 'product' => 'Business Fiber 500 symmetrisch',
            'public_ip' => '203.0.113.10', 'public_net' => '203.0.113.8/29', 'gateway' => '203.0.113.9', 'ipv6_prefix' => '2001:db8:4400::/48', 'dns' => '9.9.9.9 149.112.112.112',
            'router_iface' => 'igb3', 'customer_no' => 'SW-77120', 'contract_no' => 'BF-2024-0091', 'line_id' => 'SWN-L2-00917', 'hotline' => '0800 555 7700', 'sla' => 'Entstörung 4 h, 24/7',
            'contract_start' => '2024-03-01', 'contract_end' => $end('+25 days'), 'notice' => '3 Monate', 'cost_month' => '349,00'],
        ['name' => 'Außenlager Starlink', 'template' => 'o:starlink', 'site' => 'Außenlager', 'status' => 'aktiv', 'router_iface' => 'wan', 'monitor_target' => '1.1.1.1',
            'contract_start' => '2025-09-01', 'notice' => 'monatlich', 'cost_month' => '65,00'],
    ];
    store_write('wan', static function (array &$st) use ($links, $fw, $base): void {
        store_put('wan', $st, 'wan_templates', ['name' => 'Telekom Glasfaser 500 (Firmenstandard)', 'provider' => 'Deutsche Telekom', 'product' => 'Business Glasfaser 500', 'type' => 'ftth',
            'auth' => 'pppoe', 'ipv4_mode' => 'dynamic', 'ipv6_mode' => 'pd', 'ipv6_len' => 56, 'wan_vlan' => 7, 'mtu' => 1492, 'down_mbit' => 500, 'up_mbit' => 200, 'priority' => 1,
            'dns' => '', 'user_hint' => 'Zugangsdaten aus dem Auftragsschreiben', 'modem' => 'Glasfaser-Modem 2', 'sla' => 'Entstörung nächster Werktag', 'base' => 'tk-ftth',
            'description' => 'Firmenstandard für Filialen: PPPoE auf VLAN 7, OPNsense dahinter, IPv6 /56.'] + $base);
        foreach ($links as $l) {
            $tpl = wan_template($l['template']);
            $row = wan_apply_values(wan_blank(), $tpl);
            foreach ($l as $k => $v) {
                $row[$k] = $v;
            }
            $row['secret'] = isset($l['secret']) ? secret_encrypt($l['secret']) : null;
            $row['router_net'] = $l['site'] === 'Außenlager' ? null : 'mgmt-10';
            $row['router_host'] = $l['site'] === 'Außenlager' ? null : $fw;
            unset($row['id']);
            store_put('wan', $st, 'wan_links', $row + $base);
        }
    });
    audit('wan_create', ['quelle' => 'demo', 'anschluesse' => array_column($links, 'name')], null, 'ok', ['type' => 'wan', 'label' => 'demo']);
}
