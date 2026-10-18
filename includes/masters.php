<?php
/**
 * Master-Vorlagen: database/masters.sql
 *
 * Ein Master ist eine Vorlage für einen Eintrag (z. B. Router/Gateway, DNS,
 * Firewall), der in jedem abgehakten Netzwerk automatisch auf einer festen
 * Position angelegt und mit der Vorlage abgeglichen wird – standardmäßig auf
 * der ersten nutzbaren IP des Netzes.
 *
 *   masters           Vorlage (Name-Muster, Typ, Icon, Betriebssystem, …, IP-Regel)
 *   master_services   Dienste der Vorlage (werden im Eintrag mitgepflegt)
 *   master_nets       abgehakte Netzwerke
 *
 * Im Netzwerk tragen die erzeugten Einträge hosts.master_id bzw.
 * services.master_svc. Von Hand ergänzte Dienste bleiben beim Abgleich
 * unberührt; MAC und Hardware-Kennung sind gerätebezogen und werden nie
 * überschrieben. Gleiche Integritätssicherung wie Netzwerke und Benutzer.
 */

declare(strict_types=1);

const MASTERS_NS = '@masters';
const MASTERS_SEAL = 'masters.sql';
const MASTER_RULES = ['first' => 'Erste nutzbare IP', 'gateway' => 'Gateway-IP des Netzwerks', 'last' => 'Letzte nutzbare IP', 'offset' => 'Netzadresse + Versatz'];
/** Felder, die aus der Vorlage in den Eintrag übernommen werden. */
const MASTER_HOST_FIELDS = ['kind', 'icon', 'os', 'vendor', 'model', 'location', 'owner', 'description', 'tags', 'ssh_user', 'ssh_port', 'iperf_port', 'monitor'];

const MASTERS_SCHEMA = [
    'masters' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'name' => 'TEXT NOT NULL',
        'hostname' => 'TEXT NOT NULL',
        'kind' => 'TEXT',
        'icon' => 'TEXT',
        'os' => 'TEXT',
        'vendor' => 'TEXT',
        'model' => 'TEXT',
        'location' => 'TEXT',
        'owner' => 'TEXT',
        'description' => 'TEXT',
        'tags' => 'TEXT',
        'ssh_user' => 'TEXT',
        'ssh_port' => 'INTEGER',
        'iperf_port' => 'INTEGER',
        'monitor' => 'INTEGER NOT NULL DEFAULT 1',
        'ip_rule' => "TEXT NOT NULL DEFAULT 'first'",
        'ip_offset' => 'INTEGER',
        'auto_new' => 'INTEGER NOT NULL DEFAULT 0',
        'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT',
        'row_hash' => 'TEXT NOT NULL',
    ],
    'master_services' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'master_id' => 'INTEGER NOT NULL REFERENCES masters(id)',
        'name' => 'TEXT NOT NULL',
        'proto' => "TEXT NOT NULL DEFAULT 'tcp'",
        'port' => 'INTEGER',
        'url' => 'TEXT',
        'status_check' => 'INTEGER DEFAULT 1',
        'description' => 'TEXT',
        'sort' => 'INTEGER',
        'row_hash' => 'TEXT NOT NULL',
    ],
    'master_nets' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'master_id' => 'INTEGER NOT NULL REFERENCES masters(id)',
        'net' => 'TEXT NOT NULL',
        'created_at' => 'TEXT', 'created_by' => 'TEXT',
        'row_hash' => 'TEXT NOT NULL',
    ],
];
const MASTERS_INDEXES = [
    'CREATE INDEX ix_msvc_master ON master_services (master_id);',
    'CREATE UNIQUE INDEX ix_mnet ON master_nets (master_id, net);',
];

function masters_file(): string
{
    return DB_DIR . '/masters.sql';
}

