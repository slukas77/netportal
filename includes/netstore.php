<?php
/**
 * Netzwerk-Speicher: JEDES Netzwerk ist eine eigene SQL-Datei
 *
 *     database/net-<kurzname>.sql
 *
 * Normales SQL (SQLite-Dialekt), jederzeit lesbar/importierbar:
 *     sqlite3 /tmp/netz.db < database/net-buero.sql
 *
 * Integrität (siehe sqlfile.php / integrity.php): HMAC je Zeile (an Kurzname
 * und Tabelle gebunden), HMAC je Datei, SHA-256-Siegel in der System-DB.
 * Stimmt das Siegel nicht, wird das Schreiben verweigert, bis ein Portal-Admin
 * die Abweichung geprüft und neu versiegelt hat.
 */

declare(strict_types=1);

const NET_FORMAT = 2;
const NET_KINDS = ['LAN' => 'LAN', 'VLAN' => 'VLAN', 'WLAN' => 'WLAN', 'DMZ' => 'DMZ', 'MGMT' => 'Management', 'VPN' => 'VPN', 'WAN' => 'WAN / Transfer', 'OT' => 'OT / Steuerung'];
const HOST_KINDS = ['Server', 'VM', 'Container', 'Router', 'Firewall', 'Switch', 'Access Point', 'NAS / Storage', 'Hypervisor', 'Drucker', 'Client',
    'Telefon / VoIP', 'Kamera', 'IoT', 'Steuerung / SPS', 'USV', 'BMC / iLO / IPMI', 'Sonstiges'];
const MAC_NOT_FOUND = 'not found';

const NET_SCHEMA = [
    'network' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'slug' => 'TEXT NOT NULL',
        'name' => 'TEXT NOT NULL',
        'kind' => 'TEXT NOT NULL',
        'vlan_id' => 'INTEGER',
        'cidr' => 'TEXT',
        'gateway' => 'TEXT',
        'dns' => 'TEXT',
        'dhcp_range' => 'TEXT',
        'domain' => 'TEXT',
        'location' => 'TEXT',
        'description' => 'TEXT',
        'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT',
        'row_hash' => 'TEXT NOT NULL',
    ],
    'hosts' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'hostname' => 'TEXT NOT NULL',
        'ip' => 'TEXT',
        'mac' => 'TEXT',
        'hw_id' => 'TEXT',
        'kind' => 'TEXT',
        'icon' => 'TEXT',
        'os' => 'TEXT',
        'vendor' => 'TEXT',
        'model' => 'TEXT',
        'location' => 'TEXT',
        'owner' => 'TEXT',
        'ssh_user' => 'TEXT',
        'ssh_port' => 'INTEGER',
        'iperf_port' => 'INTEGER',
        'monitor' => 'INTEGER NOT NULL DEFAULT 1',
        'description' => 'TEXT',
        'tags' => 'TEXT',
        'master_id' => 'INTEGER',
        'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT',
        'row_hash' => 'TEXT NOT NULL',
    ],
    'services' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'host_id' => 'INTEGER NOT NULL REFERENCES hosts(id)',
        'name' => 'TEXT NOT NULL',
        'proto' => "TEXT NOT NULL DEFAULT 'tcp'",
        'port' => 'INTEGER',
        'url' => 'TEXT',
        'status_check' => 'INTEGER DEFAULT 1',
        'description' => 'TEXT',
        'master_svc' => 'INTEGER',
        'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT',
        'row_hash' => 'TEXT NOT NULL',
    ],
];
const NET_INDEXES = [
    'CREATE INDEX ix_hosts_ip ON hosts (ip);',
    'CREATE INDEX ix_services_host ON services (host_id);',
];

class NetWriteBlocked extends StoreWriteBlocked
{
}

final class NetNotFound extends RuntimeException
{
}

function net_valid_slug(string $s): bool
{
    return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,46}[a-z0-9])?$/', $s);
}

function net_slugify(string $name): string
{
    $s = mb_strtolower($name);
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'à' => 'a']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim(substr($s, 0, 48), '-');
    return $s !== '' ? $s : 'netz';
}

function net_file(string $slug): string
{
    if (!net_valid_slug($slug)) {
        throw new InvalidArgumentException('Ungültiger Netzwerk-Kurzname');
    }
    return DB_DIR . '/net-' . $slug . '.sql';
}

function net_seal_key(string $slug): string
{
    return 'net-' . $slug . '.sql';
}

