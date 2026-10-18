<?php
/**
 * Allgemeiner SQL-Datei-Speicher für weitere Bereiche (Switches, WAN).
 *
 * Eine Definition beschreibt Datei, Namensraum (für die Zeilen-Hashes),
 * Siegel-Schlüssel, Tabellen und Indizes. Gleiche Sicherung wie bei den
 * Netzwerken: HMAC je Zeile, HMAC je Datei, SHA-256-Siegel, Schreibschutz bei
 * Abweichung.
 */

declare(strict_types=1);

/** Registrierte Speicher: key => def (wird von switches.php / wan.php gefüllt). */
function store_defs(array $add = []): array
{
    static $defs = [];
    foreach ($add as $k => $d) {
        $defs[$k] = $d;
    }
    return $defs;
}

function store_def(string $key): array
{
    $d = store_defs()[$key] ?? null;
    if ($d === null) {
        throw new RuntimeException('Unbekannter Speicher: ' . $key);
    }
    return $d;
}

function store_file(string $key): string
{
    return DB_DIR . '/' . store_def($key)['file'];
}

function store_load(string $key, bool $fresh = false): array
{
    static $cache = [];
    if (isset($cache[$key]) && !$fresh) {
        return $cache[$key];
    }
    $d = store_def($key);
    $file = store_file($key);
    if (!is_file($file)) {
        return $cache[$key] = array_fill_keys(array_keys($d['schema']), []) + ['sha256' => null, 'bytes' => 0, 'exists' => false,
            'parse' => ['body_hmac_ok' => true, 'trailer' => null, 'foreign' => [], 'errors' => []]];
    }
    $r = sqlfile_read($file, $d['schema']);
    return $cache[$key] = $r['tables'] + ['sha256' => $r['sha256'], 'bytes' => $r['bytes'], 'parse' => $r['parse'], 'exists' => true];
}

function store_put(string $key, array &$st, string $table, array $row): int
{
    $d = store_def($key);
    $row = sqlfile_row($d['schema'], $table, $row, $st[$table], $d['ns']);
    $st[$table][$row['id']] = $row;
    return $row['id'];
}

function store_save(string $key, array &$st, ?string $by = null): void
{
    $d = store_def($key);
    $by ??= current_actor();
    $counts = [];
    $rows = 0;
    foreach (array_keys($d['schema']) as $t) {
        $counts[] = count($st[$t]) . ' ' . $t;
        $rows += count($st[$t]);
    }
    $header = [
        'Network Portal · ' . $d['title'] . ' (SQLite-Dialekt)',
        'Inhalt   : ' . implode(', ', $counts),
        'Geschrieben ' . now_str() . ' von ' . $by,
        'Jede Zeile trägt ein HMAC (row_hash), die Datei ein HMAC in der letzten Zeile.',
    ];
    $sql = sqlfile_dump($d['schema'], $st, $header, $d['indexes'] ?? []);
    sqlfile_save(store_file($key), $d['seal'], $sql, $rows, $by);
    $st['sha256'] = hash('sha256', $sql);
}

/** Ändert einen Speicher unter Sperre (mit Siegelprüfung). $fn(array &$st): mixed */
function store_write(string $key, callable $fn): mixed
{
    $d = store_def($key);
    $lock = sqlfile_lock('store-' . $key);
    try {
        $st = store_load($key, true);
        if ($st['exists']) {
            [$ok, $why] = sqlfile_seal_ok($d['seal'], (string)$st['sha256'], $st['parse']['body_hmac_ok']);
            if (!$ok) {
                audit('net_write_blocked', ['datei' => $d['file'], 'grund' => $why], null, 'denied', ['type' => $key, 'label' => $d['file']]);
                throw new StoreWriteBlocked(t('Schreibschutz für {f}: {w}. Ein Portal-Admin muss die Abweichung unter „Integrität“ prüfen und neu versiegeln.', ['f' => $d['file'], 'w' => $why]));
            }
        }
        $result = $fn($st);
        store_save($key, $st);
        store_load($key, true);
        return $result;
    } finally {
        sqlfile_unlock($lock);
    }
}

function store_row_ok(string $key, string $table, array $row): bool
{
    return sqlfile_row_valid(store_def($key)['ns'], $table, $row);
}

/** Integritätsprüfung eines Speichers (null, wenn die Datei noch nicht existiert). */
function store_check(string $key): ?array
{
    $d = store_def($key);
    $st = store_load($key, true);
    if (!$st['exists']) {
        return null;
    }
    $res = integrity_check_file($d['seal'], $d['ns'], $st, array_intersect_key($st, $d['schema']),
        static fn($t, $r) => $t . ' #' . $r['id'] . ' ' . (string)($r['name'] ?? $r['port'] ?? $r['vid'] ?? ''));
    $res['slug'] = '@' . $key;
    $res['name'] = t($d['title']);
    $res['file'] = $d['file'];
    return $res;
}

/** Neu versiegeln (optional Zeilen-Hashes neu berechnen). */
function store_reseal(string $key, bool $rows): void
{
    $d = store_def($key);
    $lock = sqlfile_lock('store-' . $key);
    try {
        $st = store_load($key, true);
        if ($rows) {
            foreach (array_keys($d['schema']) as $t) {
                foreach ($st[$t] as $row) {
                    store_put($key, $st, $t, $row);
                }
            }
        }
        store_save($key, $st);
        store_load($key, true);
    } finally {
        sqlfile_unlock($lock);
    }
}

// --- Geheimnisse (z. B. PPPoE-Passwort) verschlüsselt ablegen ---------------------------------

function secret_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $key = integrity_key('secret-box');
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . (string)$ct);
}

function secret_decrypt(?string $enc): ?string
{
    if ($enc === null || $enc === '' || !str_starts_with($enc, 'v1:')) {
        return null;
    }
    $raw = base64_decode(substr($enc, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', integrity_key('secret-box'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pt === false ? null : $pt;
}