function masters_store(bool $fresh = false): array
{
    static $st = null;
    if ($st !== null && !$fresh) {
        return $st;
    }
    if (!is_file(masters_file())) {
        return $st = array_fill_keys(array_keys(MASTERS_SCHEMA), []) + ['sha256' => null, 'bytes' => 0, 'parse' => ['body_hmac_ok' => true, 'trailer' => null, 'foreign' => [], 'errors' => []], 'exists' => false];
    }
    $r = sqlfile_read(masters_file(), MASTERS_SCHEMA);
    return $st = $r['tables'] + ['sha256' => $r['sha256'], 'bytes' => $r['bytes'], 'parse' => $r['parse'], 'exists' => true];
}

function masters_put(array &$st, string $table, array $row): int
{
    $row = sqlfile_row(MASTERS_SCHEMA, $table, $row, $st[$table], MASTERS_NS);
    $st[$table][$row['id']] = $row;
    return $row['id'];
}

function masters_save(array &$st, ?string $by = null): void
{
    $by ??= current_actor();
    $header = [
        'Network Portal · Master-Vorlagen (SQLite-Dialekt)',
        'Inhalt   : ' . count($st['masters']) . ' Master, ' . count($st['master_services']) . ' Dienste, ' . count($st['master_nets']) . ' Netzwerk-Zuordnungen',
        'Geschrieben ' . now_str() . ' von ' . $by,
        'Jede Zeile trägt ein HMAC (row_hash), die Datei ein HMAC in der letzten Zeile.',
    ];
    $sql = sqlfile_dump(MASTERS_SCHEMA, $st, $header, MASTERS_INDEXES);
    sqlfile_save(masters_file(), MASTERS_SEAL, $sql, count($st['masters']) + count($st['master_services']) + count($st['master_nets']), $by);
    $st['sha256'] = hash('sha256', $sql);
}

/** Ändert masters.sql unter Sperre. $fn(array &$st): mixed */
function masters_write(callable $fn): mixed
{
    $lock = sqlfile_lock('masters');
    try {
        $st = masters_store(true);
        if ($st['exists']) {
            [$ok, $why] = sqlfile_seal_ok(MASTERS_SEAL, (string)$st['sha256'], $st['parse']['body_hmac_ok']);
            if (!$ok) {
                audit('net_write_blocked', ['datei' => 'masters.sql', 'grund' => $why], null, 'denied', ['type' => 'master', 'label' => 'masters.sql']);
                throw new StoreWriteBlocked(t('Schreibschutz für masters.sql: {w}. Ein Portal-Admin muss die Abweichung unter „Integrität“ prüfen und neu versiegeln.', ['w' => $why]));
            }
        }
        $result = $fn($st);
        masters_save($st);
        masters_store(true);
        return $result;
    } finally {
        sqlfile_unlock($lock);
    }
}

function master_get(int $id): ?array
{
    return masters_store()['masters'][$id] ?? null;
}

/** Dienste eines Masters, sortiert. */
function master_services(int $id): array
{
    $l = array_values(array_filter(masters_store()['master_services'], static fn($s) => (int)$s['master_id'] === $id));
    usort($l, static fn($a, $b) => [(int)$a['sort'], (int)$a['id']] <=> [(int)$b['sort'], (int)$b['id']]);
    return $l;
}

/** Kurznamen der abgehakten Netzwerke eines Masters. */
function master_nets(int $id): array
{
    $out = [];
    foreach (masters_store()['master_nets'] as $r) {
        if ((int)$r['master_id'] === $id) {
            $out[] = (string)$r['net'];
        }
    }
    return $out;
}

