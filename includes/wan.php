<?php
/**
 * WAN-Verwaltung: database/wan.sql
 *
 *   wan_templates   eigene Vorlagen (Zugangsart, Anmeldung, VLAN, MTU, IPv4/IPv6, Bandbreite …)
 *   wan_links       Internet-Anschlüsse je Standort: Anbieter, Technik, Adressen, Zugangsdaten
 *                   (Passwort AES-256-GCM-verschlüsselt), Router/Modem, Vertrag, Überwachung
 *
 * Original-Vorlagen sind fest eingebaut (nicht änderbar); eigene Vorlagen lassen sich
 * frei anlegen oder aus einer eingebauten kopieren – wie bei den Switches.
 */

declare(strict_types=1);

store_defs(['wan' => [
    'file' => 'wan.sql', 'ns' => '@wan', 'seal' => 'wan.sql', 'title' => 'WAN-Verwaltung',
    'schema' => [
        'wan_templates' => [
            'id' => 'INTEGER NOT NULL PRIMARY KEY', 'name' => 'TEXT NOT NULL', 'provider' => 'TEXT', 'product' => 'TEXT', 'type' => 'TEXT NOT NULL',
            'auth' => 'TEXT NOT NULL', 'ipv4_mode' => 'TEXT NOT NULL', 'ipv6_mode' => 'TEXT NOT NULL', 'ipv6_len' => 'INTEGER', 'wan_vlan' => 'INTEGER', 'mtu' => 'INTEGER',
            'down_mbit' => 'INTEGER', 'up_mbit' => 'INTEGER', 'priority' => 'INTEGER', 'dns' => 'TEXT', 'user_hint' => 'TEXT', 'modem' => 'TEXT', 'sla' => 'TEXT',
            'base' => 'TEXT', 'description' => 'TEXT',
            'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT', 'row_hash' => 'TEXT NOT NULL',
        ],
        'wan_links' => [
            'id' => 'INTEGER NOT NULL PRIMARY KEY', 'name' => 'TEXT NOT NULL', 'template' => 'TEXT', 'site' => 'TEXT', 'status' => "TEXT NOT NULL DEFAULT 'aktiv'",
            'provider' => 'TEXT', 'product' => 'TEXT', 'type' => 'TEXT NOT NULL', 'auth' => 'TEXT NOT NULL',
            'ipv4_mode' => 'TEXT NOT NULL', 'public_ip' => 'TEXT', 'public_net' => 'TEXT', 'gateway' => 'TEXT',
            'ipv6_mode' => 'TEXT NOT NULL', 'ipv6_prefix' => 'TEXT', 'ipv6_len' => 'INTEGER', 'dns' => 'TEXT',
            'wan_vlan' => 'INTEGER', 'mtu' => 'INTEGER', 'down_mbit' => 'INTEGER', 'up_mbit' => 'INTEGER', 'priority' => 'INTEGER',
            'username' => 'TEXT', 'secret' => 'TEXT', 'router_net' => 'TEXT', 'router_host' => 'INTEGER', 'router_iface' => 'TEXT', 'modem' => 'TEXT',
            'monitor_target' => 'TEXT', 'customer_no' => 'TEXT', 'contract_no' => 'TEXT', 'line_id' => 'TEXT', 'hotline' => 'TEXT', 'sla' => 'TEXT',
            'contract_start' => 'TEXT', 'contract_end' => 'TEXT', 'notice' => 'TEXT', 'cost_month' => 'TEXT', 'notes' => 'TEXT',
            'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT', 'row_hash' => 'TEXT NOT NULL',
        ],
    ],
    'indexes' => ['CREATE INDEX ix_wan_site ON wan_links (site);'],
]]);

