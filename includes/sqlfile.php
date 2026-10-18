<?php
/**
 * SQL-Datei-Speicher (gemeinsame Grundlage für Netzwerke und Benutzer).
 *
 * Jede Datei ist normales SQL (CREATE TABLE + INSERT, SQLite-Dialekt). Gelesen
 * wird sie NICHT von SQLite, sondern von einem strengen eigenen Parser: nur
 * INSERT-Werte der bekannten Tabellen zählen, alles andere wird gemeldet und
 * nie ausgeführt (kein ATTACH DATABASE o. Ä.).
 *
 * Integrität je Datei: HMAC in der letzten Zeile + SHA-256-Siegel in der
 * System-DB. Je Zeile: row_hash = HMAC(Namensraum, Tabelle, Inhalt).
 */

declare(strict_types=1);

/** Spalten, die als Zahl gespeichert werden. */
const SQLFILE_INT_COLS = ['id', 'vlan_id', 'ssh_port', 'monitor', 'port', 'host_id', 'status_check', 'iperf_port', 'user_id', 'alg', 'sign_count',
    'active', 'must_change_pw', 'require_2fa', 'master_id', 'master_svc', 'ip_offset', 'auto_new', 'sort',
    'switch_id', 'enabled', 'untagged', 'vid', 'device_host', 'mgmt_vlan', 'down_mbit', 'up_mbit', 'wan_vlan', 'mtu', 'priority', 'ipv6_len', 'router_host'];
/** Spalten, die leer bleiben dürfen ('' wird nicht zu NULL). */
const SQLFILE_KEEP_EMPTY = ['name', 'hostname', 'slug', 'kind', 'proto', 'username'];

class StoreWriteBlocked extends RuntimeException
{
}

function sqlfile_parse(string $content, array $schema): array
{
    $tables = array_fill_keys(array_keys($schema), []);
    $out = ['tables' => $tables, 'trailer' => null, 'body' => $content, 'foreign' => [], 'errors' => []];
    if (preg_match('/\n-- integrity: hmac-sha256=([0-9a-f]{64})\s*$/', $content, $m, PREG_OFFSET_CAPTURE)) {
        $out['trailer'] = $m[1][0];
        $out['body'] = substr($content, 0, $m[0][1] + 1);
    }
    // Anweisungen trennen: Text zwischen Sonderzeichen in Blöcken übernehmen (strcspn),
    // Zeichenketten mit strpos überspringen – deutlich schneller als zeichenweise.
    $len = strlen($content);
    $i = 0;
    $stmt = '';
    $blank = true;
    $statements = [];
    while ($i < $len) {
        $k = strcspn($content, "';-", $i);
        if ($k > 0) {
            $chunk = substr($content, $i, $k);
            if ($blank && strspn($chunk, " \t\r\n") !== $k) {
                $blank = false;
            }
            $stmt .= $chunk;
            $i += $k;
            continue;
        }
        $c = $content[$i];
        if ($c === '-') {
            if ($blank && ($content[$i + 1] ?? '') === '-') {
                $nl = strpos($content, "\n", $i);
                $i = $nl === false ? $len : $nl + 1;
                continue;
            }
            $stmt .= '-';
            $blank = false;
            $i++;
            continue;
        }
        if ($c === "'") {
            $j = $i + 1;
            while (true) {
                $q = strpos($content, "'", $j);
                if ($q === false) {
                    $j = $len;
                    break;
                }
                if (($content[$q + 1] ?? '') === "'") {
                    $j = $q + 2;
                    continue;
                }
                $j = $q;
                break;
            }
            $stmt .= substr($content, $i, $j - $i + 1);
            $blank = false;
            $i = $j + 1;
            continue;
        }
        // ';'
        $statements[] = trim($stmt);
        $stmt = '';
        $blank = true;
        $i++;
    }
    if (trim($stmt) !== '') {
        $out['errors'][] = 'Unvollständige Anweisung am Dateiende';
    }
    $names = implode('|', array_map('preg_quote', array_keys($schema)));
    foreach ($statements as $s) {
        if ($s === '') {
            continue;
        }
        if (preg_match('/^INSERT INTO (' . $names . ') \(([a-z_0-9, ]+)\) VALUES \((.*)\)$/s', $s, $m)) {
            $cols = array_map('trim', explode(',', $m[2]));
            $vals = sqlfile_parse_values($m[3]);
            if ($vals === null || count($vals) !== count($cols)) {
                $out['errors'][] = 'Fehlerhafte Werte in: ' . mb_substr($s, 0, 120);
                continue;
            }
            $known = $schema[$m[1]];
            $unknown = array_diff($cols, array_keys($known));
            if ($unknown !== []) {
                $out['errors'][] = 'Unbekannte Spalten in ' . $m[1] . ': ' . implode(', ', $unknown);
            }
            $row = array_intersect_key(array_combine($cols, $vals), $known) + array_fill_keys(array_keys($known), null);
            $id = (int)$row['id'];
            if (isset($out['tables'][$m[1]][$id])) {
                $out['errors'][] = 'Doppelte ID ' . $id . ' in ' . $m[1];
            }
            $out['tables'][$m[1]][$id] = $row;
            continue;
        }
        if (preg_match('/^(PRAGMA foreign_keys=OFF|BEGIN TRANSACTION|COMMIT|CREATE TABLE (' . $names . ') \(.*\)|CREATE (UNIQUE )?INDEX ix_[a-z_]+ ON [a-z_]+ \([a-z_, ]+\))$/s', $s)) {
            continue;
        }
        $out['foreign'][] = mb_substr($s, 0, 200);
    }
    return $out;
}