function masters_list(): array
{
    $l = array_values(masters_store()['masters']);
    usort($l, static fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
    return $l;
}

function master_rule_label(array $m): string
{
    $r = (string)$m['ip_rule'];
    return t(MASTER_RULES[$r] ?? MASTER_RULES['first']) . ($r === 'offset' ? ' (+' . (int)$m['ip_offset'] . ')' : '');
}

/** Ganzzahl zu einer IP-Adresse (v4/v6) addieren. */
function ip_add(string $ip, int $n): ?string
{
    $b = @inet_pton($ip);
    if ($b === false) {
        return null;
    }
    $bytes = array_values(unpack('C*', $b));
    $carry = $n;
    for ($i = count($bytes) - 1; $i >= 0 && $carry > 0; $i--) {
        $sum = $bytes[$i] + ($carry & 0xff);
        $carry >>= 8;
        $carry += $sum >> 8;
        $bytes[$i] = $sum & 0xff;
    }
    if ($carry > 0) {
        return null;
    }
    return inet_ntop(pack('C*', ...$bytes)) ?: null;
}

/** Ziel-IP eines Masters im Netzwerk: [ip|null, Hinweis]. */
function master_target_ip(array $m, array $network): array
{
    $info = cidr_info($network['cidr'] ?? null);
    if ($info === null) {
        return [null, t('kein CIDR hinterlegt')];
    }
    switch ((string)$m['ip_rule']) {
        case 'gateway':
            $gw = trim((string)($network['gateway'] ?? ''));
            if ($gw === '' || !ip_valid($gw)) {
                return [null, t('kein Gateway hinterlegt')];
            }
            return [$gw, ''];
        case 'last':
            return [$info['last'], ''];
        case 'offset':
            $off = max(1, (int)$m['ip_offset']);
            $ip = ip_add($info['network'], $off);
            if ($ip === null || !ip_in_cidr($ip, $network['cidr']) || ($info['broadcast'] !== null && $ip === $info['broadcast'])) {
                return [null, t('Versatz liegt außerhalb des Netzes')];
            }
            return [$ip, ''];
        default:
            return [$info['first'], ''];
    }
}

/** Platzhalter in Namen/URLs: {net} {slug} {vlan} {ip} {octet} {kind} */
function master_fill(string $pattern, array $network, ?string $ip): string
{
    $octet = $ip !== null && !ip_is_v6($ip) ? (string)substr((string)strrchr($ip, '.'), 1) : '';
    $s = strtr($pattern, [
        '{net}' => (string)$network['name'], '{slug}' => (string)$network['slug'], '{vlan}' => (string)($network['vlan_id'] ?? ''),
        '{ip}' => (string)$ip, '{octet}' => $octet, '{kind}' => (string)$network['kind'],
    ]);
    return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? '');
}

/** Gewünschter Eintrag aus der Vorlage (ohne gerätebezogene Felder). */
function master_host_row(array $m, array $network, ?string $ip): array
{
    $row = ['hostname' => mb_substr(master_fill((string)$m['hostname'], $network, $ip) ?: (string)$m['name'], 0, 120), 'master_id' => (int)$m['id']];
    if ($ip !== null) {
        $row['ip'] = $ip;
    }
    foreach (MASTER_HOST_FIELDS as $f) {
        $row[$f] = $m[$f];
    }
    if ($row['tags'] !== null) {
        $row['tags'] = master_fill((string)$row['tags'], $network, $ip);
    }
    if ($row['description'] !== null) {
        $row['description'] = master_fill((string)$row['description'], $network, $ip);
    }
    return $row;
}

/**
 * Wendet einen Master auf ein geladenes Netzwerk an (ohne zu speichern).
 * Liefert ['state' => new|ok|updated|removed|conflict|nocidr|none, 'ip' => …, 'detail' => …, 'changed' => bool].
 * $live = true: neue Einträge bekommen MAC/Hersteller aus der Nachbartabelle.
 */
