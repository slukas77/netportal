<?php
/**
 * Integritätsprüfung
 *
 * Schlüssel: zweigeteilt – Teil 1 in der System-DB (meta.key_part), Teil 2 in
 * config/integrity.key. Erst beide zusammen ergeben den HMAC-Schlüssel.
 *
 * Geprüft wird:
 *   1. je Eintrag:  row_hash = HMAC(Namensraum, Tabelle, Inhalt)
 *   2. je Datei:    HMAC in der letzten Zeile + SHA-256-Siegel in der System-DB
 *                   (alle Netzwerk-Dateien, database/users.sql, database/masters.sql)
 *   3. Bestand:     Dateien ohne Siegel / Siegel ohne Datei
 *   4. Audit-Log:   lückenlose Hash-Kette
 *   5. Programm:    SHA-256 aller Programmdateien gegen manifest.sha256
 */

declare(strict_types=1);

final class IntegrityKeyMissing extends RuntimeException
{
}

/** Später ergänzte Spalten: leer (NULL) zählen sie nicht zum Zeilen-Hash – ältere Einträge bleiben gültig. */
const ROW_HASH_OPTIONAL = [
    'hosts' => ['hw_id', 'icon', 'iperf_port', 'master_id'],
    'services' => ['status_check', 'master_svc'],
];

function integrity_master_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = KEY_FILE;
    $dbPart = meta_get('key_part');
    $filePart = is_file($file) ? trim((string)@file_get_contents($file)) : null;
    if ($dbPart === null && $filePart === null) {
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0770, true);
        }
        $filePart = bin2hex(random_bytes(32));
        if (@file_put_contents($file, $filePart . "\n", LOCK_EX) === false) {
            throw new IntegrityKeyMissing('config/integrity.key kann nicht angelegt werden.');
        }
        @chmod($file, 0600);
        $dbPart = bin2hex(random_bytes(32));
        meta_set('key_part', $dbPart);
        meta_set('key_created', now_str());
    }
    if ($dbPart === null || $filePart === null || !preg_match('/^[0-9a-f]{64}$/', (string)$filePart)) {
        throw new IntegrityKeyMissing($dbPart === null
            ? t('Schlüsselteil in der System-Datenbank fehlt – die System-DB wurde ersetzt oder zurückgesetzt.')
            : t('config/integrity.key fehlt oder ist beschädigt – aus der Sicherung zurückspielen.'));
    }
    $key = hash_hmac('sha256', hex2bin($dbPart), hex2bin($filePart), true);
    $fp = substr(hash('sha256', $key), 0, 16);
    $stored = meta_get('key_fingerprint');
    if ($stored === null) {
        meta_set('key_fingerprint', $fp);
    } elseif (!hash_equals($stored, $fp)) {
        $key = null;
        throw new IntegrityKeyMissing(t('Der Integritätsschlüssel passt nicht zur Installation (Schlüssel ausgetauscht?).'));
    }
    return $key;
}

function integrity_key(string $purpose): string
{
    return hash_hmac('sha256', 'netportal:' . $purpose, integrity_master_key(), true);
}

function integrity_key_fingerprint(): string
{
    try {
        return substr(hash('sha256', integrity_master_key()), 0, 16);
    } catch (Throwable $e) {
        return '–';
    }
}