/** Zugangstechniken. */
const WAN_TYPES = [
    'vdsl' => 'VDSL / Supervectoring', 'adsl' => 'ADSL', 'ftth' => 'Glasfaser (FTTH)', 'cable' => 'Kabel (DOCSIS)', 'lte' => 'LTE / 5G',
    'sat' => 'Satellit', 'fixed' => 'Standleitung / Ethernet', 'sdsl' => 'SDSL / G.SHDSL', 'other' => 'Sonstige',
];
const WAN_AUTH = ['pppoe' => 'PPPoE', 'dhcp' => 'DHCP (IPoE)', 'static' => 'statisch', 'none' => 'keine (Router des Anbieters)'];
const WAN_IPV4 = ['dynamic' => 'dynamisch (öffentlich)', 'static' => 'feste IP / Netz', 'cgnat' => 'CGNAT (geteilt)', 'dslite' => 'DS-Lite (IPv4 über IPv6)', 'none' => 'kein IPv4'];
const WAN_IPV6 = ['pd' => 'DHCPv6 Prefix Delegation', 'static' => 'festes Präfix', 'slaac' => 'SLAAC (nur Adresse)', 'none' => 'kein IPv6'];
const WAN_STATUS = ['aktiv' => 'aktiv', 'backup' => 'Backup', 'geplant' => 'geplant', 'gekündigt' => 'gekündigt'];

/** Eingebaute Vorlagen (übliche Anschluss-Parameter der Zugangsarten). */
const WAN_ORIGINALS = [
    'tk-vdsl' => [
        'name' => 'Telekom VDSL (Supervectoring)', 'provider' => 'Deutsche Telekom', 'product' => 'MagentaZuhause XL', 'type' => 'vdsl', 'auth' => 'pppoe',
        'ipv4_mode' => 'dynamic', 'ipv6_mode' => 'pd', 'ipv6_len' => 56, 'wan_vlan' => 7, 'mtu' => 1492, 'down_mbit' => 250, 'up_mbit' => 40, 'priority' => 1,
        'dns' => '', 'modem' => 'VDSL-Modem (Bridge)', 'sla' => '',
        'user_hint' => 'Anschlusskennung + T-Online-Nummer + Mitbenutzer-Suffix @t-online.de',
        'description' => 'PPPoE auf VLAN 7, MTU 1492, IPv4 dynamisch, IPv6-Präfix /56 per DHCPv6-PD. Modem im Bridge-Modus, Einwahl am eigenen Router.',
    ],
    'tk-ftth' => [
        'name' => 'Telekom Glasfaser', 'provider' => 'Deutsche Telekom', 'product' => 'MagentaZuhause Glasfaser 1000', 'type' => 'ftth', 'auth' => 'pppoe',
        'ipv4_mode' => 'dynamic', 'ipv6_mode' => 'pd', 'ipv6_len' => 56, 'wan_vlan' => 7, 'mtu' => 1492, 'down_mbit' => 1000, 'up_mbit' => 200, 'priority' => 1,
        'dns' => '', 'modem' => 'Glasfaser-Modem (ONT)', 'sla' => '',
        'user_hint' => 'Anschlusskennung + T-Online-Nummer + Mitbenutzer-Suffix @t-online.de',
        'description' => 'PPPoE auf VLAN 7 hinter dem Glasfaser-Modem (ONT), MTU 1492, IPv6-Präfix /56.',
    ],
    'ftth-ipoe' => [
        'name' => 'Glasfaser FTTH · IPoE', 'provider' => '', 'product' => 'Glasfaser 1000', 'type' => 'ftth', 'auth' => 'dhcp',
        'ipv4_mode' => 'cgnat', 'ipv6_mode' => 'pd', 'ipv6_len' => 56, 'wan_vlan' => null, 'mtu' => 1500, 'down_mbit' => 1000, 'up_mbit' => 500, 'priority' => 1,
        'dns' => '', 'modem' => 'ONT des Netzbetreibers', 'sla' => '',
        'user_hint' => 'keine Zugangsdaten (Anmeldung über die Leitung)',
        'description' => 'IPoE: Adressen per DHCP/DHCPv6 ohne Einwahl, IPv4 oft über CGNAT, IPv6-Präfix /56. VLAN je nach Netzbetreiber.',
    ],
    'cable' => [
        'name' => 'Kabel DOCSIS 3.1', 'provider' => '', 'product' => 'Kabel 1000', 'type' => 'cable', 'auth' => 'dhcp',
        'ipv4_mode' => 'dslite', 'ipv6_mode' => 'pd', 'ipv6_len' => 59, 'wan_vlan' => null, 'mtu' => 1500, 'down_mbit' => 1000, 'up_mbit' => 50, 'priority' => 1,
        'dns' => '', 'modem' => 'Kabelmodem (Bridge-Modus)', 'sla' => '',
        'user_hint' => 'keine – Freischaltung über die MAC-Adresse des Kabelmodems',
        'description' => 'Kabelmodem im Bridge-Modus, Router bekommt Adressen per DHCP. IPv4 meist über DS-Lite, IPv6-Präfixlänge je Anbieter (/56–/62).',
    ],
    'lte-backup' => [
        'name' => 'LTE / 5G Backup', 'provider' => '', 'product' => 'Mobilfunk-Datentarif', 'type' => 'lte', 'auth' => 'dhcp',
        'ipv4_mode' => 'cgnat', 'ipv6_mode' => 'slaac', 'ipv6_len' => 64, 'wan_vlan' => null, 'mtu' => 1500, 'down_mbit' => 150, 'up_mbit' => 50, 'priority' => 2,
        'dns' => '', 'modem' => 'LTE/5G-Router (Bridge/IP-Passthrough)', 'sla' => '',
        'user_hint' => 'APN des Anbieters, SIM-PIN',
        'description' => 'Ausweichleitung (Priorität 2) für Failover. IPv4 über CGNAT – keine eingehenden Verbindungen.',
    ],
    'business-static' => [
        'name' => 'Business mit festen IPs (/29)', 'provider' => '', 'product' => 'Business-Anschluss', 'type' => 'fixed', 'auth' => 'static',
        'ipv4_mode' => 'static', 'ipv6_mode' => 'static', 'ipv6_len' => 48, 'wan_vlan' => null, 'mtu' => 1500, 'down_mbit' => 500, 'up_mbit' => 500, 'priority' => 1,
        'dns' => '', 'modem' => 'Abschlussgerät des Anbieters', 'sla' => 'Entstörung 4 h, 24/7',
        'user_hint' => 'keine – feste Adressen laut Auftragsbestätigung',
        'description' => 'Symmetrische Leitung mit festem IPv4-Netz /29 (5 nutzbare Adressen) und festem IPv6-Präfix /48, Service-Level-Vertrag.',
    ],
    'starlink' => [
        'name' => 'Satellit (Starlink)', 'provider' => 'Starlink', 'product' => 'Standard', 'type' => 'sat', 'auth' => 'dhcp',
        'ipv4_mode' => 'cgnat', 'ipv6_mode' => 'pd', 'ipv6_len' => 56, 'wan_vlan' => null, 'mtu' => 1500, 'down_mbit' => 200, 'up_mbit' => 20, 'priority' => 2,
        'dns' => '', 'modem' => 'Starlink-Router (Bypass-Modus)', 'sla' => '',
        'user_hint' => 'keine Zugangsdaten',
        'description' => 'IPv4 über CGNAT, IPv6-Präfix /56 per DHCPv6-PD. Eigener Router hinter dem Starlink-Router im Bypass-Modus.',
    ],
];