function master_apply(array &$net, array $m, array $msvcs, bool $ticked, bool $adopt, bool $live): array
{
    $mid = (int)$m['id'];
    $linked = null;
    foreach ($net['hosts'] as $h) {
        if ((int)($h['master_id'] ?? 0) === $mid) {
            $linked = $h;
            break;
        }
    }
    $user = current_actor();
    if (!$ticked) {
        if ($linked === null) {
            return ['state' => 'none', 'ip' => null, 'detail' => '', 'changed' => false];
        }
        foreach ($net['services'] as $sid => $s) {
            if ((int)$s['host_id'] === (int)$linked['id']) {
                net_remove($net, 'services', (int)$sid);
            }
        }
        net_remove($net, 'hosts', (int)$linked['id']);
        return ['state' => 'removed', 'ip' => $linked['ip'], 'detail' => (string)$linked['hostname'], 'changed' => true, 'host' => $linked];
    }
    [$ip, $why] = master_target_ip($m, $net['network']);
    if ($ip === null) {
        return ['state' => 'nocidr', 'ip' => null, 'detail' => $why, 'changed' => false];
    }
    $conflict = null;
    foreach ($net['hosts'] as $h) {
        if ((string)$h['ip'] === $ip && ($linked === null || (int)$h['id'] !== (int)$linked['id'])) {
            $conflict = $h;
            break;
        }
    }
    $state = $linked === null ? 'new' : 'ok';
    $detail = '';
    if ($conflict !== null) {
        if ($adopt && $linked === null && empty($conflict['master_id'])) {
            $linked = $conflict;
            $state = 'adopted';
            $detail = (string)$conflict['hostname'];
        } else {
            if ($linked === null) {
                return ['state' => 'conflict', 'ip' => $ip, 'detail' => (string)$conflict['hostname'], 'changed' => false];
            }
            $ip = (string)$linked['ip'];
            $state = 'conflict';
            $detail = (string)$conflict['hostname'];
        }
    }
    $want = master_host_row($m, $net['network'], $ip);
    if ($linked === null) {
        $mac = null;
        $vendor = $want['vendor'];
        if ($live) {
            $local = detect_local_addrs();
            $mac = $local[$ip] ?? detect_neighbor_mac($ip);
            if ($mac !== null && ($vendor === null || $vendor === '')) {
                $vendor = oui_vendor($mac);
            }
        }
        $row = $want + ['mac' => $mac ?? MAC_NOT_FOUND, 'hw_id' => null, 'created_at' => now_str(), 'created_by' => $user, 'updated_at' => now_str(), 'updated_by' => $user];
        $row['vendor'] = $vendor;
        $hid = net_put($net, 'hosts', $row);
        $changed = true;
    } else {
        $hid = (int)$linked['id'];
        $diff = false;
        foreach ($want as $k => $v) {
            if ((string)($linked[$k] ?? '') !== (string)($v ?? '')) {
                $diff = true;
                break;
            }
        }
        $changed = $diff;
        if ($diff) {
            net_put($net, 'hosts', array_merge($linked, $want, ['updated_at' => now_str(), 'updated_by' => $user]));
            if ($state === 'ok') {
                $state = 'updated';
            }
        }
    }
    // Dienste der Vorlage abgleichen
    $have = [];
    foreach ($net['services'] as $sid => $s) {
        if ((int)$s['host_id'] === $hid && !empty($s['master_svc'])) {
            $have[(int)$s['master_svc']] = $s;
        }
    }
    foreach ($msvcs as $ms) {
        $sv = ['host_id' => $hid, 'name' => (string)$ms['name'], 'proto' => (string)$ms['proto'], 'port' => $ms['port'],
            'url' => $ms['url'] !== null && $ms['url'] !== '' ? master_fill((string)$ms['url'], $net['network'], $ip) : null,
            'status_check' => $ms['status_check'] === null ? 1 : (int)$ms['status_check'], 'description' => $ms['description'], 'master_svc' => (int)$ms['id']];
        $cur = $have[(int)$ms['id']] ?? null;
        unset($have[(int)$ms['id']]);
        if ($cur === null) {
            net_put($net, 'services', $sv + ['created_at' => now_str(), 'created_by' => $user, 'updated_at' => now_str(), 'updated_by' => $user]);
            $changed = true;
        } else {
            foreach ($sv as $k => $v) {
                if ((string)($cur[$k] ?? '') !== (string)($v ?? '')) {
                    net_put($net, 'services', array_merge($cur, $sv, ['updated_at' => now_str(), 'updated_by' => $user]));
                    $changed = true;
                    break;
                }
            }
        }
    }
    foreach ($have as $s) {
        net_remove($net, 'services', (int)$s['id']);
        $changed = true;
    }
    if ($changed && $state === 'ok') {
        $state = 'updated';
    }
    return ['state' => $state, 'ip' => $ip, 'detail' => $detail, 'changed' => $changed, 'host_id' => $hid];
}

