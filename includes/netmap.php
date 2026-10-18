<?php
/**
 * NetMap: Wer hängt wo? Aus den vorhandenen Einträgen abgeleitet – nichts wird geraten.
 *
 *   Internet → WAN-Anschluss → Router (Eintrag) → Switch-Port → Gerät …
 *
 * Quellen: WAN-Anschlüsse (Router-Eintrag), Switches (verknüpfter Management-Eintrag)
 * und deren Ports (angeschlossenes Gerät, Netzwerke untagged/tagged), Netzwerke und
 * Einträge. Ein Switch, der an einem Port eines anderen Switches oder Routers hängt,
 * wird über seinen eigenen Eintrag erkannt. Einträge ohne Port-Zuordnung erscheinen
 * getrennt als „ohne Anschluss-Information“.
 */

declare(strict_types=1);

/** Knoten-ID eines Eintrags. */
function nm_hid(string $slug, int $id): string
{
    return 'h:' . $slug . ':' . $id;
}

/**
 * Baut den Graphen.
 * nodes[id] = [type, label, sub, href, net, host, status, vids[]]
 * edges[key] = [a, b, ports[], untagged, tagged[], kind]
 */
function netmap_build(): array
{
    $nets = net_list();
    $nodes = $edges = [];
    $hostIndex = [];
    foreach (array_keys($nets) as $slug) {
        try {
            $net = net_host_index($slug);
        } catch (Throwable $e) {
            continue;
        }
        $st = status_get($slug);
        foreach ($net['hosts'] as $h) {
            $id = nm_hid($slug, (int)$h['id']);
            $s = $st['hosts'][(string)$h['id']] ?? null;
            $nodes[$id] = ['type' => 'host', 'label' => (string)$h['hostname'], 'sub' => (string)$h['ip'], 'net' => $slug, 'host' => $h,
                'href' => url(['p' => 'host', 'n' => $slug, 'id' => $h['id']]), 'status' => $s === null ? null : (bool)$s['ok'], 'kind' => (string)$h['kind']];
            if ((string)$h['ip'] !== '') {
                $hostIndex[(string)$h['ip']][] = $id;
            }
        }
    }
    $nodes['inet'] = ['type' => 'inet', 'label' => 'Internet', 'sub' => '', 'href' => url(['p' => 'wans']), 'net' => null, 'status' => null];

    $addEdge = static function (string $a, string $b, array $info) use (&$edges): void {
        $key = $a < $b ? $a . '|' . $b : $b . '|' . $a;
        if (!isset($edges[$key])) {
            $edges[$key] = ['a' => $a, 'b' => $b, 'ports' => [], 'untagged' => [], 'tagged' => [], 'kind' => $info['kind'], 'trunks' => [], 'via' => $info['via'] ?? null];
        }
        $e = &$edges[$key];
        foreach (['ports', 'untagged', 'tagged'] as $k) {
            $e[$k] = array_values(array_unique([...$e[$k], ...($info[$k] ?? [])]));
        }
        if (!empty($info['trunk'])) {
            $e['trunks'][$a] = $info['trunk'];
        }
    };

    // WAN-Anschlüsse
    $wanStatus = wan_status_all();
    foreach (wan_links() as $w) {
        $id = 'wan:' . $w['id'];
        $s = $wanStatus[(string)$w['id']] ?? null;
        $nodes[$id] = ['type' => 'wan', 'label' => (string)$w['name'], 'sub' => trim($w['provider'] . ' · ' . wan_mbit($w['down_mbit'] === null ? null : (int)$w['down_mbit']), ' ·'),
            'href' => url(['p' => 'wan', 'id' => $w['id']]), 'net' => null, 'status' => $s === null || $s['ok'] === null ? null : (bool)$s['ok'], 'wan' => $w];
        $addEdge('inet', $id, ['kind' => 'wan']);
        if ($w['router_net'] && $w['router_host']) {
            $rid = nm_hid((string)$w['router_net'], (int)$w['router_host']);
            if (isset($nodes[$rid])) {
                $addEdge($id, $rid, ['kind' => 'wan', 'via' => (string)$w['router_iface']]);
                $nodes[$rid]['role'] = 'router';
            }
        }
    }

    // Switches und ihre Ports
    $st = store_load('switches');
    $swNode = [];
    foreach ($st['switches'] as $sw) {
        $id = $sw['net'] && $sw['host_id'] && isset($nodes[nm_hid((string)$sw['net'], (int)$sw['host_id'])]) ? nm_hid((string)$sw['net'], (int)$sw['host_id']) : 'sw:' . $sw['id'];
        $swNode[(int)$sw['id']] = $id;
        $nodes[$id] = ($nodes[$id] ?? ['type' => 'host', 'label' => (string)$sw['name'], 'sub' => (string)$sw['mgmt_ip'], 'net' => $sw['net'] ?: null, 'status' => null, 'kind' => 'Switch']);
        $nodes[$id]['role'] = 'switch';
        $nodes[$id]['switch'] = $sw;
        $nodes[$id]['href'] = url(['p' => 'switch', 'id' => $sw['id']]);
    }
    foreach ($st['switch_ports'] as $p) {
        if (!$p['device_net'] || !$p['device_host'] || !isset($swNode[(int)$p['switch_id']])) {
            continue;
        }
        $dev = nm_hid((string)$p['device_net'], (int)$p['device_host']);
        if (!isset($nodes[$dev])) {
            continue;
        }
        $a = $swNode[(int)$p['switch_id']];
        if ($a === $dev) {
            continue;
        }
        $addEdge($a, $dev, ['kind' => 'port', 'ports' => [$a . '#' . $p['pnum']], 'untagged' => $p['untagged'] !== null ? [(int)$p['untagged']] : [],
            'tagged' => vid_list((string)$p['tagged']), 'trunk' => $p['trunk'] ?: null]);
    }
    return ['nodes' => $nodes, 'edges' => $edges];
}