/** Felder, die eine Vorlage vorgibt (beim Anwenden übernommen). */
const WAN_TPL_FIELDS = ['provider', 'product', 'type', 'auth', 'ipv4_mode', 'ipv6_mode', 'ipv6_len', 'wan_vlan', 'mtu', 'down_mbit', 'up_mbit', 'priority', 'dns', 'modem', 'sla'];

function wan_template_list(): array
{
    $out = [];
    foreach (array_keys(WAN_ORIGINALS) as $k) {
        $out['o:' . $k] = wan_template('o:' . $k);
    }
    foreach (store_load('wan')['wan_templates'] as $t) {
        $out['c:' . $t['id']] = wan_template('c:' . $t['id']);
    }
    return array_filter($out);
}

/** 'o:tk-vdsl' (eingebaut) oder 'c:3' (eigene). */
function wan_template(string $ref): ?array
{
    if (preg_match('/^o:([a-z0-9-]+)$/', $ref, $m) && isset(WAN_ORIGINALS[$m[1]])) {
        $o = WAN_ORIGINALS[$m[1]];
        foreach (['name', 'description', 'user_hint', 'modem', 'sla', 'product'] as $k) {
            $o[$k] = $o[$k] !== '' ? t($o[$k]) : '';
        }
        return $o + ['ref' => $ref, 'builtin' => true, 'base' => $m[1]];
    }
    if (preg_match('/^c:(\d+)$/', $ref, $m)) {
        $t = store_load('wan')['wan_templates'][(int)$m[1]] ?? null;
        if ($t === null) {
            return null;
        }
        $o = ['ref' => $ref, 'builtin' => false, 'id' => (int)$t['id'], 'row' => $t];
        foreach (['name', 'provider', 'product', 'type', 'auth', 'ipv4_mode', 'ipv6_mode', 'dns', 'user_hint', 'modem', 'sla', 'base', 'description'] as $k) {
            $o[$k] = (string)$t[$k];
        }
        foreach (['ipv6_len', 'wan_vlan', 'mtu', 'down_mbit', 'up_mbit', 'priority'] as $k) {
            $o[$k] = $t[$k] === null ? null : (int)$t[$k];
        }
        return $o;
    }
    return null;
}

