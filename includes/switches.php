<?php
/**
 * Managed-Switch-Verwaltung: database/switches.sql
 *
 *   switch_templates   eigene Vorlagen (Port-Aufbau, Grundeinstellungen, Port-Vorgaben)
 *   switches           Switches (Modell/Vorlage, Management-IP, Einstellungen)
 *   switch_ports       je Port: Name, an/aus, Netzwerk untagged, Netzwerke tagged,
 *                      Trunk-Gruppe, Geschwindigkeit, angeschlossenes Gerät
 *   switch_vlans       VLANs auf dem Switch (mit Verweis auf das Netzwerk im Portal)
 *
 * Original-Vorlagen (fest eingebaut, nicht änderbar) nach Herstellerangaben:
 *   HP ProCurve 2610-24 (J9085A), HP ProCurve 2510-24 (J9019A, J9019B).
 * Eigene Vorlagen lassen sich frei anlegen oder aus einem Original kopieren.
 *
 * Konfiguration: Das Portal erzeugt die Konfiguration im ProCurve-Format
 * (running-config) zum Herunterladen/Kopieren und liest eine vorhandene
 * running-config wieder ein.
 */

declare(strict_types=1);

store_defs(['switches' => [
    'file' => 'switches.sql', 'ns' => '@switches', 'seal' => 'switches.sql', 'title' => 'Switch-Verwaltung',
    'schema' => [
        'switch_templates' => [
            'id' => 'INTEGER NOT NULL PRIMARY KEY', 'name' => 'TEXT NOT NULL', 'vendor' => 'TEXT', 'model' => 'TEXT', 'product_no' => 'TEXT',
            'software' => 'TEXT', 'cli' => "TEXT NOT NULL DEFAULT 'procurve'", 'base' => 'TEXT', 'ports' => 'TEXT NOT NULL', 'layout' => "TEXT NOT NULL DEFAULT 'oddeven'",
            'console' => 'TEXT', 'default_hostname' => 'TEXT', 'settings' => 'TEXT', 'port_presets' => 'TEXT', 'description' => 'TEXT',
            'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT', 'row_hash' => 'TEXT NOT NULL',
        ],
        'switches' => [
            'id' => 'INTEGER NOT NULL PRIMARY KEY', 'name' => 'TEXT NOT NULL', 'template' => 'TEXT NOT NULL', 'net' => 'TEXT', 'host_id' => 'INTEGER',
            'mgmt_vlan' => 'INTEGER', 'mgmt_ip' => 'TEXT', 'mgmt_mask' => 'TEXT', 'serial' => 'TEXT', 'firmware' => 'TEXT', 'location' => 'TEXT',
            'settings' => 'TEXT', 'notes' => 'TEXT',
            'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT', 'row_hash' => 'TEXT NOT NULL',
        ],
        'switch_ports' => [
            'id' => 'INTEGER NOT NULL PRIMARY KEY', 'switch_id' => 'INTEGER NOT NULL REFERENCES switches(id)', 'pnum' => 'TEXT NOT NULL', 'name' => 'TEXT',
            'enabled' => 'INTEGER NOT NULL DEFAULT 1', 'untagged' => 'INTEGER', 'tagged' => 'TEXT', 'trunk' => 'TEXT', 'speed' => 'TEXT',
            'device_net' => 'TEXT', 'device_host' => 'INTEGER', 'notes' => 'TEXT', 'row_hash' => 'TEXT NOT NULL',
        ],
        'switch_vlans' => [
            'id' => 'INTEGER NOT NULL PRIMARY KEY', 'switch_id' => 'INTEGER NOT NULL REFERENCES switches(id)', 'vid' => 'INTEGER NOT NULL', 'name' => 'TEXT',
            'net' => 'TEXT', 'ip' => 'TEXT', 'mask' => 'TEXT', 'row_hash' => 'TEXT NOT NULL',
        ],
    ],
    'indexes' => ['CREATE INDEX ix_swport ON switch_ports (switch_id);', 'CREATE INDEX ix_swvlan ON switch_vlans (switch_id, vid);'],
]]);