/**
 * Vorschau für alle Netzwerke (nichts wird gespeichert). Arbeitet nur mit dem
 * schlanken Host-Index – auch bei sehr vielen Einträgen schnell.
 * Zustände: new | ok (vorhanden) | removed | conflict | nocidr | none
 */
function master_plan(array $m, array $msvcs, array $ticked): array
{
    $mid = (int)($m['id'] ?? 0);
    $out = [];
    foreach (net_list() as $slug => $meta) {
        try {
            $idx = net_host_index($slug);
        } catch (Throwable $e) {
            continue;
        }
        $base = ['name' => $meta['name'], 'cidr' => $meta['cidr'], 'vlan_id' => $meta['vlan_id'], 'changed' => false];
        $linked = null;
        if ($mid > 0) {
            foreach ($idx['hosts'] as $h) {
                if ($h['master_id'] === $mid) {
                    $linked = $h;
                    break;
                }
            }
        }
        if (!in_array($slug, $ticked, true)) {
            $out[$slug] = $base + ($linked === null ? ['state' => 'none', 'ip' => null, 'detail' => '']
                : ['state' => 'removed', 'ip' => $linked['ip'], 'detail' => $linked['hostname']]);
            continue;
        }
        [$ip, $why] = master_target_ip($m, $idx['network']);
        if ($ip === null) {
            $out[$slug] = $base + ['state' => 'nocidr', 'ip' => null, 'detail' => $why];
            continue;
        }
        $owner = null;
        foreach ($idx['hosts'] as $h) {
            if ($h['ip'] === $ip && ($linked === null || $h['id'] !== $linked['id'])) {
                $owner = $h;
                break;
            }
        }
        if ($linked !== null) {
            $out[$slug] = $base + ($owner !== null ? ['state' => 'conflict', 'ip' => $linked['ip'], 'detail' => $owner['hostname']] : ['state' => 'ok', 'ip' => $ip, 'detail' => '']);
        } else {
            $out[$slug] = $base + ($owner !== null ? ['state' => 'conflict', 'ip' => $ip, 'detail' => $owner['hostname']] : ['state' => 'new', 'ip' => $ip, 'detail' => '']);
        }
    }
    return $out;
}

/**
 * Gleicht einen Master mit allen Netzwerken ab (oder nur mit $only).
 * Speichert nur Netzwerke, in denen sich wirklich etwas ändert.
 */