/**
 * Baum ab „Internet“ (Breitensuche). Teile ohne Weg zum Internet werden an eigene
 * Wurzeln gehängt (Switch ohne Uplink-Zuordnung).
 * Liefert parent[id] = [parentId, edgeKey], children[id] = [ids], roots, depth.
 */
function netmap_tree(array $g): array
{
    $adj = [];
    foreach ($g['edges'] as $k => $e) {
        $adj[$e['a']][] = [$e['b'], $k];
        $adj[$e['b']][] = [$e['a'], $k];
    }
    $parent = $children = $depth = [];
    $order = static function (array $list) use ($g): array {
        usort($list, static function ($x, $y) use ($g) {
            $nx = $g['nodes'][$x[0]];
            $ny = $g['nodes'][$y[0]];
            $rank = static fn($n) => match (true) { $n['type'] === 'wan' => 0, ($n['role'] ?? '') === 'router' => 1, ($n['role'] ?? '') === 'switch' => 2, default => 3 };
            return [$rank($nx), strnatcasecmp((string)$nx['label'], (string)$ny['label'])] <=> [$rank($ny), 0];
        });
        return $list;
    };
    $roots = [];
    $visit = static function (string $root) use (&$parent, &$children, &$depth, $adj, $order): void {
        $depth[$root] = 0;
        $parent[$root] = null;
        $queue = [$root];
        while ($queue) {
            $n = array_shift($queue);
            foreach ($order($adj[$n] ?? []) as [$m, $k]) {
                if (array_key_exists($m, $depth)) {
                    continue;
                }
                $depth[$m] = $depth[$n] + 1;
                $parent[$m] = [$n, $k];
                $children[$n][] = $m;
                $queue[] = $m;
            }
        }
    };
    $visit('inet');
    $roots[] = 'inet';
    // weitere Teilbäume: zuerst Router, dann Switches mit den meisten Verbindungen
    $rest = array_keys(array_filter($g['nodes'], static fn($n, $id) => !array_key_exists($id, $depth) && isset($adj[$id]), ARRAY_FILTER_USE_BOTH));
    usort($rest, static fn($x, $y) => [(($g['nodes'][$x]['role'] ?? '') === 'router' ? 0 : 1), -count($adj[$x])] <=> [(($g['nodes'][$y]['role'] ?? '') === 'router' ? 0 : 1), -count($adj[$y])]);
    foreach ($rest as $id) {
        if (!array_key_exists($id, $depth)) {
            $visit($id);
            $roots[] = $id;
        }
    }
    return ['parent' => $parent, 'children' => $children, 'roots' => $roots, 'depth' => $depth];
}

/** Port-Beschriftung einer Kante aus Sicht des Switches ("Port 25,26 · Trk1"). */
function netmap_edge_label(array $e, array $nodes): string
{
    if ($e['kind'] !== 'port') {
        return (string)($e['via'] ?? '');
    }
    $byNode = [];
    foreach ($e['ports'] as $p) {
        [$n, $pn] = explode('#', $p, 2);
        $byNode[$n][] = $pn;
    }
    $parts = [];
    foreach ($byNode as $n => $list) {
        natsort($list);
        $parts[] = (count($byNode) > 1 ? ($nodes[$n]['label'] ?? '') . ' ' : '') . t('Port {p}', ['p' => port_range_compress(array_values($list))])
            . (isset($e['trunks'][$n]) ? ' · ' . $e['trunks'][$n] : '');
    }
    return implode(' / ', $parts);
}