/** Original-Vorlagen nach Herstellerangaben (HP/Hewlett-Packard ProCurve). */
const SWITCH_ORIGINALS = [
    'J9085A' => [
        'name' => 'HP ProCurve 2610-24', 'vendor' => 'HP ProCurve', 'model' => '2610-24', 'product_no' => 'J9085A', 'software' => 'R.11', 'cli' => 'procurve',
        'layout' => 'oddeven', 'console' => 'RJ-45 (seriell)', 'default_hostname' => 'ProCurve Switch 2610-24',
        'ports' => "1-24;10/100Base-TX;rj45\n25-26;10/100/1000Base-T;rj45\n27-28;mini-GBIC (SFP);sfp",
        'description' => '24 × RJ-45 10/100 (Auto-MDIX), 2 × RJ-45 10/100/1000, 2 × offene mini-GBIC-Steckplätze, 1 × serielle Konsole (RJ-45). Software-Familie R.11.',
    ],
    'J9019B' => [
        'name' => 'HP ProCurve 2510-24', 'vendor' => 'HP ProCurve', 'model' => '2510-24', 'product_no' => 'J9019B', 'software' => 'Q.11', 'cli' => 'procurve',
        'layout' => 'oddeven', 'console' => 'RJ-45 (seriell)', 'default_hostname' => 'ProCurve Switch 2510-24',
        'ports' => "1-24;10/100Base-TX;rj45\n25-26;Dual-Personality: 10/100/1000Base-T oder mini-GBIC;combo",
        'description' => 'Hardware-Revision B. 24 × RJ-45 10/100 (Auto-MDIX), 2 × Dual-Personality-Ports (wahlweise RJ-45 10/100/1000 oder mini-GBIC), 1 × serielle Konsole (RJ-45). Software-Familie Q.',
    ],
    'J9019A' => [
        'name' => 'HP ProCurve 2510-24', 'vendor' => 'HP ProCurve', 'model' => '2510-24', 'product_no' => 'J9019A', 'software' => 'Q.10', 'cli' => 'procurve',
        'layout' => 'oddeven', 'console' => 'RJ-45 (seriell)', 'default_hostname' => 'ProCurve Switch 2510-24',
        'ports' => "1-24;10/100Base-TX;rj45\n25-26;Dual-Personality: 10/100/1000Base-T oder mini-GBIC;combo",
        'description' => 'Hardware-Revision A. 24 × RJ-45 10/100 (Auto-MDIX), 2 × Dual-Personality-Ports (wahlweise RJ-45 10/100/1000 oder mini-GBIC), 1 × serielle Konsole (RJ-45). Software-Familie Q.',
    ],
];

/** Werkseinstellungen der ProCurve-Originale. */
const SWITCH_FACTORY = [
    'hostname' => '', 'gateway' => '', 'sntp' => '', 'timezone' => '', 'dst' => '', 'snmp_community' => 'public', 'snmp_rw' => '1',
    'snmp_location' => '', 'snmp_contact' => '', 'syslog' => '', 'ssh' => '0', 'telnet' => '1', 'web' => '1', 'stp' => '0', 'managers' => '', 'console_timeout' => '',
];
const SWITCH_SETTING_KEYS = ['hostname', 'gateway', 'sntp', 'timezone', 'dst', 'snmp_community', 'snmp_rw', 'snmp_location', 'snmp_contact', 'syslog',
    'ssh', 'telnet', 'web', 'stp', 'managers', 'console_timeout'];
const SWITCH_SPEEDS = ['auto', '10-half', '10-full', '100-half', '100-full', '1000-full', 'auto-10', 'auto-100'];

// --- Portbereiche ------------------------------------------------------------------------------

/** "1-4,7,Trk1" → ['1','2','3','4','7','Trk1'] */
function port_range_expand(string $s): array
{
    $out = [];
    foreach (preg_split('/[\s,]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        if (preg_match('/^([A-Za-z]*)(\d+)-\1?(\d+)$/', $part, $m) && (int)$m[3] >= (int)$m[2] && (int)$m[3] - (int)$m[2] < 1000) {
            for ($i = (int)$m[2]; $i <= (int)$m[3]; $i++) {
                $out[] = $m[1] . $i;
            }
        } elseif (preg_match('/^[A-Za-z0-9]{1,12}$/', $part)) {
            $out[] = $part;
        }
    }
    return array_values(array_unique($out));
}

/** ['1','2','3','5'] → "1-3,5" (Buchstaben-Präfixe werden gruppiert). */
function port_range_compress(array $ports): string
{
    $groups = [];
    $other = [];
    foreach ($ports as $p) {
        $p = (string)$p;
        if (preg_match('/^([A-Za-z]*)(\d+)$/', $p, $m) && !preg_match('/^trk/i', $p)) {
            $groups[$m[1]][] = (int)$m[2];
        } else {
            $other[] = $p;
        }
    }
    $parts = [];
    foreach ($groups as $pre => $nums) {
        sort($nums);
        $nums = array_values(array_unique($nums));
        $start = $prev = null;
        foreach ($nums as $n) {
            if ($start === null) {
                $start = $prev = $n;
            } elseif ($n === $prev + 1) {
                $prev = $n;
            } else {
                $parts[] = $pre . $start . ($prev !== $start ? '-' . $pre . $prev : '');
                $start = $prev = $n;
            }
        }
        if ($start !== null) {
            $parts[] = $pre . $start . ($prev !== $start ? '-' . $pre . $prev : '');
        }
    }
    natsort($other);
    return implode(',', [...$parts, ...array_values(array_unique($other))]);
}