function master_sync(int $mid, bool $adopt = false, ?array $only = null): array
{
    $m = master_get($mid);
    $msvcs = $m !== null ? master_services($mid) : [];
    $ticked = $m !== null ? master_nets($mid) : [];
    $results = [];
    foreach (array_keys(net_list(true)) as $slug) {
        if ($only !== null && !in_array($slug, $only, true)) {
            continue;
        }
        try {
            // nichts zu tun: nicht ausgewählt und kein verknüpfter Eintrag → Netzwerk gar nicht erst laden
            if (!in_array($slug, $ticked, true)) {
                $hasLinked = false;
                foreach (net_host_index($slug)['hosts'] as $h) {
                    if ($h['master_id'] === $mid) {
                        $hasLinked = true;
                        break;
                    }
                }
                if (!$hasLinked) {
                    continue;
                }
            }
            $probe = net_load($slug, true);
            if ($m === null) {
                // Master gelöscht: verknüpfte Einträge entfernen
                $r = master_apply($probe, ['id' => $mid], [], false, false, false);
            } else {
                $r = master_apply($probe, $m, $msvcs, in_array($slug, $ticked, true), $adopt, false);
            }
            if ($r['changed']) {
                $r = net_write($slug, static function (array &$net) use ($m, $mid, $msvcs, $ticked, $slug, $adopt): array {
                    return $m === null ? master_apply($net, ['id' => $mid], [], false, false, true)
                        : master_apply($net, $m, $msvcs, in_array($slug, $ticked, true), $adopt, true);
                });
            }
        } catch (StoreWriteBlocked $e) {
            $r = ['state' => 'blocked', 'ip' => null, 'detail' => $e->getMessage(), 'changed' => false];
        } catch (Throwable $e) {
            $r = ['state' => 'error', 'ip' => null, 'detail' => $e->getMessage(), 'changed' => false];
        }
        if ($r['state'] === 'removed' && !empty($r['host']['id'])) {
            try {
                infra_forget_host($slug, (int)$r['host']['id']);
            } catch (Throwable $e) {
                $r['detail'] .= ' · ' . $e->getMessage();
            }
        }
        if ($r['state'] !== 'none') {
            $results[$slug] = $r;
        }
    }
    $count = [];
    foreach ($results as $r) {
        $count[$r['state']] = ($count[$r['state']] ?? 0) + 1;
    }
    audit('master_sync', ['master' => $m['name'] ?? ('#' . $mid), 'ergebnis' => $count,
        'netzwerke' => array_map(static fn($r) => $r['state'] . ($r['ip'] ? ' ' . $r['ip'] : '') . ($r['detail'] !== '' ? ' (' . $r['detail'] . ')' : ''), $results)],
        null, isset($count['conflict']) || isset($count['blocked']) || isset($count['error']) ? 'warn' : 'ok', ['type' => 'master', 'id' => $mid, 'label' => $m['name'] ?? '']);
    return $results;
}

/** Neues Netzwerk angelegt: Master mit „automatisch für neue Netzwerke“ dort anlegen. */
function masters_on_new_network(string $slug): array
{
    $out = [];
    foreach (masters_list() as $m) {
        if ((int)$m['auto_new'] !== 1) {
            continue;
        }
        masters_write(static function (array &$st) use ($m, $slug): void {
            foreach ($st['master_nets'] as $r) {
                if ((int)$r['master_id'] === (int)$m['id'] && $r['net'] === $slug) {
                    return;
                }
            }
            masters_put($st, 'master_nets', ['master_id' => $m['id'], 'net' => $slug, 'created_at' => now_str(), 'created_by' => current_actor()]);
        });
        $out[$m['name']] = master_sync((int)$m['id'], false, [$slug])[$slug] ?? null;
    }
    return $out;
}

/** Text zum Zustand eines Netzwerks in Vorschau/Ergebnis. */
function master_state_label(string $state): string
{
    return match ($state) {
        'new' => t('wird angelegt'), 'ok' => t('vorhanden'), 'updated' => t('wird angepasst'), 'removed' => t('wird entfernt'),
        'conflict' => t('IP belegt'), 'nocidr' => t('nicht möglich'), 'adopted' => t('übernommen'), 'blocked' => t('Schreibschutz'),
        'error' => t('Fehler'), default => '–',
    };
}

/** Zustand nach dem Abgleich (Vergangenheit). */
function master_state_done(string $state): string
{
    return match ($state) {
        'new' => t('angelegt'), 'updated' => t('angepasst'), 'ok' => t('aktuell'), 'adopted' => t('übernommen'), 'removed' => t('entfernt'),
        default => master_state_label($state),
    };
}

function master_state_class(string $state): string
{
    return match ($state) {
        'ok' => 'ok-soft', 'new', 'updated', 'adopted' => 'state-unknown', 'removed', 'conflict', 'blocked', 'error' => 'bad', default => 'dim',
    };
}