/** Kompatibilität (CLI/Tests): zerlegt eine Netzwerk-Datei. */
function net_parse(string $content): array
{
    return sqlfile_parse($content, NET_SCHEMA);
}

/**
 * Lädt ein Netzwerk: ['network' => row, 'hosts' => [id => row], 'services' => [id => row],
 * 'slug', 'file', 'sha256', 'bytes', 'mtime', 'parse'].
 */
function net_load(string $slug, bool $fresh = false): array
{
    static $cache = [];
    if (!$fresh && isset($cache[$slug])) {
        return $cache[$slug];
    }
    $file = net_file($slug);
    if (!is_file($file)) {
        throw new NetNotFound(t('Netzwerk „{n}“ gibt es nicht.', ['n' => $slug]));
    }
    $r = sqlfile_read($file, NET_SCHEMA);
    $network = $r['tables']['network'][1] ?? null;
    if ($network === null) {
        $network = array_fill_keys(array_keys(NET_SCHEMA['network']), null);
        $network['id'] = 1;
        $network['slug'] = $slug;
        $network['name'] = $slug . ' (beschädigt)';
        $network['kind'] = 'LAN';
        $r['parse']['errors'][] = 'Kopfdatensatz (Tabelle network) fehlt';
    }
    $net = ['slug' => $slug, 'file' => $file, 'sha256' => $r['sha256'], 'bytes' => $r['bytes'], 'mtime' => $r['mtime'],
        'network' => $network, 'hosts' => $r['tables']['hosts'], 'services' => $r['tables']['services'], 'parse' => $r['parse']];
    uasort($net['hosts'], 'host_sort_cmp');
    // Zwischenspeicher je Aufruf begrenzen: bei sehr vielen großen Netzwerken sonst Speicher ohne Ende
    unset($cache[$slug]);
    if (count($cache) >= 8) {
        unset($cache[array_key_first($cache)]);
    }
    return $cache[$slug] = $net;
}

function host_sort_cmp(array $a, array $b): int
{
    return [ip_sort_key((string)$a['ip']), mb_strtolower((string)$a['hostname'])] <=> [ip_sort_key((string)$b['ip']), mb_strtolower((string)$b['hostname'])];
}

/** Liste aller Netzwerke (Navigation), gecacht nach Dateistand. */
function net_list(bool $fresh = false): array
{
    static $list = null;
    if ($list !== null && !$fresh) {
        return $list;
    }
    $cacheFile = CACHE_DIR . '/netindex.json';
    $cache = is_file($cacheFile) ? (json_decode((string)@file_get_contents($cacheFile), true) ?: []) : [];
    $out = [];
    $changed = false;
    foreach (glob(DB_DIR . '/net-*.sql') ?: [] as $f) {
        $slug = substr(basename($f, '.sql'), 4);
        if (!net_valid_slug($slug)) {
            continue;
        }
        $stamp = hash_file('xxh128', $f) . ':v3';
        if (($cache[$slug]['stamp'] ?? '') !== $stamp) {
            try {
                $n = net_load($slug, true);
                $cache[$slug] = [
                    'stamp' => $stamp, 'slug' => $slug,
                    'name' => (string)$n['network']['name'], 'kind' => (string)$n['network']['kind'],
                    'vlan_id' => $n['network']['vlan_id'], 'cidr' => (string)$n['network']['cidr'],
                    'hosts' => count($n['hosts']), 'services' => count($n['services']),
                    'host_ids' => array_values(array_map('intval', array_keys(array_filter($n['hosts'], static fn($h) => (int)$h['monitor'] === 1 && ($h['ip'] || $h['hostname']))))),
                ];
            } catch (Throwable $e) {
                $cache[$slug] = ['stamp' => $stamp, 'slug' => $slug, 'name' => $slug, 'kind' => '?', 'vlan_id' => null, 'cidr' => '', 'hosts' => 0, 'services' => 0, 'host_ids' => [], 'error' => true];
            }
            $changed = true;
        }
        $out[$slug] = $cache[$slug];
    }
    if ($changed || count($cache) !== count($out)) {
        file_put_atomic($cacheFile, (string)json_encode($out, JSON_UNESCAPED_UNICODE), 0660);
    }
    uasort($out, static fn($a, $b) => [($a['vlan_id'] === null ? -1 : (int)$a['vlan_id']), mb_strtolower($a['name'])] <=> [($b['vlan_id'] === null ? -1 : (int)$b['vlan_id']), mb_strtolower($b['name'])]);
    return $list = $out;
}