function vid_list(string $s): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $v) {
        if (preg_match('/^(\d{1,4})-(\d{1,4})$/', $v, $m)) {
            for ($i = (int)$m[1]; $i <= (int)$m[2] && $i <= 4094; $i++) {
                $out[] = $i;
            }
        } elseif (ctype_digit($v) && (int)$v >= 1 && (int)$v <= 4094) {
            $out[] = (int)$v;
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

// --- Vorlagen ----------------------------------------------------------------------------------

/** Port-Aufbau "1-24;Typ;rj45\n…" → Liste der Ports. */
function switch_ports_from_spec(string $spec): array
{
    $ports = [];
    $g = 0;
    foreach (preg_split('/\R/', $spec) ?: [] as $line) {
        $f = array_map('trim', explode(';', $line));
        if (($f[0] ?? '') === '') {
            continue;
        }
        $conn = in_array($f[2] ?? '', ['rj45', 'sfp', 'combo', 'sfpplus'], true) ? $f[2] : 'rj45';
        foreach (port_range_expand($f[0]) as $p) {
            $ports[$p] = ['label' => $p, 'type' => $f[1] ?? '', 'conn' => $conn, 'group' => $g];
        }
        $g++;
    }
    return $ports;
}

function switch_template_list(): array
{
    $out = [];
    foreach (SWITCH_ORIGINALS as $pn => $o) {
        $out['o:' . $pn] = switch_template('o:' . $pn);
    }
    foreach (store_load('switches')['switch_templates'] as $t) {
        $out['c:' . $t['id']] = switch_template('c:' . $t['id']);
    }
    return array_filter($out);
}

/** Vorlage auflösen: 'o:J9085A' (Original) oder 'c:3' (eigene). */
function switch_template(string $ref): ?array
{
    if (preg_match('/^o:([A-Z0-9]+)$/', $ref, $m) && isset(SWITCH_ORIGINALS[$m[1]])) {
        $o = SWITCH_ORIGINALS[$m[1]];
        $o['description'] = t($o['description']);
        $o['console'] = t($o['console']);
        return $o + ['ref' => $ref, 'builtin' => true, 'base' => $m[1], 'settings' => SWITCH_FACTORY, 'port_presets' => '', 'port_list' => switch_ports_from_spec($o['ports'])];
    }
    if (preg_match('/^c:(\d+)$/', $ref, $m)) {
        $t = store_load('switches')['switch_templates'][(int)$m[1]] ?? null;
        if ($t === null) {
            return null;
        }
        $set = json_decode((string)$t['settings'], true);
        return [
            'ref' => $ref, 'builtin' => false, 'id' => (int)$t['id'], 'name' => (string)$t['name'], 'vendor' => (string)$t['vendor'], 'model' => (string)$t['model'],
            'product_no' => (string)$t['product_no'], 'software' => (string)$t['software'], 'cli' => (string)$t['cli'], 'base' => (string)$t['base'],
            'layout' => (string)$t['layout'], 'console' => (string)$t['console'], 'default_hostname' => (string)$t['default_hostname'],
            'ports' => (string)$t['ports'], 'port_list' => switch_ports_from_spec((string)$t['ports']), 'settings' => is_array($set) ? $set : [],
            'port_presets' => (string)$t['port_presets'], 'description' => (string)$t['description'], 'row' => $t,
        ];
    }
    return null;
}

/** Port-Vorgaben "1-22;30;;Arbeitsplatz;on;" → Liste. */
function switch_presets_parse(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $f = array_map('trim', explode(';', $line));
        $out[] = [
            'ports' => port_range_expand($f[0] ?? ''),
            'untagged' => ($f[1] ?? '') === '' ? false : (strtolower($f[1]) === 'none' ? null : (ctype_digit($f[1]) ? (int)$f[1] : false)),
            'tagged' => ($f[2] ?? '') === '' ? false : (strtolower($f[2]) === 'all' ? 'all' : implode(',', vid_list($f[2]))),
            'name' => ($f[3] ?? '') === '' ? false : mb_substr($f[3], 0, 60),
            'enabled' => ($f[4] ?? '') === '' ? false : (in_array(strtolower($f[4]), ['off', 'aus', '0', 'disable'], true) ? 0 : 1),
            'trunk' => ($f[5] ?? '') === '' ? false : (preg_match('/^Trk\d{1,2}$/i', $f[5]) ? 'Trk' . substr($f[5], 3) : false),
        ];
    }
    return $out;
}

// --- Switches ---------------------------------------------------------------------------------

function switch_get(int $id): ?array
{
    return store_load('switches')['switches'][$id] ?? null;
}

function switches_list(): array
{
    $l = array_values(store_load('switches')['switches']);
    usort($l, static fn($a, $b) => strnatcasecmp((string)$a['name'], (string)$b['name']));
    return $l;
}

function switch_ports(int $sid): array
{
    $tpl = ($s = switch_get($sid)) ? switch_template((string)$s['template']) : null;
    $order = $tpl ? array_flip(array_keys($tpl['port_list'])) : [];
    $l = array_values(array_filter(store_load('switches')['switch_ports'], static fn($p) => (int)$p['switch_id'] === $sid));
    usort($l, static fn($a, $b) => [$order[$a['pnum']] ?? 9999, $a['pnum']] <=> [$order[$b['pnum']] ?? 9999, $b['pnum']]);
    return $l;
}

function switch_vlans(int $sid): array
{
    $l = array_values(array_filter(store_load('switches')['switch_vlans'], static fn($v) => (int)$v['switch_id'] === $sid));
    usort($l, static fn($a, $b) => (int)$a['vid'] <=> (int)$b['vid']);
    return $l;
}

function switch_settings(array $sw): array
{
    $s = json_decode((string)$sw['settings'], true);
    return (is_array($s) ? $s : []) + SWITCH_FACTORY;
}

/** VLAN-ID → Netzwerk im Portal (Kurzname, Name). */
function vid_networks(): array
{
    $out = [];
    foreach (net_list() as $slug => $n) {
        if ($n['vlan_id'] !== null && !isset($out[(int)$n['vlan_id']])) {
            $out[(int)$n['vlan_id']] = ['slug' => $slug, 'name' => (string)$n['name'], 'cidr' => (string)$n['cidr']];
        }
    }
    return $out;
}

/** Farbton je VLAN (gleich in Frontansicht, Tabelle und Legende). */
function vid_color(?int $vid): string
{
    if ($vid === null) {
        return 'transparent';
    }
    if ($vid === 1) {
        return 'hsl(0 0% 46%)';
    }
    $h = ($vid * 137) % 360;
    return 'hsl(' . $h . ' 62% 58%)';
}

/** Sicherstellen, dass alle benutzten VLANs in switch_vlans stehen (mit Verweis aufs Netzwerk). */
function switch_ensure_vlans(array &$st, int $sid): void
{
    $have = [];
    foreach ($st['switch_vlans'] as $v) {
        if ((int)$v['switch_id'] === $sid) {
            $have[(int)$v['vid']] = true;
        }
    }
    $need = [1 => true];
    foreach ($st['switch_ports'] as $p) {
        if ((int)$p['switch_id'] !== $sid) {
            continue;
        }
        if ($p['untagged'] !== null) {
            $need[(int)$p['untagged']] = true;
        }
        foreach (vid_list((string)$p['tagged']) as $v) {
            $need[$v] = true;
        }
    }
    $sw = $st['switches'][$sid] ?? null;
    if ($sw && $sw['mgmt_vlan']) {
        $need[(int)$sw['mgmt_vlan']] = true;
    }
    $nets = vid_networks();
    foreach (array_keys($need) as $vid) {
        if (!isset($have[$vid])) {
            store_put('switches', $st, 'switch_vlans', ['switch_id' => $sid, 'vid' => $vid,
                'name' => $vid === 1 ? 'DEFAULT_VLAN' : ($nets[$vid]['name'] ?? 'VLAN' . $vid), 'net' => $nets[$vid]['slug'] ?? null]);
        }
    }
}

/**
 * Vorlage auf einen Switch anwenden.
 * $ports: Port-Vorgaben der Vorlage übernehmen · $settings: Einstellungen übernehmen.
 * Liefert eine Zusammenfassung der Änderungen.
 */
function switch_apply_template(array &$st, int $sid, string $ref, bool $ports, bool $settings): array
{
    $tpl = switch_template($ref);
    if ($tpl === null) {
        throw new RuntimeException(t('Vorlage nicht gefunden.'));
    }
    $sw = $st['switches'][$sid];
    $sum = ['added' => 0, 'removed' => 0, 'changed' => 0, 'settings' => 0];
    // Port-Aufbau
    $existing = [];
    foreach ($st['switch_ports'] as $pid => $p) {
        if ((int)$p['switch_id'] === $sid) {
            if (!isset($tpl['port_list'][$p['pnum']])) {
                unset($st['switch_ports'][$pid]);
                $sum['removed']++;
            } else {
                $existing[$p['pnum']] = $pid;
            }
        }
    }
    foreach ($tpl['port_list'] as $label => $_) {
        if (!isset($existing[$label])) {
            $existing[$label] = store_put('switches', $st, 'switch_ports', ['switch_id' => $sid, 'pnum' => (string)$label, 'enabled' => 1, 'untagged' => 1, 'speed' => 'auto']);
            $sum['added']++;
        }
    }
    // Port-Vorgaben
    if ($ports) {
        $allVids = array_map(static fn($v) => (int)$v['vid'], array_filter($st['switch_vlans'], static fn($v) => (int)$v['switch_id'] === $sid));
        foreach (switch_presets_parse($tpl['port_presets']) as $pr) {
            foreach ($pr['ports'] as $label) {
                if (!isset($existing[$label])) {
                    continue;
                }
                $row = $st['switch_ports'][$existing[$label]];
                $new = $row;
                if ($pr['untagged'] !== false) {
                    $new['untagged'] = $pr['untagged'];
                }
                if ($pr['tagged'] !== false) {
                    $new['tagged'] = $pr['tagged'] === 'all' ? implode(',', array_diff(array_unique([...$allVids, ...array_keys(vid_networks())]), [(int)$new['untagged']])) : $pr['tagged'];
                }
                if ($pr['name'] !== false) {
                    $new['name'] = $pr['name'];
                }
                if ($pr['enabled'] !== false) {
                    $new['enabled'] = $pr['enabled'];
                }
                if ($pr['trunk'] !== false) {
                    $new['trunk'] = $pr['trunk'];
                }
                if ($new !== $row) {
                    store_put('switches', $st, 'switch_ports', $new);
                    $sum['changed']++;
                }
            }
        }
    }
    // Einstellungen
    $cur = switch_settings($sw);
    if ($settings) {
        foreach (SWITCH_SETTING_KEYS as $k) {
            $v = (string)($tpl['settings'][$k] ?? '');
            if ($v !== '' && (string)($cur[$k] ?? '') !== $v) {
                $cur[$k] = $v;
                $sum['settings']++;
            }
        }
    }
    store_put('switches', $st, 'switches', array_merge($sw, ['template' => $ref, 'settings' => json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_at' => now_str(), 'updated_by' => current_actor()]));
    switch_ensure_vlans($st, $sid);
    return $sum;
}

/** Ports eines Switches, an denen ein Gerät aus dem Portal hängt: [net][host_id] => [[switch, port], …] */
function switch_device_map(): array
{
    $st = store_load('switches');
    $out = [];
    foreach ($st['switch_ports'] as $p) {
        if ($p['device_net'] && $p['device_host']) {
            $sw = $st['switches'][(int)$p['switch_id']] ?? null;
            if ($sw) {
                $out[(string)$p['device_net']][(int)$p['device_host']][] = ['switch' => $sw, 'port' => $p];
            }
        }
    }
    return $out;
}

// --- Konfiguration erzeugen ----------------------------------------------------------------------

/** Name für Port/VLAN: ohne Steuerzeichen und Anführungszeichen, gekürzt. */
function switch_clean_name(string $s, int $max): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F"]/u', '', $s) ?? ''), 0, $max);
}

function switch_q(string $s): string
{
    return '"' . str_replace(['"', "\r", "\n"], ['', ' ', ' '], mb_substr($s, 0, 64)) . '"';
}

function cidr_mask_v4(?string $mask): string
{
    $mask = trim((string)$mask);
    if (preg_match('/^\/?(\d{1,2})$/', $mask, $m) && (int)$m[1] <= 32) {
        return long2ip((int)(0xFFFFFFFF << (32 - (int)$m[1]) & 0xFFFFFFFF));
    }
    return filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $mask : '255.255.255.0';
}

/** ProCurve running-config (bzw. Klartext-Portplan bei anderen Herstellern). */
function switch_config(int $sid): string
{
    $sw = switch_get($sid);
    $tpl = switch_template((string)$sw['template']);
    $set = switch_settings($sw);
    $ports = switch_ports($sid);
    $vlans = switch_vlans($sid);
    if (($tpl['cli'] ?? 'generic') !== 'procurve') {
        return switch_config_generic($sw, $tpl, $set, $ports, $vlans);
    }
    $o = [];
    $o[] = '; ' . ($tpl['product_no'] ?: 'Switch') . ' Configuration Editor; Created on release #' . ($sw['firmware'] ?: ($tpl['software'] ?? '') . '.xx');
    $o[] = '; erzeugt mit dem Network Portal ' . APP_VERSION . ' am ' . date('Y-m-d H:i') . ' – ' . $sw['name'];
    $o[] = '';
    $o[] = 'hostname ' . switch_q($set['hostname'] !== '' ? $set['hostname'] : (string)$sw['name']);
    if ($set['timezone'] !== '') {
        $o[] = 'time timezone ' . (int)$set['timezone'];
    }
    if ($set['dst'] !== '') {
        $o[] = 'time daylight-time-rule ' . $set['dst'];
    }
    // Trunks: Mitglied → TrkN
    $trk = [];
    foreach ($ports as $p) {
        if ($p['trunk']) {
            $trk[(string)$p['trunk']][] = (string)$p['pnum'];
        }
    }
    $memberOf = [];
    foreach ($trk as $name => $members) {
        foreach ($members as $m) {
            $memberOf[$m] = $name;
        }
    }
    foreach ($ports as $p) {
        $lines = [];
        if ((string)$p['name'] !== '') {
            $lines[] = '   name ' . switch_q((string)$p['name']);
        }
        if ((int)$p['enabled'] === 0) {
            $lines[] = '   disable';
        }
        if ($p['speed'] && $p['speed'] !== 'auto') {
            $lines[] = '   speed-duplex ' . $p['speed'];
        }
        if ($lines !== []) {
            $o[] = 'interface ' . $p['pnum'];
            array_push($o, ...$lines);
            $o[] = 'exit';
        }
    }
    foreach ($trk as $name => $members) {
        $o[] = 'trunk ' . port_range_compress($members) . ' ' . $name . ' LACP';
    }
    if ($set['gateway'] !== '') {
        $o[] = 'ip default-gateway ' . $set['gateway'];
    }
    if ($set['sntp'] !== '') {
        foreach (array_slice(preg_split('/[\s,]+/', $set['sntp'], -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2) as $srv) {
            $o[] = 'sntp server ' . $srv;
        }
        $o[] = 'timesync sntp';
        $o[] = 'sntp unicast';
    }
    if ($set['snmp_community'] !== '') {
        $o[] = 'snmp-server community ' . switch_q($set['snmp_community']) . ($set['snmp_rw'] === '1' ? ' Unrestricted' : ' Operator');
    }
    if ($set['snmp_location'] !== '') {
        $o[] = 'snmp-server location ' . switch_q($set['snmp_location']);
    }
    if ($set['snmp_contact'] !== '') {
        $o[] = 'snmp-server contact ' . switch_q($set['snmp_contact']);
    }
    if ($set['syslog'] !== '') {
        foreach (preg_split('/[\s,]+/', $set['syslog'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $srv) {
            $o[] = 'logging ' . $srv;
        }
    }
    // VLANs
    $portView = static fn(string $pnum): string => $memberOf[$pnum] ?? $pnum;
    $vlanUntagged = $vlanTagged = [];
    foreach ($ports as $p) {
        $label = $portView((string)$p['pnum']);
        if ($p['untagged'] !== null) {
            $vlanUntagged[(int)$p['untagged']][$label] = $label;
        }
        foreach (vid_list((string)$p['tagged']) as $v) {
            if ($v !== (int)$p['untagged']) {
                $vlanTagged[$v][$label] = $label;
            }
        }
    }
    // Wie bei ProCurve: Ports ohne jede Mitgliedschaft bleiben untagged in VLAN 1,
    // aus VLAN 1 entfernte Ports erscheinen dort als "no untagged".
    $noDefault = [];
    foreach ($ports as $p) {
        $label = $portView((string)$p['pnum']);
        $member = $p['untagged'] !== null || vid_list((string)$p['tagged']) !== [];
        if (!$member && !isset($vlanUntagged[1][$label])) {
            $vlanUntagged[1][$label] = $label;
        } elseif ($member && ($p['untagged'] === null || (int)$p['untagged'] !== 1)) {
            $noDefault[$label] = $label;
        }
    }
    $noDefault = array_diff_key($noDefault, $vlanUntagged[1] ?? []);
    foreach ($vlans as $v) {
        $vid = (int)$v['vid'];
        $o[] = 'vlan ' . $vid;
        $o[] = '   name ' . switch_q((string)($v['name'] ?: ($vid === 1 ? 'DEFAULT_VLAN' : 'VLAN' . $vid)));
        if (!empty($vlanUntagged[$vid])) {
            $o[] = '   untagged ' . port_range_compress(array_values($vlanUntagged[$vid]));
        }
        if (!empty($vlanTagged[$vid])) {
            $o[] = '   tagged ' . port_range_compress(array_values($vlanTagged[$vid]));
        }
        if ($vid === 1 && $noDefault !== []) {
            $o[] = '   no untagged ' . port_range_compress(array_values($noDefault));
        }
        $ip = (string)$v['ip'];
        if ($ip === '' && (int)$sw['mgmt_vlan'] === $vid && $sw['mgmt_ip']) {
            $ip = (string)$sw['mgmt_ip'];
            $v['mask'] = $sw['mgmt_mask'];
        }
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $o[] = '   ip address ' . $ip . ' ' . cidr_mask_v4((string)$v['mask']);
        } elseif ($vid === 1 && !$sw['mgmt_ip']) {
            $o[] = '   ip address dhcp-bootp';
        } else {
            $o[] = '   no ip address';
        }
        $o[] = '   exit';
    }
    if ($set['stp'] === '1') {
        $o[] = 'spanning-tree';
    }
    foreach (preg_split('/[\s,]+/', (string)$set['managers'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $m) {
        $c = cidr_parse(str_contains($m, '/') ? $m : $m . '/32');
        if ($c !== null && $c['bits'] === 32) {
            $o[] = 'ip authorized-managers ' . explode('/', $c['text'])[0] . ' ' . cidr_mask_v4((string)$c['prefix']);
        }
    }
    if ($set['ssh'] === '1') {
        $o[] = 'ip ssh';
    }
    if ($set['telnet'] === '0') {
        $o[] = 'no telnet-server';
    }
    if ($set['web'] === '0') {
        $o[] = 'no web-management';
    }
    if ($set['console_timeout'] !== '') {
        $o[] = 'console inactivity-timer ' . (int)$set['console_timeout'];
    }
    if ($set['ssh'] === '1') {
        $o[] = '';
        $o[] = '; Vor dem ersten SSH-Zugriff einmalig auf dem Switch: crypto key generate ssh';
    }
    $o[] = '; Passwörter setzen: password manager / password operator (werden nie in die Datei geschrieben)';
    return implode("\n", $o) . "\n";
}

function switch_config_generic(array $sw, ?array $tpl, array $set, array $ports, array $vlans): string
{
    $o = ['# Portplan ' . $sw['name'] . ' – ' . ($tpl['name'] ?? '?') . ' – Network Portal ' . APP_VERSION . ' ' . date('Y-m-d H:i'), ''];
    $o[] = '# VLANs';
    foreach ($vlans as $v) {
        $o[] = sprintf('VLAN %-5d %-24s %s', (int)$v['vid'], (string)$v['name'], (string)$v['ip']);
    }
    $o[] = '';
    $o[] = '# Ports: Port | Status | untagged | tagged | Trunk | Name';
    foreach ($ports as $p) {
        $o[] = sprintf('%-6s %-4s %-8s %-20s %-6s %s', $p['pnum'], (int)$p['enabled'] ? 'an' : 'aus', $p['untagged'] ?? '-', $p['tagged'] ?: '-', $p['trunk'] ?: '-', $p['name']);
    }
    $o[] = '';
    $o[] = '# Einstellungen';
    foreach ($set as $k => $v) {
        if ($v !== '' && $k !== 'snmp_community') {
            $o[] = $k . ' = ' . $v;
        }
    }
    return implode("\n", $o) . "\n";
}

// --- running-config einlesen (ProCurve) ---------------------------------------------------------

/** Liefert ['product','firmware','hostname','settings','ports'=>[pnum=>[…]],'vlans'=>[vid=>[…]],'trunks'] */
function switch_parse_procurve(string $cfg): array
{
    $r = ['product' => null, 'firmware' => null, 'unknown' => 0];
    $settings = $ports = $vlans = $trunks = [];
    $lines = preg_split('/\R/', str_replace("\0", '', mb_substr($cfg, 0, 400000))) ?: [];
    $block = null;
    $cur = null;
    foreach ($lines as $raw) {
        $l = trim($raw);
        if ($l === '') {
            continue;
        }
        if (preg_match('/^;\s*(\S+) Configuration Editor; Created on release #(\S+)/', $l, $m)) {
            $r['product'] = $m[1];
            $r['firmware'] = $m[2];
            continue;
        }
        if ($l[0] === ';') {
            continue;
        }
        if ($l === 'exit') {
            $block = $cur = null;
            continue;
        }
        if (preg_match('/^vlan (\d{1,6})$/', $l, $m)) {
            if ((int)$m[1] < 1 || (int)$m[1] > 4094) {
                $block = 'skip';
                $r['unknown']++;
                continue;
            }
            $block = 'vlan';
            $cur = (int)$m[1];
            $vlans[$cur] ??= ['name' => '', 'untagged' => [], 'tagged' => [], 'ip' => '', 'mask' => ''];
            continue;
        }
        if (preg_match('/^interface ([A-Za-z0-9,\-]+)$/', $l, $m)) {
            $block = 'if';
            $cur = port_range_expand($m[1]);
            continue;
        }
        if ($block === 'skip') {
            continue;
        }
        if ($block === 'vlan') {
            if (preg_match('/^name "?([^"]*)"?$/', $l, $m)) {
                $vlans[$cur]['name'] = $m[1];
            } elseif (preg_match('/^untagged (\S+)$/', $l, $m)) {
                $vlans[$cur]['untagged'] = array_merge($vlans[$cur]['untagged'], port_range_expand($m[1]));
            } elseif (preg_match('/^tagged (\S+)$/', $l, $m)) {
                $vlans[$cur]['tagged'] = array_merge($vlans[$cur]['tagged'], port_range_expand($m[1]));
            } elseif (preg_match('/^ip address (\d+\.\d+\.\d+\.\d+) (\d+\.\d+\.\d+\.\d+)$/', $l, $m)) {
                $vlans[$cur]['ip'] = $m[1];
                $vlans[$cur]['mask'] = $m[2];
            }
            continue;
        }
        if ($block === 'if') {
            foreach ($cur as $p) {
                $ports[$p] ??= [];
                if (preg_match('/^name "?([^"]*)"?$/', $l, $m)) {
                    $ports[$p]['name'] = $m[1];
                } elseif ($l === 'disable') {
                    $ports[$p]['enabled'] = 0;
                } elseif (preg_match('/^speed-duplex (\S+)$/', $l, $m)) {
                    $ports[$p]['speed'] = $m[1];
                }
            }
            continue;
        }
        if (preg_match('/^hostname "?([^"]*)"?$/', $l, $m)) {
            $settings['hostname'] = $m[1];
        } elseif (preg_match('/^trunk (\S+) (Trk\d+)/i', $l, $m)) {
            $trunks['Trk' . substr($m[2], 3)] = port_range_expand($m[1]);
        } elseif (preg_match('/^ip default-gateway (\S+)$/', $l, $m)) {
            $settings['gateway'] = $m[1];
        } elseif (preg_match('/^sntp server (?:priority \d+ )?(\S+)/', $l, $m)) {
            $settings['sntp'] = trim(($settings['sntp'] ?? '') . ' ' . $m[1]);
        } elseif (preg_match('/^snmp-server community "([^"]*)"(.*)$/', $l, $m)) {
            $settings['snmp_community'] = $m[1];
            $settings['snmp_rw'] = stripos($m[2], 'Unrestricted') !== false || stripos($m[2], 'Manager') !== false ? '1' : '0';
        } elseif (preg_match('/^snmp-server location "?([^"]*)"?$/', $l, $m)) {
            $settings['snmp_location'] = $m[1];
        } elseif (preg_match('/^snmp-server contact "?([^"]*)"?$/', $l, $m)) {
            $settings['snmp_contact'] = $m[1];
        } elseif (preg_match('/^logging (\d+\.\d+\.\d+\.\d+)$/', $l, $m)) {
            $settings['syslog'] = trim(($settings['syslog'] ?? '') . ' ' . $m[1]);
        } elseif (preg_match('/^time timezone (-?\d+)$/', $l, $m)) {
            $settings['timezone'] = $m[1];
        } elseif (preg_match('/^time daylight-time-rule (\S+)$/', $l, $m)) {
            $settings['dst'] = $m[1];
        } elseif ($l === 'spanning-tree') {
            $settings['stp'] = '1';
        } elseif ($l === 'ip ssh') {
            $settings['ssh'] = '1';
        } elseif ($l === 'no telnet-server') {
            $settings['telnet'] = '0';
        } elseif ($l === 'no web-management') {
            $settings['web'] = '0';
        } elseif (preg_match('/^ip authorized-managers (\S+) (\S+)/', $l, $m)) {
            $bits = substr_count(decbin(ip2long($m[2]) ?: 0), '1');
            $settings['managers'] = trim(($settings['managers'] ?? '') . ' ' . $m[1] . '/' . $bits);
        } elseif (preg_match('/^console inactivity-timer (\d+)$/', $l, $m)) {
            $settings['console_timeout'] = $m[1];
        } else {
            $r['unknown']++;
        }
    }
    return $r + ['settings' => $settings, 'ports' => $ports, 'vlans' => $vlans, 'trunks' => $trunks];
}

/** Eingelesene Konfiguration auf einen Switch übertragen. */
function switch_import(array &$st, int $sid, array $cfg): array
{
    $sw = $st['switches'][$sid];
    if ($cfg['product'] && isset(SWITCH_ORIGINALS[$cfg['product']]) && !str_starts_with((string)$sw['template'], 'c:')) {
        $sw['template'] = 'o:' . $cfg['product'];
    }
    if ($cfg['firmware']) {
        $sw['firmware'] = mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string)$cfg['firmware']) ?? '', 0, 40);
    }
    // eingelesene Werte genauso prüfen wie Formulareingaben
    $set = switch_settings_from_input(array_merge(switch_settings($sw), array_intersect_key($cfg['settings'], array_flip(SWITCH_SETTING_KEYS))));
    $sw['settings'] = json_encode($set, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st['switches'][$sid] = $sw;
    store_put('switches', $st, 'switches', $sw);
    // Port-Aufbau aus der Vorlage
    $sum = switch_apply_template($st, $sid, (string)$sw['template'], false, false);
    $byLabel = [];
    foreach ($st['switch_ports'] as $pid => $p) {
        if ((int)$p['switch_id'] === $sid) {
            $byLabel[(string)$p['pnum']] = $pid;
        }
    }
    // Trunk-Namen auflösen
    $expand = static function (array $list) use ($cfg): array {
        $out = [];
        foreach ($list as $x) {
            $k = preg_match('/^trk(\d+)$/i', $x, $m) ? 'Trk' . $m[1] : null;
            $out = array_merge($out, $k !== null ? ($cfg['trunks'][$k] ?? []) : [$x]);
        }
        return $out;
    };
    $unt = $tag = [];
    foreach ($cfg['vlans'] as $vid => $v) {
        foreach ($expand($v['untagged']) as $p) {
            $unt[$p] = $vid;
        }
        foreach ($expand($v['tagged']) as $p) {
            $tag[$p][] = $vid;
        }
    }
    $trunkOf = [];
    foreach ($cfg['trunks'] as $name => $members) {
        foreach ($members as $m) {
            $trunkOf[$m] = $name;
        }
    }
    $n = 0;
    foreach ($byLabel as $label => $pid) {
        $p = $st['switch_ports'][$pid];
        $pc = $cfg['ports'][$label] ?? [];
        $p['untagged'] = $unt[$label] ?? null;
        $p['tagged'] = isset($tag[$label]) ? implode(',', array_unique($tag[$label])) : null;
        $p['trunk'] = $trunkOf[$label] ?? null;
        $p['name'] = isset($pc['name']) ? switch_clean_name((string)$pc['name'], 60) : $p['name'];
        $p['enabled'] = isset($pc['enabled']) ? ((int)$pc['enabled'] === 0 ? 0 : 1) : 1;
        $p['speed'] = in_array($pc['speed'] ?? 'auto', SWITCH_SPEEDS, true) ? ($pc['speed'] ?? 'auto') : 'auto';
        store_put('switches', $st, 'switch_ports', $p);
        $n++;
    }
    // VLAN-Tabelle
    $nets = vid_networks();
    foreach ($st['switch_vlans'] as $k => $v) {
        if ((int)$v['switch_id'] === $sid) {
            unset($st['switch_vlans'][$k]);
        }
    }
    $sw = $st['switches'][$sid];
    foreach ($cfg['vlans'] as $vid => $v) {
        $ipOk = $v['ip'] !== '' && filter_var($v['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        store_put('switches', $st, 'switch_vlans', ['switch_id' => $sid, 'vid' => $vid, 'name' => switch_clean_name((string)$v['name'], 32) ?: 'VLAN' . $vid, 'net' => $nets[$vid]['slug'] ?? null,
            'ip' => $ipOk ? $v['ip'] : null, 'mask' => $ipOk ? cidr_mask_v4((string)$v['mask']) : null]);
        if ($ipOk && !$sw['mgmt_ip']) {
            $sw['mgmt_ip'] = $v['ip'];
            $sw['mgmt_mask'] = $v['mask'];
            $sw['mgmt_vlan'] = $vid;
            store_put('switches', $st, 'switches', $sw);
        }
    }
    switch_ensure_vlans($st, $sid);
    return ['ports' => $n, 'vlans' => count($cfg['vlans']), 'trunks' => count($cfg['trunks']), 'unknown' => $cfg['unknown'], 'product' => $cfg['product'], 'firmware' => $cfg['firmware']];
}