function sqlfile_parse_values(string $s): ?array
{
    $vals = [];
    $len = strlen($s);
    $i = 0;
    while (true) {
        while ($i < $len && ctype_space($s[$i])) {
            $i++;
        }
        if ($i >= $len) {
            return null;
        }
        if ($s[$i] === "'") {
            $j = $i + 1;
            $buf = '';
            while (true) {
                $q = strpos($s, "'", $j);
                if ($q === false) {
                    return null;
                }
                $buf .= substr($s, $j, $q - $j);
                if (($s[$q + 1] ?? '') === "'") {
                    $buf .= "'";
                    $j = $q + 2;
                    continue;
                }
                $i = $q + 1;
                break;
            }
            $vals[] = $buf;
        } elseif (strncasecmp(substr($s, $i, 4), 'NULL', 4) === 0) {
            $vals[] = null;
            $i += 4;
        } elseif (preg_match('/\G-?\d+(\.\d+)?(?:[eE][-+]?\d+)?/', $s, $m, 0, $i)) {
            $vals[] = isset($m[1]) || stripos($m[0], 'e') !== false ? (float)$m[0] : (int)$m[0];
            $i += strlen($m[0]);
        } else {
            return null;
        }
        while ($i < $len && ctype_space($s[$i])) {
            $i++;
        }
        if ($i >= $len) {
            return $vals;
        }
        if ($s[$i] !== ',') {
            return null;
        }
        $i++;
    }
}

function sqlfile_value(mixed $v): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v)) {
        return (string)$v;
    }
    if (is_float($v)) {
        return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    return "'" . str_replace("'", "''", str_replace("\0", '', (string)$v)) . "'";
}

/** Erzeugt den Dateiinhalt inkl. HMAC-Schlusszeile. */
function sqlfile_dump(array $schema, array $tables, array $header, array $indexes = []): string
{
    $lines = ['-- ' . str_repeat('=', 74)];
    foreach ($header as $hl) {
        $lines[] = '-- ' . str_replace(["\r", "\n"], ' ', $hl);
    }
    $lines[] = '-- ' . str_repeat('=', 74);
    $lines[] = 'PRAGMA foreign_keys=OFF;';
    $lines[] = 'BEGIN TRANSACTION;';
    foreach ($schema as $table => $cols) {
        $defs = [];
        foreach ($cols as $c => $tdef) {
            $defs[] = '  ' . $c . ' ' . $tdef;
        }
        $lines[] = '';
        $lines[] = 'CREATE TABLE ' . $table . " (\n" . implode(",\n", $defs) . "\n);";
        $rows = $tables[$table] ?? [];
        ksort($rows);
        $colList = implode(', ', array_keys($cols));
        foreach ($rows as $r) {
            $vals = [];
            foreach (array_keys($cols) as $c) {
                $vals[] = sqlfile_value($r[$c] ?? null);
            }
            $lines[] = 'INSERT INTO ' . $table . ' (' . $colList . ') VALUES (' . implode(', ', $vals) . ');';
        }
    }
    $lines[] = '';
    foreach ($indexes as $ix) {
        $lines[] = $ix;
    }
    $lines[] = 'COMMIT;';
    $body = implode("\n", $lines) . "\n";
    return $body . '-- integrity: hmac-sha256=' . integrity_file_hmac($body) . "\n";
}

/** Liest + zerlegt eine Datei. */
/**
 * Zerlegen mit Zwischenspeicher: das Ergebnis wird je Datei in .cache/parsed/ abgelegt
 * (Schlüssel = Inhalts-Hash, geschützt mit HMAC). Bei gleichem Inhalt entfällt das Zerlegen;
 * Datei-HMAC und Siegel werden trotzdem bei jedem Lesen geprüft.
 */