/**
 * Leichter Index der Einträge eines Netzwerks (Auswahllisten, Suche, NetMap).
 * Wird in .cache/hostidx/ zwischengespeichert und mit HMAC geschützt; ändert sich die
 * SQL-Datei (Zeitstempel/Größe), wird er aus der geprüften Datei neu gebaut.
 * Liefert ['network' => [slug, name, vlan_id, cidr], 'hosts' => [id => [...]]].
 */
function net_host_index(string $slug): array
{
    static $mem = [];
    $file = net_file($slug);
    if (!is_file($file)) {
        throw new NetNotFound(t('Netzwerk „{n}“ gibt es nicht.', ['n' => $slug]));
    }
    $stamp = hash_file('xxh128', $file) . ':i4';
    if (isset($mem[$slug]) && $mem[$slug]['stamp'] === $stamp) {
        return $mem[$slug];
    }
    $dir = CACHE_DIR . '/hostidx';
    $cf = $dir . '/' . $slug . '.json';
    $key = integrity_key('cache');
    if (is_file($cf)) {
        $raw = (string)@file_get_contents($cf);
        $nl = strpos($raw, "\n");
        if ($nl === 64) {
            $json = substr($raw, 65);
            if (hash_equals(hash_hmac('sha256', $json, $key), substr($raw, 0, 64))) {
                $d = json_decode($json, true);
                if (is_array($d) && ($d['stamp'] ?? '') === $stamp) {
                    return $mem[$slug] = $d;
                }
            }
        }
    }
    $net = net_load($slug, true);
    $svc = [];
    foreach ($net['services'] as $sv) {
        $svc[(int)$sv['host_id']][] = $sv['name'] . ' ' . $sv['port'] . ' ' . $sv['url'];
    }
    $hosts = [];
    foreach ($net['hosts'] as $h) {
        $hosts[(int)$h['id']] = [
            'id' => (int)$h['id'], 'hostname' => (string)$h['hostname'], 'ip' => (string)$h['ip'], 'mac' => (string)$h['mac'],
            'kind' => (string)$h['kind'], 'icon' => (string)$h['icon'], 'monitor' => (int)$h['monitor'], 'master_id' => (int)($h['master_id'] ?? 0), 'os' => (string)$h['os'],
            'hay' => mb_strtolower(implode(' ', [$h['ip'], $h['hostname'], $h['mac'], $h['hw_id'], $h['kind'], $h['os'], $h['vendor'], $h['model'],
                $h['tags'], $h['location'], $h['owner'], $h['description'], implode(' ', $svc[(int)$h['id']] ?? [])])),
        ];
    }
    $d = ['stamp' => $stamp, 'network' => ['slug' => $slug, 'name' => (string)$net['network']['name'], 'vlan_id' => $net['network']['vlan_id'],
        'cidr' => (string)$net['network']['cidr'], 'gateway' => (string)$net['network']['gateway']], 'hosts' => $hosts];
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $json = (string)json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_atomic($cf, hash_hmac('sha256', $json, $key) . "\n" . $json, 0640);
    return $mem[$slug] = $d;
}

function net_seal_ok(array $net): array
{
    return sqlfile_seal_ok(net_seal_key($net['slug']), $net['sha256'], $net['parse']['body_hmac_ok']);
}

function net_lock(string $slug)
{
    return sqlfile_lock('net-' . $slug);
}

/** Ändert ein Netzwerk. $fn(array &$net): mixed */
function net_write(string $slug, callable $fn, bool $force = false): mixed
{
    $lock = net_lock($slug);
    try {
        $net = net_load($slug, true);
        [$ok, $why] = net_seal_ok($net);
        if (!$ok && !$force) {
            audit('net_write_blocked', ['grund' => $why], null, 'denied', ['net' => $slug, 'type' => 'network', 'label' => $net['network']['name']]);
            throw new NetWriteBlocked(t('Schreibschutz für „{n}“: {w}. Ein Portal-Admin muss die Abweichung unter „Integrität“ prüfen und neu versiegeln.',
                ['n' => $net['network']['name'], 'w' => $why]));
        }
        $result = $fn($net);
        net_save($net);
        return $result;
    } finally {
        sqlfile_unlock($lock);
    }
}