/** Weg eines Knotens bis zur Wurzel als Liste [[nodeId, edgeKey|null], …] (ohne den Knoten selbst). */
function netmap_path(array $tree, string $id): array
{
    $out = [];
    $guard = 0;
    while (($p = $tree['parent'][$id] ?? null) !== null && $guard++ < 64) {
        $out[] = [$p[0], $p[1]];
        $id = $p[0];
    }
    return $out;
}

/** Weg als lesbarer Text (für Host-Seite und CLI). */
function netmap_path_text(array $g, array $tree, string $id): string
{
    if (!array_key_exists($id, $tree['depth'])) {
        return '';
    }
    $parts = [];
    $cur = $id;
    foreach (netmap_path($tree, $id) as [$pid, $ek]) {
        $e = $g['edges'][$ek];
        $lbl = netmap_edge_label($e, $g['nodes']);
        $parts[] = $g['nodes'][$pid]['label'] . ($lbl !== '' ? ' (' . $lbl . ')' : '');
        $cur = $pid;
    }
    return implode(' → ', $parts);
}

/** Kurzinfo für die Seite eines Eintrags: Weg ins Internet. */
function netmap_host_path(string $slug, int $id): ?array
{
    static $cache = null;
    $cache ??= (static function (): array {
        $g = netmap_build();
        return [$g, netmap_tree($g)];
    })();
    [$g, $tree] = $cache;
    $nid = nm_hid($slug, $id);
    if (!array_key_exists($nid, $tree['depth'])) {
        return null;
    }
    $steps = [];
    foreach (netmap_path($tree, $nid) as [$pid, $ek]) {
        $steps[] = ['node' => $g['nodes'][$pid], 'label' => netmap_edge_label($g['edges'][$ek], $g['nodes'])];
    }
    return ['steps' => $steps, 'internet' => netmap_root($tree, $nid) === 'inet'];
}

function netmap_root(array $tree, string $id): string
{
    $guard = 0;
    while (($p = $tree['parent'][$id] ?? null) !== null && $guard++ < 64) {
        $id = $p[0];
    }
    return $id;
}

/**
 * Verweise auf gelöschte Einträge entfernen (Switch-Ports, Switch-Management, WAN-Router).
 * $id = null: alle Einträge des Netzwerks (Netzwerk gelöscht).
 * Liefert die Anzahl geänderter Zeilen je Bereich.
 */
function infra_forget_host(string $slug, ?int $id): array
{
    $hit = static fn($net, $host): bool => (string)$net === $slug && ($id === null || (int)$host === $id);
    $out = ['switch_ports' => 0, 'switches' => 0, 'wan' => 0];
    $st = store_load('switches', true);
    $need = false;
    foreach ($st['switch_ports'] as $p) {
        $need = $need || ($p['device_net'] && $hit($p['device_net'], $p['device_host']));
    }
    foreach ($st['switches'] as $sw) {
        $need = $need || ($sw['net'] && $sw['host_id'] && $hit($sw['net'], $sw['host_id']));
    }
    if ($need) {
        $r = store_write('switches', static function (array &$st) use ($hit): array {
            $n = ['switch_ports' => 0, 'switches' => 0];
            foreach ($st['switch_ports'] as $p) {
                if ($p['device_net'] && $hit($p['device_net'], $p['device_host'])) {
                    store_put('switches', $st, 'switch_ports', array_merge($p, ['device_net' => null, 'device_host' => null]));
                    $n['switch_ports']++;
                }
            }
            foreach ($st['switches'] as $sw) {
                if ($sw['net'] && $sw['host_id'] && $hit($sw['net'], $sw['host_id'])) {
                    store_put('switches', $st, 'switches', array_merge($sw, ['host_id' => null]));
                    $n['switches']++;
                }
            }
            return $n;
        });
        $out = array_merge($out, $r);
    }
    $wl = array_filter(wan_links(), static fn($w) => $w['router_net'] && $hit($w['router_net'], $w['router_host']));
    if ($wl !== []) {
        $out['wan'] = store_write('wan', static function (array &$st) use ($wl): int {
            foreach ($wl as $w) {
                store_put('wan', $st, 'wan_links', array_merge($st['wan_links'][(int)$w['id']], ['router_net' => null, 'router_host' => null]));
            }
            return count($wl);
        });
    }
    return $out;
}