function wan_get(int $id): ?array
{
    return store_load('wan')['wan_links'][$id] ?? null;
}

/** Anschlüsse sortiert nach Standort, Priorität, Name. */
function wan_links(): array
{
    $l = array_values(store_load('wan')['wan_links']);
    usort($l, static fn($a, $b) => [(string)$a['site'], (int)($a['priority'] ?? 9), (string)$a['name']] <=> [(string)$b['site'], (int)($b['priority'] ?? 9), (string)$b['name']]);
    return $l;
}

/** Ziel für die Erreichbarkeitsprüfung: Überwachungsziel, sonst Gateway, sonst öffentliche IP. */
function wan_monitor_target(array $w): ?string
{
    foreach ([(string)$w['monitor_target'], (string)$w['gateway'], (string)$w['public_ip']] as $c) {
        if ($c !== '' && net_target($c) !== null) {
            return net_target($c);
        }
    }
    return null;
}

// --- Status der Prüfung (.cache/wan-status.json) ----------------------------------------------------

function wan_status_all(): array
{
    $f = CACHE_DIR . '/wan-status.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}

function wan_status_put(int $id, array $r): void
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0770, true);
    }
    $f = CACHE_DIR . '/wan-status.json';
    $fh = fopen($f, 'c+');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fh), true);
    $d = is_array($d) ? $d : [];
    $d[(string)$id] = $r;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)json_encode($d, JSON_UNESCAPED_SLASHES));
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Prüft einen Anschluss per Ping (Ergebnis im Status-Cache). */
function wan_check(array $w): array
{
    $t = wan_monitor_target($w);
    if ($t === null) {
        return ['ok' => null, 'target' => null, 'avg' => null, 'loss' => null, 'ts' => time(), 'error' => t('Kein Prüfziel (Überwachung, Gateway oder öffentliche IP) eingetragen.')];
    }
    $r = net_ping($t);
    $res = ['ok' => (bool)$r['ok'], 'target' => $t, 'avg' => $r['avg'], 'loss' => $r['loss'], 'method' => $r['method'], 'ts' => time()];
    wan_status_put((int)$w['id'], $res);
    return $res + ['output' => $r['output'] ?? ''];
}

/** Tage bis Vertragsende (null = unbekannt). */
function wan_days_left(array $w): ?int
{
    $e = (string)$w['contract_end'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) {
        return null;
    }
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $e);
    if ($end === false) {
        return null;
    }
    return (int)floor(($end->getTimestamp() - strtotime('today')) / 86400);
}

/** Kündigungsfrist in Tagen ("3 Monate", "1 Monat", "4 Wochen", "30 Tage"). */
function wan_notice_days(string $n): int
{
    if (preg_match('/(\d+)\s*(monat|month|woche|week|tag|day)/i', $n, $m)) {
        $k = strtolower(substr($m[2], 0, 3));
        return (int)$m[1] * (in_array($k, ['mon'], true) ? 30 : (in_array($k, ['woc', 'wee'], true) ? 7 : 1));
    }
    return 90;
}

/** Hinweise je Anschluss (Vertrag läuft aus, Kündigungsfrist, fehlende Daten). */
function wan_warnings(array $w): array
{
    $out = [];
    $d = wan_days_left($w);
    if ($d !== null && (string)$w['status'] !== 'gekündigt') {
        $nd = wan_notice_days((string)$w['notice']);
        if ($d < 0) {
            $out[] = ['bad', t('Vertragsende überschritten ({d})', ['d' => (string)$w['contract_end']])];
        } elseif ($d <= $nd) {
            $out[] = ['bad', t('Kündigungsfrist ({f}) unterschritten: noch {n} Tage bis Vertragsende', ['f' => (string)$w['notice'] ?: '3 Monate', 'n' => $d])];
        } elseif ($d <= $nd + 60) {
            $out[] = ['warn', t('Vertragsende in {n} Tagen – Kündigung/Verlängerung prüfen', ['n' => $d])];
        }
    }
    if ((string)$w['auth'] === 'pppoe' && (string)$w['username'] === '') {
        $out[] = ['warn', t('PPPoE ohne Benutzername')];
    }
    if ((string)$w['ipv4_mode'] === 'static' && (string)$w['public_ip'] === '') {
        $out[] = ['warn', t('Feste IP angegeben, aber keine Adresse eingetragen')];
    }
    return $out;
}