function net_save(array &$net): void
{
    $by = current_actor();
    $n = $net['network'];
    $header = [
        'Network Portal · Netzwerk-Datenbank (Format ' . NET_FORMAT . ', SQLite-Dialekt)',
        'Netzwerk : ' . $n['name'] . ' (' . $n['slug'] . ')',
        'Typ      : ' . $n['kind'] . ($n['vlan_id'] !== null ? ' · VLAN ' . $n['vlan_id'] : '') . ($n['cidr'] ? ' · ' . $n['cidr'] : ''),
        'Inhalt   : ' . count($net['hosts']) . ' Hosts, ' . count($net['services']) . ' Dienste',
        'Geschrieben ' . now_str() . ' von ' . $by,
        'Diese Datei wird vom Network Portal verwaltet. Jede Zeile trägt ein HMAC',
        '(row_hash), die Datei ein HMAC in der letzten Zeile. Änderungen am Portal',
        'vorbei werden von der Integritätsprüfung erkannt.',
    ];
    $sql = sqlfile_dump(NET_SCHEMA, ['network' => [1 => $n], 'hosts' => $net['hosts'], 'services' => $net['services']], $header, NET_INDEXES);
    sqlfile_save($net['file'], net_seal_key($net['slug']), $sql, count($net['hosts']) + count($net['services']) + 1, $by);
    $net['sha256'] = hash('sha256', $sql);
    $net['bytes'] = strlen($sql);
}

function net_create(array $network): string
{
    $slug = (string)$network['slug'];
    $file = net_file($slug);
    $lock = net_lock($slug);
    try {
        if (is_file($file)) {
            throw new RuntimeException(t('Es gibt bereits ein Netzwerk mit dem Kurznamen „{s}“.', ['s' => $slug]));
        }
        $net = ['slug' => $slug, 'file' => $file, 'network' => [], 'hosts' => [], 'services' => []];
        $user = current_actor();
        $row = array_merge(array_fill_keys(array_keys(NET_SCHEMA['network']), null), array_intersect_key($network, NET_SCHEMA['network']));
        $row['created_at'] = $row['updated_at'] = now_str();
        $row['created_by'] = $row['updated_by'] = $user;
        net_put($net, 'network', $row);
        net_save($net);
        return $slug;
    } finally {
        sqlfile_unlock($lock);
    }
}

/** Setzt/ersetzt eine Zeile (inkl. Hash). Liefert die ID. */
function net_put(array &$net, string $table, array $row): int
{
    if ($table === 'network') {
        $row['id'] = 1;
        $net['network'] = sqlfile_row(NET_SCHEMA, 'network', $row, [], $net['slug']);
        return 1;
    }
    $row = sqlfile_row(NET_SCHEMA, $table, $row, $net[$table], $net['slug']);
    $net[$table][$row['id']] = $row;
    return $row['id'];
}

function net_remove(array &$net, string $table, int $id): ?array
{
    $row = $net[$table][$id] ?? null;
    unset($net[$table][$id]);
    return $row;
}

function net_archive(string $slug): string
{
    $lock = net_lock($slug);
    try {
        $dir = DB_DIR . '/archiv';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $target = $dir . '/net-' . $slug . '.' . date('Ymd-His') . '.sql';
        if (!@rename(net_file($slug), $target)) {
            throw new RuntimeException(t('Datei konnte nicht archiviert werden.'));
        }
        sys_exec('DELETE FROM seals WHERE file = ?', [net_seal_key($slug)]);
        status_drop($slug);
        return 'database/archiv/' . basename($target);
    } finally {
        sqlfile_unlock($lock);
    }
}

// --- Live-Status (Ping/Dienst-Prüfung/iperf3) – bewusst NICHT in der SQL-Datei ------------------

function status_file(string $slug): string
{
    return CACHE_DIR . '/status/net-' . $slug . '.json';
}

function status_get(string $slug): array
{
    $f = status_file($slug);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($d) ? $d + ['hosts' => [], 'services' => [], 'iperf' => []] : ['hosts' => [], 'services' => [], 'iperf' => []];
}

/** $updates = ['hosts' => [id => [...]], 'services' => [...], 'iperf' => [...]] */
function status_put(string $slug, array $updates): void
{
    $dir = CACHE_DIR . '/status';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $fh = fopen(status_file($slug), 'c+');
    if (!$fh) {
        return;
    }
    flock($fh, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fh), true);
    $d = is_array($d) ? $d : [];
    foreach (['hosts', 'services', 'iperf'] as $k) {
        foreach ($updates[$k] ?? [] as $id => $v) {
            $d[$k][(string)$id] = $v;
        }
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)json_encode($d));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function status_drop(string $slug): void
{
    @unlink(status_file($slug));
}