function sqlfile_parse_cached(string $file, string $content, array $schema): array
{
    $hash = hash('xxh128', $content) . ':' . hash('xxh128', (string)json_encode($schema)) . ':p1';
    $dir = CACHE_DIR . '/parsed';
    $cf = $dir . '/' . basename($file) . '.json';
    $key = integrity_key('cache');
    $raw = is_file($cf) ? (string)@file_get_contents($cf) : '';
    if (strlen($raw) > 65 && $raw[64] === "\n") {
        $json = substr($raw, 65);
        if (hash_equals(hash_hmac('sha256', $json, $key), substr($raw, 0, 64))) {
            $d = json_decode($json, true);
            if (is_array($d) && ($d['hash'] ?? '') === $hash && isset($d['p']['tables'])) {
                $p = $d['p'];
                return ['tables' => $p['tables'], 'trailer' => $p['trailer'], 'body' => $p['trailer'] !== null ? substr($content, 0, (int)$d['body_len']) : $content,
                    'foreign' => $p['foreign'], 'errors' => $p['errors']];
            }
        }
    }
    $p = sqlfile_parse($content, $schema);
    $store = $p;
    unset($store['body']);
    $json = (string)json_encode(['hash' => $hash, 'body_len' => strlen($p['body']), 'p' => $store], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    @file_put_atomic($cf, hash_hmac('sha256', $json, $key) . "\n" . $json, 0640);
    return $p;
}

function sqlfile_read(string $file, array $schema): array
{
    $fh = fopen($file, 'rb');
    if (!$fh) {
        throw new RuntimeException('Datei nicht lesbar: ' . basename($file));
    }
    flock($fh, LOCK_SH);
    $content = (string)stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $p = sqlfile_parse_cached($file, $content, $schema);
    return [
        'tables' => $p['tables'],
        'sha256' => hash('sha256', $content),
        'bytes' => strlen($content),
        'mtime' => (int)filemtime($file),
        'parse' => [
            'trailer' => $p['trailer'],
            'body_hmac_ok' => $p['trailer'] !== null && hash_equals(integrity_file_hmac($p['body']), $p['trailer']),
            'foreign' => $p['foreign'],
            'errors' => $p['errors'],
        ],
    ];
}

/** Zeile vorbereiten: Spalten auffüllen, ID vergeben, Typen setzen, Hash berechnen. */
function sqlfile_row(array $schema, string $table, array $row, array $existing, string $ns): array
{
    $cols = $schema[$table];
    $row = array_intersect_key($row, $cols) + array_fill_keys(array_keys($cols), null);
    if (empty($row['id'])) {
        $row['id'] = $existing === [] ? 1 : max(array_keys($existing)) + 1;
    }
    foreach ($row as $k => $v) {
        if (in_array($k, SQLFILE_INT_COLS, true)) {
            $row[$k] = ($v === null || $v === '') ? null : (int)$v;
        } elseif (is_string($v) && $v === '' && !in_array($k, SQLFILE_KEEP_EMPTY, true)) {
            $row[$k] = null;
        }
    }
    $row['row_hash'] = integrity_row_hash($ns, $table, $row);
    return $row;
}

function sqlfile_row_valid(string $ns, string $table, array $row): bool
{
    return is_string($row['row_hash'] ?? null) && hash_equals(integrity_row_hash($ns, $table, $row), $row['row_hash']);
}

function sqlfile_lock(string $key)
{
    $dir = CACHE_DIR . '/locks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $fh = fopen($dir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $key) . '.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX)) {
        throw new RuntimeException('Sperre nicht möglich: ' . $key);
    }
    return $fh;
}

function sqlfile_unlock($fh): void
{
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Stimmt die Datei mit ihrem Siegel überein? [ok, Grund] */
function sqlfile_seal_ok(string $sealKey, string $sha256, bool $hmacOk): array
{
    $seal = sys_one('SELECT * FROM seals WHERE file = ?', [$sealKey]);
    if ($seal === null) {
        return [false, t('Kein Siegel vorhanden – die Datei wurde nicht über das Portal angelegt')];
    }
    if (!hash_equals((string)$seal['sha256'], $sha256)) {
        return [false, t('Datei-Hash weicht vom Siegel ab – die Datei wurde am Portal vorbei geändert')];
    }
    if (!$hmacOk) {
        return [false, t('Datei-HMAC (letzte Zeile) ungültig')];
    }
    return [true, ''];
}

function sqlfile_seal_store(string $sealKey, string $content, int $rows, string $by): void
{
    $body = substr($content, 0, (int)strrpos(rtrim($content, "\n"), "\n") + 1);
    sys_exec('INSERT INTO seals (file, sha256, hmac, bytes, rows, sealed_at, sealed_by) VALUES (?, ?, ?, ?, ?, ?, ?)
              ON CONFLICT(file) DO UPDATE SET sha256 = excluded.sha256, hmac = excluded.hmac, bytes = excluded.bytes,
              rows = excluded.rows, sealed_at = excluded.sealed_at, sealed_by = excluded.sealed_by',
        [$sealKey, hash('sha256', $content), integrity_file_hmac($body), strlen($content), $rows, now_str(), $by]);
}

/** Datei atomisch schreiben + versiegeln. */
function sqlfile_save(string $file, string $sealKey, string $content, int $rows, string $by): void
{
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0770, true);
    }
    if (!file_put_atomic($file, $content, 0640)) {
        throw new RuntimeException(t('SQL-Datei konnte nicht geschrieben werden: {f}', ['f' => basename($file)]));
    }
    sqlfile_seal_store($sealKey, $content, $rows, $by);
}

function current_actor(): string
{
    return (string)(auth_user_soft()['username'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'system'));
}