/** Anzahl kritischer Hinweise für die Navigation. */
function wan_nav_badge(): string
{
    $n = 0;
    try {
        foreach (wan_links() as $w) {
            foreach (wan_warnings($w) as $x) {
                if ($x[0] === 'bad') {
                    $n++;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        return '';
    }
    return $n > 0 ? '<i class="health-dot down" title="' . th('{n} Anschluss/Anschlüsse mit Vertragshinweis', ['n' => $n]) . '"></i>' : '';
}

/** Nutzbare Adressen eines festen IPv4-Netzes ("203.0.113.8/29"). */
function wan_usable(string $cidr): ?array
{
    $c = cidr_parse($cidr);
    if ($c === null || $c['bits'] !== 32 || $c['prefix'] > 30) {
        return null;
    }
    $network = (string)inet_ntop($c['net']);
    $net = (int)ip2long($network);
    $size = 2 ** (32 - $c['prefix']);
    return ['first' => long2ip($net + 1), 'last' => long2ip($net + $size - 2), 'count' => $size - 2, 'broadcast' => long2ip($net + $size - 1), 'network' => $network, 'prefix' => $c['prefix']];
}

/** Einstellungen am Router als Klartext (für Einrichtung/Dokumentation; Passwort nur als Platzhalter). */
function wan_router_sheet(array $w): string
{
    $lab = static fn(string $s, int $ind = 0): string => str_repeat(' ', $ind) . mb_str_pad($s, 15 - $ind) . ': ';
    $L = [];
    $L[] = '# ' . $w['name'] . ((string)$w['site'] !== '' ? ' · ' . $w['site'] : '');
    $L[] = '# ' . trim($w['provider'] . ' ' . $w['product']) . ' · ' . t(WAN_TYPES[$w['type']] ?? $w['type']);
    $L[] = '';
    $L[] = $lab(t('Interface')) . ((string)$w['router_iface'] !== '' ? $w['router_iface'] : 'wan') . ($w['wan_vlan'] ? '.' . (int)$w['wan_vlan'] . '   (VLAN ' . (int)$w['wan_vlan'] . ' tagged)' : '');
    $L[] = $lab(t('Anmeldung')) . t(WAN_AUTH[$w['auth']] ?? $w['auth']);
    if ($w['auth'] === 'pppoe') {
        $L[] = $lab(t('PPPoE-Benutzer')) . $w['username'];
        $L[] = $lab(t('PPPoE-Passwort')) . ((string)$w['secret'] !== '' ? t('<hinterlegt im Portal>') : t('<fehlt>'));
    }
    $L[] = $lab(t('MTU')) . ($w['mtu'] ?: 1500) . ($w['auth'] === 'pppoe' ? '   (MSS-Clamping ' . (($w['mtu'] ?: 1492) - 40) . ')' : '');
    $L[] = $lab(t('IPv4')) . t(WAN_IPV4[$w['ipv4_mode']] ?? $w['ipv4_mode']);
    if ($w['ipv4_mode'] === 'static') {
        $L[] = $lab(t('Adresse'), 2) . $w['public_ip'];
        if (($u = wan_usable((string)$w['public_net'])) !== null) {
            $L[] = $lab(t('Netz'), 2) . $w['public_net'] . '  (' . t('nutzbar {a} – {b} ({n} Adressen)', ['a' => $u['first'], 'b' => $u['last'], 'n' => $u['count']]) . ')';
        }
        $L[] = $lab(t('Gateway'), 2) . $w['gateway'];
    }
    $L[] = $lab(t('IPv6')) . t(WAN_IPV6[$w['ipv6_mode']] ?? $w['ipv6_mode']) . ($w['ipv6_len'] && $w['ipv6_mode'] !== 'none' ? '  /' . (int)$w['ipv6_len'] : '') . ((string)$w['ipv6_prefix'] !== '' ? '  ' . $w['ipv6_prefix'] : '');
    $L[] = $lab(t('DNS')) . ((string)$w['dns'] !== '' ? $w['dns'] : t('vom Anbieter'));
    $L[] = $lab(t('Bandbreite')) . (int)$w['down_mbit'] . ' / ' . (int)$w['up_mbit'] . ' Mbit/s (Down/Up)';
    $L[] = $lab(t('Priorität')) . (int)($w['priority'] ?: 1) . '  (' . ((int)$w['priority'] > 1 ? t('Failover') : t('primär')) . ')';
    if ((string)$w['monitor_target'] !== '') {
        $L[] = $lab(t('Überwachung')) . 'ping ' . $w['monitor_target'];
    }
    return implode("\n", $L) . "\n";
}

/** Linux-Konfiguration (pppd-Peer bzw. systemd-networkd) als Vorschlag. */
function wan_linux_config(array $w): string
{
    $if = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$w['router_iface']) ?: 'eth1';
    $dev = $w['wan_vlan'] ? $if . '.' . (int)$w['wan_vlan'] : $if;
    $o = '';
    if ($w['wan_vlan']) {
        $o .= "# /etc/systemd/network/10-wan-vlan.netdev\n[NetDev]\nName={$dev}\nKind=vlan\n\n[VLAN]\nId=" . (int)$w['wan_vlan'] . "\n\n"
            . "# /etc/systemd/network/10-{$if}.network  (Zeile ergänzen)\n[Network]\nVLAN={$dev}\n\n";
    }
    if ($w['auth'] === 'pppoe') {
        $mtu = (int)($w['mtu'] ?: 1492);
        $o .= "# /etc/ppp/peers/wan\nplugin pppoe.so\nnic-{$dev}\nuser \"" . str_replace('"', '', (string)$w['username']) . "\"\n"
            . "noipdefault\ndefaultroute\nreplacedefaultroute\nhide-password\npersist\nmaxfail 0\nholdoff 5\nnoauth\nmtu {$mtu}\nmru {$mtu}\n"
            . ($w['ipv6_mode'] !== 'none' ? "+ipv6\nipv6cp-use-ipaddr\n" : '')
            . "lcp-echo-interval 15\nlcp-echo-failure 4\n\n# /etc/ppp/chap-secrets (" . t('Passwort aus dem Portal eintragen, Rechte 600') . ")\n\"" . str_replace('"', '', (string)$w['username']) . "\" * \"<PASSWORT>\" *\n";
    } else {
        $o .= "# /etc/systemd/network/20-wan.network\n[Match]\nName={$dev}\n\n[Network]\n";
        if ($w['ipv4_mode'] === 'static') {
            $u = wan_usable((string)$w['public_net']);
            $o .= 'Address=' . $w['public_ip'] . '/' . ($u['prefix'] ?? 32) . "\nGateway=" . $w['gateway'] . "\n";
        } elseif ($w['ipv4_mode'] !== 'none' && $w['ipv4_mode'] !== 'dslite') {
            $o .= "DHCP=" . ($w['ipv6_mode'] === 'pd' ? 'yes' : 'ipv4') . "\n";
        } else {
            $o .= "DHCP=ipv6\n";
        }
        if ($w['ipv6_mode'] !== 'none') {
            $o .= "IPv6AcceptRA=yes\n";
        }
        foreach (preg_split('/[\s,]+/', (string)$w['dns'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $d) {
            $o .= "DNS={$d}\n";
        }
        if ($w['mtu']) {
            $o .= "\n[Link]\nMTUBytes=" . (int)$w['mtu'] . "\n";
        }
        if ($w['ipv6_mode'] === 'pd') {
            $o .= "\n[DHCPv6]\nPrefixDelegationHint=::/" . (int)($w['ipv6_len'] ?: 56) . "\n";
        }
    }
    return $o;
}

/** Anschlüsse, die an einem Eintrag (Router) hängen – für die Host-Seite. */
function wan_by_router(string $slug, int $hostId): array
{
    return array_values(array_filter(wan_links(), static fn($w) => (string)$w['router_net'] === $slug && (int)$w['router_host'] === $hostId));
}