function integrity_row_canon(array $row, string $table = ''): string
{
    unset($row['row_hash']);
    foreach (ROW_HASH_OPTIONAL[$table] ?? [] as $opt) {
        if (array_key_exists($opt, $row) && $row[$opt] === null) {
            unset($row[$opt]);
        }
    }
    ksort($row);
    $c = [];
    foreach ($row as $k => $v) {
        $c[$k] = $v === null ? null : (is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : (string)$v);
    }
    return json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function integrity_row_hash(string $ns, string $table, array $row): string
{
    return hash_hmac('sha256', $ns . "\0" . $table . "\0" . integrity_row_canon($row, $table), integrity_key('row'));
}

function integrity_file_hmac(string $body): string
{
    return hash_hmac('sha256', $body, integrity_key('file'));
}

// --- Prüfungen --------------------------------------------------------------------------------

/** Gemeinsame Prüfung einer SQL-Datei. $tables = [tabelle => [id => zeile]] */
function integrity_check_file(string $sealKey, string $ns, array $loaded, array $tables, callable $label): array
{
    $res = ['ok' => true, 'checks' => [], 'bad_rows' => [], 'rows_total' => 0, 'rows_ok' => 0, 'sha256' => $loaded['sha256'], 'bytes' => $loaded['bytes']];
    $add = static function (string $l, bool $ok, string $detail) use (&$res): void {
        $res['checks'][] = ['label' => $l, 'ok' => $ok, 'detail' => $detail];
        if (!$ok) {
            $res['ok'] = false;
        }
    };
    $seal = sys_one('SELECT * FROM seals WHERE file = ?', [$sealKey]);
    $res['seal'] = $seal;
    $p = $loaded['parse'];
    $add(t('Datei-HMAC (letzte Zeile)'), $p['body_hmac_ok'], $p['trailer'] === null ? t('fehlt') : ($p['body_hmac_ok'] ? t('gültig') : t('ungültig – Inhalt verändert')));
    if ($seal === null) {
        $add(t('Siegel (SHA-256 in System-DB)'), false, t('kein Siegel – Datei nicht über das Portal angelegt'));
    } else {
        $match = hash_equals((string)$seal['sha256'], $loaded['sha256']);
        $add(t('Siegel (SHA-256 in System-DB)'), $match, $match
            ? t('stimmt · versiegelt {d} von {u}', ['d' => fmt_datetime($seal['sealed_at']), 'u' => $seal['sealed_by']])
            : t('abweichend · erwartet {a}…, gefunden {b}…', ['a' => substr((string)$seal['sha256'], 0, 16), 'b' => substr($loaded['sha256'], 0, 16)]));
    }
    $clean = $p['errors'] === [] && $p['foreign'] === [];
    $add(t('Aufbau / Syntax'), $clean, $clean ? t('nur erlaubte Anweisungen')
        : implode(' · ', array_slice([...$p['errors'], ...array_map(static fn($s) => t('fremde Anweisung: {s}', ['s' => $s]), $p['foreign'])], 0, 5)));
    foreach ($tables as $table => $list) {
        foreach ($list as $id => $row) {
            $res['rows_total']++;
            $expect = integrity_row_hash($ns, $table, $row);
            if (is_string($row['row_hash']) && hash_equals($expect, $row['row_hash'])) {
                $res['rows_ok']++;
            } else {
                $res['bad_rows'][] = ['table' => $table, 'id' => (int)$id, 'label' => $label($table, $row), 'found' => (string)$row['row_hash'], 'expected' => $expect];
            }
        }
    }
    $add(t('Einträge (Hash je Zeile)'), $res['bad_rows'] === [], t('{a} von {b} gültig', ['a' => $res['rows_ok'], 'b' => $res['rows_total']]));
    return $res;
}

function integrity_check_net(string $slug): array
{
    try {
        $net = net_load($slug, true);
    } catch (Throwable $e) {
        return ['ok' => false, 'slug' => $slug, 'name' => $slug, 'checks' => [['label' => t('Datei lesbar'), 'ok' => false, 'detail' => $e->getMessage()]], 'bad_rows' => [], 'rows_total' => 0, 'rows_ok' => 0];
    }
    $res = integrity_check_file(net_seal_key($slug), $slug, $net, ['network' => [1 => $net['network']], 'hosts' => $net['hosts'], 'services' => $net['services']],
        static fn($t, $r) => $t === 'hosts' ? $r['hostname'] . ' ' . $r['ip'] : ($t === 'services' ? $r['name'] . ' :' . $r['port'] : (string)$r['name']));
    $res['slug'] = $slug;
    $res['name'] = (string)$net['network']['name'];
    if ((string)$net['network']['slug'] !== $slug) {
        $res['ok'] = false;
        $res['checks'][] = ['label' => t('Kurzname in der Datei'), 'ok' => false, 'detail' => '„' . $net['network']['slug'] . '“'];
    }
    $orphans = array_filter($net['services'], static fn($s) => !isset($net['hosts'][(int)$s['host_id']]));
    if ($orphans !== []) {
        $res['ok'] = false;
        $res['checks'][] = ['label' => t('Verweise'), 'ok' => false, 'detail' => t('{n} Dienst(e) ohne zugehörigen Host', ['n' => count($orphans)])];
    }
    return $res;
}

function integrity_check_users(): array
{
    $st = users_store(true);
    $res = integrity_check_file(USERS_SEAL, USERS_NS, $st, array_intersect_key($st, USERS_SCHEMA),
        static fn($t, $r) => $t === 'users' ? (string)$r['username'] : ($r['name'] ?? '') . ' (user #' . $r['user_id'] . ')');
    $res['slug'] = '@users';
    $res['name'] = t('Benutzer des Portals');
    return $res;
}

function integrity_check_masters(): ?array
{
    $st = masters_store(true);
    if (!$st['exists']) {
        return null;
    }
    $res = integrity_check_file(MASTERS_SEAL, MASTERS_NS, $st, array_intersect_key($st, MASTERS_SCHEMA),
        static fn($t, $r) => $t === 'masters' ? (string)$r['name'] : ($t === 'master_nets' ? (string)$r['net'] : (string)$r['name']) . ' (master #' . $r['master_id'] . ')');
    $res['slug'] = '@masters';
    $res['name'] = t('Master-Vorlagen');
    return $res;
}

function integrity_check_inventory(): array
{
    $files = [];
    foreach (glob(DB_DIR . '/net-*.sql') ?: [] as $f) {
        $files[basename($f)] = true;
    }
    if (is_file(users_file())) {
        $files[USERS_SEAL] = true;
    }
    if (is_file(masters_file())) {
        $files[MASTERS_SEAL] = true;
    }
    foreach (store_defs() as $key => $d) {
        if (is_file(store_file($key))) {
            $files[$d['seal']] = true;
        }
    }
    $seals = [];
    foreach (sys_all('SELECT file FROM seals') as $s) {
        $seals[$s['file']] = true;
    }
    return ['unsealed' => array_keys(array_diff_key($files, $seals)), 'missing' => array_keys(array_diff_key($seals, $files))];
}

function integrity_check_code(): array
{
    if (!is_file(MANIFEST_FILE)) {
        return ['ok' => null, 'detail' => t('manifest.sha256 fehlt – mit „php bin/netportal-cli.php manifest“ erzeugen'), 'changed' => [], 'missing' => [], 'extra' => [], 'count' => 0];
    }
    $expected = [];
    foreach (file(MANIFEST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/', $line, $m)) {
            $expected[$m[2]] = $m[1];
        }
    }
    $actual = integrity_code_files();
    $changed = $missing = [];
    foreach ($expected as $path => $hash) {
        if (!isset($actual[$path])) {
            $missing[] = $path;
        } elseif (!hash_equals($hash, hash_file('sha256', APP_ROOT . '/' . $path))) {
            $changed[] = $path;
        }
    }
    $extra = array_values(array_diff(array_keys($actual), array_keys($expected)));
    return ['ok' => $changed === [] && $missing === [] && $extra === [], 'detail' => t('{n} Dateien im Manifest', ['n' => count($expected)]),
        'changed' => $changed, 'missing' => $missing, 'extra' => $extra, 'count' => count($expected)];
}

function integrity_code_files(): array
{
    $out = [];
    foreach (['index.php', 'includes', 'assets', 'bin', 'pam-helper', 'system'] as $r) {
        $p = APP_ROOT . '/' . $r;
        if (is_file($p)) {
            $out[$r] = true;
            continue;
        }
        if (!is_dir($p)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if ($f->isFile() && $f->getFilename() !== '.htaccess' && !str_ends_with($f->getFilename(), '.o') && $f->getFilename() !== 'netportal-pam-check') {
                $out[substr($f->getPathname(), strlen(APP_ROOT) + 1)] = true;
            }
        }
    }
    ksort($out);
    return $out;
}

function integrity_write_manifest(): int
{
    $lines = [];
    foreach (array_keys(integrity_code_files()) as $path) {
        $lines[] = hash_file('sha256', APP_ROOT . '/' . $path) . '  ' . $path;
    }
    file_put_contents(MANIFEST_FILE, implode("\n", $lines) . "\n");
    return count($lines);
}

function integrity_run_all(): array
{
    $nets = [];
    $ok = true;
    foreach (array_keys(net_list()) as $slug) {
        $r = integrity_check_net($slug);
        $nets[$slug] = $r;
        $ok = $ok && $r['ok'];
    }
    $users = integrity_check_users();
    $masters = integrity_check_masters();
    $stores = [];
    foreach (array_keys(store_defs()) as $key) {
        $sr = store_check($key);
        if ($sr !== null) {
            $stores[$key] = $sr;
            $ok = $ok && $sr['ok'];
        }
    }
    $inv = integrity_check_inventory();
    $audit = audit_verify();
    $code = integrity_check_code();
    $ok = $ok && $users['ok'] && ($masters === null || $masters['ok']) && $inv['unsealed'] === [] && $inv['missing'] === [] && $audit['ok'] && $code['ok'] !== false;
    @file_put_contents(CACHE_DIR . '/integrity-last.json', (string)json_encode(['ok' => $ok, 'ts' => time()]));
    return ['ok' => $ok, 'nets' => $nets, 'users' => $users, 'masters' => $masters, 'stores' => $stores, 'inventory' => $inv, 'audit' => $audit, 'code' => $code];
}

function integrity_last(): ?array
{
    $f = CACHE_DIR . '/integrity-last.json';
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

/**
 * Neu versiegeln nach geprüfter Abweichung ($target = Netz-Kurzname, "@users", "@masters", "@switches" oder "@wan").
 * Mit $rows werden auch die Zeilen-Hashes neu berechnet (Inhalte gelten dann als echt).
 */
function integrity_reseal(string $target, bool $rows, string $reason): array
{
    if ($target === '@users') {
        $before = integrity_check_users();
        $lock = sqlfile_lock('users');
        try {
            $st = users_store(true);
            if ($rows) {
                foreach (array_keys(USERS_SCHEMA) as $t) {
                    foreach ($st[$t] as $row) {
                        users_put($st, $t, $row);
                    }
                }
            }
            users_save($st);
            users_store(true);
        } finally {
            sqlfile_unlock($lock);
        }
        $after = integrity_check_users();
        $ctx = ['type' => 'user', 'label' => 'users.sql'];
    } elseif ($target === '@masters') {
        $before = integrity_check_masters() ?? ['ok' => true, 'checks' => [], 'bad_rows' => []];
        $lock = sqlfile_lock('masters');
        try {
            $st = masters_store(true);
            if ($rows) {
                foreach (array_keys(MASTERS_SCHEMA) as $t) {
                    foreach ($st[$t] as $row) {
                        masters_put($st, $t, $row);
                    }
                }
            }
            masters_save($st);
            masters_store(true);
        } finally {
            sqlfile_unlock($lock);
        }
        $after = integrity_check_masters();
        $ctx = ['type' => 'master', 'label' => 'masters.sql'];
    } elseif (str_starts_with($target, '@') && isset(store_defs()[substr($target, 1)])) {
        $key = substr($target, 1);
        $before = store_check($key) ?? ['ok' => true, 'checks' => [], 'bad_rows' => []];
        store_reseal($key, $rows);
        $after = store_check($key);
        $ctx = ['type' => $key, 'label' => store_def($key)['file']];
    } else {
        $before = integrity_check_net($target);
        $lock = net_lock($target);
        try {
            $net = net_load($target, true);
            if ($rows) {
                net_put($net, 'network', ['slug' => $target] + $net['network']);
                foreach (['hosts', 'services'] as $t) {
                    foreach ($net[$t] as $row) {
                        net_put($net, $t, $row);
                    }
                }
            }
            net_save($net);
        } finally {
            sqlfile_unlock($lock);
        }
        $after = integrity_check_net($target);
        $ctx = ['net' => $target, 'type' => 'network', 'label' => $after['name']];
    }
    audit('seal_renew', [
        'grund' => $reason,
        'zeilen_neu_gehasht' => $rows,
        'vorher' => ['ok' => $before['ok'], 'pruefungen' => $before['checks'], 'ungueltige_zeilen' => $before['bad_rows']],
        'nachher_sha256' => $after['sha256'] ?? null,
    ], null, 'ok', $ctx);
    return $after;
}
