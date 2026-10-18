<?php
/**
 * NetMap – Plan der Verbindungen: Internet → WAN → Router → Switches → Geräte,
 * abgeleitet aus WAN-Anschlüssen, Switch-Ports und Einträgen.
 */

declare(strict_types=1);

const NM_COL = 252;
const NM_ROW = 50;
const NM_W = 200;
const NM_H = 38;

function nm_short(string $s, int $n): string
{
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

/** Icon eines Knotens als SVG-Fragment (eigene Bild-Icons als <image>). */
function nm_icon(array $n, int $size): string
{
    if ($n['type'] === 'inet') {
        return icon('globe', $size);
    }
    if ($n['type'] === 'more') {
        return icon('list', $size);
    }
    if ($n['type'] === 'wan') {
        return icon(wan_type_icon((string)$n['wan']['type']), $size);
    }
    if (($n['role'] ?? '') === 'switch' && !isset($n['host'])) {
        return icon('switch', $size);
    }
    $h = $n['host'] ?? [];
    if (preg_match('/^c:([0-9a-f]{40}\.(png|jpg|webp|svg))$/', (string)($h['icon'] ?? ''), $m)) {
        return '<image href="' . h(url(['a' => 'icon', 'f' => $m[1]])) . '" width="' . $size . '" height="' . $size . '"/>';
    }
    return host_icon_html($h, $size);
}

/** Layout: links → rechts, Blätter untereinander, Eltern mittig zu ihren Kindern. */
function nm_layout(array $tree): array
{
    $pos = [];
    $row = 0;
    $place = static function (string $id) use (&$place, &$pos, &$row, $tree): float {
        $kids = $tree['children'][$id] ?? [];
        if ($kids === []) {
            $y = $row++;
        } else {
            $ys = array_map($place, $kids);
            $y = (min($ys) + max($ys)) / 2;
        }
        $pos[$id] = ['x' => $tree['depth'][$id], 'y' => $y];
        return $y;
    };
    foreach ($tree['roots'] as $r) {
        $place($r);
        $row++;
    }
    return [$pos, max(1, $row - 1)];
}

/**
 * Für die Zeichnung: hängen an einem Knoten mehr als $max Endgeräte, werden die übrigen zu
 * einem Knoten „+N weitere“ zusammengefasst (die Liste darunter bleibt vollständig).
 */
function nm_collapse(array $tree, array &$g, string $keep, int $max = 12): array
{
    foreach ($tree['children'] as $pid => $kids) {
        $leaves = array_values(array_filter($kids, static fn($k) => empty($tree['children'][$k])));
        if (count($leaves) <= $max) {
            continue;
        }
        $show = array_slice($leaves, 0, $max);
        if ($keep !== '' && in_array($keep, $leaves, true) && !in_array($keep, $show, true)) {
            $show[$max - 1] = $keep;
        }
        $hide = array_diff($leaves, $show);
        $vid = 'more:' . $pid;
        $g['nodes'][$vid] = ['type' => 'more', 'label' => t('+{n} weitere', ['n' => count($hide)]), 'sub' => t('alle in der Liste unten'),
            'href' => '#nmTable', 'net' => null, 'status' => null];
        $g['edges'][$vid] = ['a' => $pid, 'b' => $vid, 'ports' => [], 'untagged' => [], 'tagged' => [], 'kind' => 'more', 'trunks' => [], 'via' => null];
        $tree['children'][$pid] = [...array_values(array_diff($kids, $hide)), $vid];
        foreach ($hide as $h) {
            unset($tree['depth'][$h], $tree['parent'][$h]);
        }
        $tree['depth'][$vid] = $tree['depth'][$pid] + 1;
        $tree['parent'][$vid] = [$pid, $vid];
    }
    return $tree;
}

function nm_edge_color(array $e): array
{
    if ($e['kind'] === 'wan') {
        return ['var(--accent)', false];
    }
    if (count($e['untagged']) === 1) {
        return [vid_color($e['untagged'][0]), $e['tagged'] !== []];
    }
    return [$e['tagged'] !== [] ? 'var(--info)' : 'var(--text-faint)', $e['tagged'] !== []];
}

function page_netmap(): void
{
    $g = netmap_build();
    $tree = netmap_tree($g);
    $nodes = $g['nodes'];
    $vnets = vid_networks();
    $netList = net_list();
    $filter = get_str('net', 60);
    if ($filter !== '' && !isset($netList[$filter])) {
        $filter = '';
    }
    $focus = get_str('focus', 120);
    if ($focus !== '' && !array_key_exists($focus, $tree['depth'])) {
        $focus = '';
    }

    // Hervorhebung: Netzwerk-Filter (Einträge im Netzwerk + Wege dorthin) oder Weg eines Geräts
    $hl = [];
    $hlEdges = [];
    $mark = static function (string $id) use (&$hl, &$hlEdges, $tree): void {
        $hl[$id] = true;
        foreach (netmap_path($tree, $id) as [$pid, $ek]) {
            $hl[$pid] = true;
            $hlEdges[$ek] = true;
        }
    };
    if ($focus !== '') {
        $mark($focus);
    } elseif ($filter !== '') {
        $vid = $netList[$filter]['vlan_id'];
        foreach ($tree['depth'] as $id => $_) {
            if (($nodes[$id]['net'] ?? null) === $filter) {
                $mark($id);
            }
        }
        foreach ($g['edges'] as $k => $e) {
            if ($vid !== null && (in_array((int)$vid, $e['untagged'], true) || in_array((int)$vid, $e['tagged'], true))) {
                $hlEdges[$k] = true;
                $hl[$e['a']] = $hl[$e['b']] = true;
            }
        }
    }
    $dimming = $focus !== '' || $filter !== '';

    // Kennzahlen
    $hosts = array_filter($nodes, static fn($n) => $n['type'] === 'host');
    $inTree = array_filter($hosts, static fn($n, $id) => array_key_exists($id, $tree['depth']), ARRAY_FILTER_USE_BOTH);
    $withInet = array_filter($inTree, static fn($n, $id) => netmap_root($tree, $id) === 'inet', ARRAY_FILTER_USE_BOTH);
    $loose = array_diff_key($hosts, $inTree);
    $switchCount = count(array_filter($nodes, static fn($n) => ($n['role'] ?? '') === 'switch'));

    page_start(t('NetMap'), 'netmap', [[t('NetMap'), null]]);
    heading(t('NetMap'), th('Wer hängt wo? Abgeleitet aus WAN-Anschlüssen, Switch-Ports und Einträgen'),
        (can('edit') ? btn(t('Switches'), url(['p' => 'switches']), 'secondary', 'switch') . btn(t('WAN-Anschlüsse'), url(['p' => 'wans']), 'secondary', 'globe') : ''));
    echo '<div class="stat-grid">'
        . stat_tile(t('Mit Weg ins Internet'), (string)count($withInet), t('von {n} Einträgen', ['n' => count($hosts)]), 'globe', count($withInet) ? 'ok' : '')
        . stat_tile(t('Ohne Weg ins Internet'), (string)(count($inTree) - count($withInet)), t('verbunden, aber kein Uplink zum Router'), 'topology', count($inTree) - count($withInet) ? 'warn' : '')
        . stat_tile(t('Ohne Anschluss-Info'), (string)count($loose), t('keinem Switch-Port zugeordnet'), 'list', '')
        . stat_tile(t('Switches · WAN'), $switchCount . ' · ' . count(array_filter($nodes, static fn($n) => $n['type'] === 'wan')), t('im Plan'), 'switch', '')
        . '</div>';

    // Filter
    $opts = ['' => '— ' . t('alle Netzwerke') . ' —'];
    foreach ($netList as $slug => $n) {
        $opts[$slug] = $n['name'] . ($n['vlan_id'] !== null ? ' · VLAN ' . $n['vlan_id'] : '');
    }
    echo '<form method="get" action="./" class="nm-filter"><input type="hidden" name="p" value="netmap">'
        . f_select('net', t('Netzwerk hervorheben'), $opts, $filter, ['id' => 'nmNet'])
        . '<button class="pill-btn secondary small" type="submit">' . icon('search', 14) . th('Anzeigen') . '</button>'
        . ($dimming ? btn(t('Alles zeigen'), url(['p' => 'netmap']), 'ghost small') : '')
        . ($focus !== '' ? '<span class="nm-focus">' . th('Weg von {n}', ['n' => $nodes[$focus]['label']]) . '</span>' : '') . '</form>';

    // Plan (SVG)
    $draw = nm_collapse($tree, $g, $focus);
    $nodes = $g['nodes'];
    [$pos, $rows] = nm_layout($draw);
    $maxDepth = $draw['depth'] === [] ? 0 : max($draw['depth']);
    $W = ($maxDepth + 1) * NM_COL + 30;
    $H = ($rows + 1) * NM_ROW + 30;
    $px = static fn(string $id): array => [16 + $pos[$id]['x'] * NM_COL, 16 + $pos[$id]['y'] * NM_ROW];
    $svg = '<svg class="nm-svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img" aria-label="' . th('Verbindungsplan') . '">';
    // Kanten
    foreach ($draw['parent'] as $id => $p) {
        if ($p === null) {
            continue;
        }
        [$pid, $ek] = $p;
        $e = $g['edges'][$ek];
        [$x1, $y1] = $px($pid);
        [$x2, $y2] = $px($id);
        $x1 += NM_W;
        $y1 += NM_H / 2;
        $y2 += NM_H / 2;
        $mx = ($x1 + $x2) / 2;
        [$col, $dashed] = nm_edge_color($e);
        $cls = 'nm-edge' . ($dimming && !isset($hlEdges[$ek]) ? ' dim' : '') . ($dimming && isset($hlEdges[$ek]) ? ' hl' : '');
        $lbl = netmap_edge_label($e, $nodes);
        $vl = array_map(static fn($v) => (string)$v, $e['untagged']);
        $tip = $nodes[$pid]['label'] . ' → ' . $nodes[$id]['label'] . ($lbl !== '' ? ' · ' . $lbl : '')
            . ($e['untagged'] !== [] ? ' · untagged ' . implode(',', $vl) : '') . ($e['tagged'] !== [] ? ' · tagged ' . implode(',', $e['tagged']) : '');
        $svg .= '<g class="' . $cls . '"><title>' . h($tip) . '</title><path d="M' . $x1 . ' ' . $y1 . ' C' . $mx . ' ' . $y1 . ' ' . $mx . ' ' . $y2 . ' ' . $x2 . ' ' . $y2 . '" style="stroke:' . $col . '"' . ($dashed ? ' stroke-dasharray="6 4"' : '') . '/>';
        if ($lbl !== '' || $e['untagged'] !== []) {
            $txt = nm_short($lbl, 26) . ($e['untagged'] !== [] ? ($lbl !== '' ? ' · ' : '') . implode(',', $vl) : '') . ($e['tagged'] !== [] ? ' +' . count($e['tagged']) . 'T' : '');
            $svg .= '<text x="' . ($x2 - 6) . '" y="' . ($y2 - 6) . '" text-anchor="end" class="nm-elabel">' . h($txt) . '</text>';
        }
        $svg .= '</g>';
    }
    // weitere Verbindungen, die nicht im Baum liegen (z. B. zweiter WAN-Anschluss am selben Router)
    $used = [];
    foreach ($draw['parent'] as $p) {
        if ($p !== null) {
            $used[$p[1]] = true;
        }
    }
    foreach ($g['edges'] as $ek => $e) {
        if (isset($used[$ek]) || !isset($pos[$e['a']], $pos[$e['b']])) {
            continue;
        }
        [$a, $b] = $pos[$e['a']]['x'] <= $pos[$e['b']]['x'] ? [$e['a'], $e['b']] : [$e['b'], $e['a']];
        [$x1, $y1] = $px($a);
        [$x2, $y2] = $px($b);
        $x1 += NM_W;
        $y1 += NM_H / 2;
        $y2 += NM_H / 2;
        if ($x2 <= $x1) {
            $x2 += NM_W / 2;
            $y2 += $y2 > $y1 ? -NM_H / 2 : NM_H / 2;
        }
        $mx = ($x1 + $x2) / 2;
        [$col] = nm_edge_color($e);
        $lbl = netmap_edge_label($e, $nodes);
        $svg .= '<g class="nm-edge nm-extra' . ($dimming ? ' dim' : '') . '"><title>' . h(t('weitere Verbindung') . ': ' . $nodes[$a]['label'] . ' – ' . $nodes[$b]['label'] . ($lbl !== '' ? ' · ' . $lbl : '')) . '</title>'
            . '<path d="M' . $x1 . ' ' . $y1 . ' C' . $mx . ' ' . $y1 . ' ' . $mx . ' ' . $y2 . ' ' . $x2 . ' ' . $y2 . '" style="stroke:' . $col . '" stroke-dasharray="2 5"/></g>';
    }
    // Knoten
    foreach ($pos as $id => $_) {
        $n = $nodes[$id];
        [$x, $y] = $px($id);
        $role = $n['type'] === 'host' ? ($n['role'] ?? 'host') : $n['type'];
        $cls = 'nm-node nm-' . $role . ($dimming && !isset($hl[$id]) ? ' dim' : '') . ($id === $focus ? ' focus' : '');
        $netVid = $n['net'] !== null ? ($netList[$n['net']]['vlan_id'] ?? null) : null;
        $sub = $n['type'] === 'host' ? trim((string)$n['sub'] . ($n['net'] !== null ? ' · ' . ($netList[$n['net']]['name'] ?? $n['net']) : ''), ' ·') : (string)$n['sub'];
        $dot = $n['status'] === null ? 'unknown' : ($n['status'] ? 'up' : 'down');
        $svg .= '<a href="' . h((string)$n['href']) . '" class="' . $cls . '"><title>' . h($n['label'] . ($sub !== '' ? ' · ' . $sub : '')) . '</title>'
            . '<rect x="' . $x . '" y="' . $y . '" width="' . NM_W . '" height="' . NM_H . '" rx="8"/>'
            . ($netVid !== null ? '<rect class="nm-vbar" x="' . $x . '" y="' . ($y + 6) . '" width="3" height="' . (NM_H - 12) . '" rx="1.5" style="fill:' . vid_color((int)$netVid) . '"/>' : '')
            . '<g transform="translate(' . ($x + 10) . ' ' . ($y + 11) . ')" class="nm-ic">' . nm_icon($n, 16) . '</g>'
            . '<text x="' . ($x + 34) . '" y="' . ($y + 16) . '" class="nm-label">' . h(nm_short((string)$n['label'], 22)) . '</text>'
            . '<text x="' . ($x + 34) . '" y="' . ($y + 30) . '" class="nm-sub">' . h(nm_short($sub, 30)) . '</text>'
            . ($n['type'] !== 'inet' ? '<circle cx="' . ($x + NM_W - 11) . '" cy="' . ($y + 11) . '" r="4" class="nm-dot ' . $dot . '"/>' : '')
            . '</a>';
    }
    $svg .= '</svg>';
    echo '<section class="card nm-card" id="map"><div class="nm-scroll">' . ($pos === [] ? '<p class="dim">' . th('Noch keine Verbindungen erfasst.') . '</p>' : $svg) . '</div>'
        . '<div class="legend nm-legend"><span class="legend-title">' . th('Legende') . '</span>'
        . '<span class="legend-item"><i class="nm-l solid"></i>' . th('Port untagged (Farbe = Netzwerk)') . '</span>'
        . '<span class="legend-item"><i class="nm-l dashed"></i>' . th('mit tagged Netzwerken (Trunk)') . '</span>'
        . '<span class="legend-item"><i class="nm-l wan"></i>' . th('WAN-Anschluss') . '</span>'
        . '<span class="legend-item"><i class="nm-l dotted"></i>' . th('weitere Verbindung (z. B. Backup-Leitung)') . '</span>'
        . '<span class="legend-item"><i class="dot up"></i>' . th('online') . ' <i class="dot down"></i>' . th('offline') . ' <i class="dot unknown"></i>' . th('ungeprüft') . '</span>'
        . '<span class="legend-item">' . th('„+2T“ = zwei weitere Netzwerke tagged') . '</span></div>'
        . '<p class="hint">' . th('Grundlage: Router im WAN-Anschluss, verknüpfter Eintrag je Switch und „Angeschlossenes Gerät“ je Switch-Port. Ein Switch, der an einem Port eines anderen Switches hängt, wird darüber als Uplink erkannt.') . '</p></section>';

    // Verbindungsliste
    echo '<h2 class="section-label spaced">' . th('Verbindungen ({n})', ['n' => count($inTree)]) . '</h2>';
    echo '<div class="table-wrap"><table class="data-table compact" id="nmTable"><thead><tr><th class="col-dot"></th><th>' . th('Gerät') . '</th><th>' . th('Netzwerk') . '</th><th>' . th('IP-Adresse') . '</th><th>' . th('Hängt an') . '</th><th>' . th('Weg ins Internet') . '</th><th class="col-act"></th></tr></thead><tbody>';
    $list = $inTree;
    uasort($list, static fn($a, $b) => strnatcasecmp((string)$a['label'], (string)$b['label']));
    foreach ($list as $id => $n) {
        $p = $tree['parent'][$id] ?? null;
        $at = '<span class="dim">' . th('Anfang des Plans') . '</span>';
        if ($p !== null) {
            $lbl = netmap_edge_label($g['edges'][$p[1]], $nodes);
            $at = '<a href="' . h((string)$nodes[$p[0]]['href']) . '">' . h($nodes[$p[0]]['label']) . '</a>' . ($lbl !== '' ? ' <span class="dim small">' . h($lbl) . '</span>' : '');
        }
        $chain = [];
        foreach (netmap_path($tree, $id) as [$pid, $_]) {
            $chain[] = h($nodes[$pid]['label']);
        }
        $inet = netmap_root($tree, $id) === 'inet';
        $net = $n['net'] !== null ? ($netList[$n['net']] ?? null) : null;
        $dot = $n['status'] === null ? 'unknown' : ($n['status'] ? 'up' : 'down');
        echo '<tr><td class="col-dot"><i class="dot ' . $dot . '"></i></td><td><div class="cell-title"><a href="' . h((string)$n['href']) . '">' . h($n['label']) . '</a>'
            . (($n['role'] ?? '') === 'switch' ? ' <span class="badge dim">Switch</span>' : (($n['role'] ?? '') === 'router' ? ' <span class="badge dim">Router</span>' : '')) . '</div></td>'
            . '<td>' . ($net ? vid_chip($net['vlan_id'] === null ? null : (int)$net['vlan_id'], $vnets) . ($net['vlan_id'] === null ? h($net['name']) : '') : '') . '</td>'
            . '<td class="mono">' . h((string)$n['sub']) . '</td><td>' . $at . '</td>'
            . '<td class="small">' . ($chain !== [] ? implode(' <span class="dim">→</span> ', $chain) : '') . ($inet ? '' : ' <span class="badge warn">' . th('kein Weg ins Internet') . '</span>') . '</td>'
            . '<td class="col-act"><a class="icon-btn" href="' . h(url(['p' => 'netmap', 'focus' => $id]) . '#map') . '" title="' . th('Weg im Plan zeigen') . '" aria-label="' . th('Weg im Plan zeigen') . '">' . icon('topology', 14) . '</a></td></tr>';
    }
    echo '</tbody></table></div>';

    // Ohne Anschluss-Information
    echo '<h2 class="section-label spaced">' . th('Ohne Anschluss-Information ({n})', ['n' => count($loose)]) . '</h2>';
    if ($loose === []) {
        echo '<p class="dim">' . th('Alle Einträge sind einem Switch-Port oder WAN-Anschluss zugeordnet.') . '</p>';
    } else {
        echo '<p class="hint">' . th('Diese Einträge hängen an keinem erfassten Switch-Port. Zuordnen unter „Switches“ → Port → „Angeschlossenes Gerät“ (virtuelle Maschinen und WLAN-Geräte haben meist keinen eigenen Port).') . '</p><div class="nm-loose">';
        $by = [];
        foreach ($loose as $id => $n) {
            $by[(string)$n['net']][] = $n;
        }
        foreach ($netList as $slug => $meta) {
            if (empty($by[$slug])) {
                continue;
            }
            usort($by[$slug], static fn($a, $b) => strnatcasecmp((string)$a['label'], (string)$b['label']));
            echo '<section class="card nm-group"><h3>' . ($meta['vlan_id'] !== null ? vid_chip((int)$meta['vlan_id'], $vnets) : h($meta['name'])) . '</h3><div class="chips">';
            $extra = count($by[$slug]) - 60;
            foreach (array_slice($by[$slug], 0, 60) as $n) {
                $dot = $n['status'] === null ? 'unknown' : ($n['status'] ? 'up' : 'down');
                echo '<a class="nm-chip" href="' . h((string)$n['href']) . '"><i class="dot ' . $dot . '"></i>' . h($n['label']) . ' <span class="dim mono">' . h((string)$n['sub']) . '</span>' . ((string)$n['kind'] !== '' ? ' <span class="dim small">' . h(t((string)$n['kind'])) . '</span>' : '') . '</a>';
            }
            if ($extra > 0) {
                echo '<a class="nm-chip" href="' . h(url(['p' => 'net', 'n' => $slug])) . '">' . th('+{n} weitere im Netzwerk', ['n' => $extra]) . '</a>';
            }
            echo '</div></section>';
        }
        echo '</div>';
    }

    // Netzwerke im Plan
    echo '<h2 class="section-label spaced">' . th('Netzwerke auf den Switches') . '</h2><div class="table-wrap"><table class="data-table compact"><thead><tr><th>' . th('Netzwerk') . '</th><th>Gateway</th><th>' . th('Switches (untagged · tagged Ports)') . '</th><th>' . th('Einträge verbunden') . '</th><th class="col-act"></th></tr></thead><tbody>';
    $swPorts = [];
    foreach (store_load('switches')['switch_ports'] as $p) {
        $sid = (int)$p['switch_id'];
        if ($p['untagged'] !== null) {
            $swPorts[(int)$p['untagged']][$sid]['u'] = ($swPorts[(int)$p['untagged']][$sid]['u'] ?? 0) + 1;
        }
        foreach (vid_list((string)$p['tagged']) as $v) {
            $swPorts[$v][$sid]['t'] = ($swPorts[$v][$sid]['t'] ?? 0) + 1;
        }
    }
    $swAll = store_load('switches')['switches'];
    $ipIndex = [];
    foreach ($hosts as $id => $n) {
        if ((string)$n['sub'] !== '') {
            $ipIndex[(string)$n['sub']] = $id;
        }
    }
    $perNet = [];
    foreach ($hosts as $id => $n) {
        $perNet[(string)$n['net']]['all'] = ($perNet[(string)$n['net']]['all'] ?? 0) + 1;
        if (isset($inTree[$id])) {
            $perNet[(string)$n['net']]['conn'] = ($perNet[(string)$n['net']]['conn'] ?? 0) + 1;
        }
    }
    foreach ($netList as $slug => $meta) {
        $vid = $meta['vlan_id'] === null ? null : (int)$meta['vlan_id'];
        $sw = [];
        foreach ($vid !== null ? ($swPorts[$vid] ?? []) : [] as $sid => $c) {
            if (isset($swAll[$sid])) {
                $sw[] = '<a href="' . h(url(['p' => 'switch', 'id' => $sid])) . '">' . h($swAll[$sid]['name']) . '</a> <span class="dim small">' . (int)($c['u'] ?? 0) . ' · ' . (int)($c['t'] ?? 0) . '</span>';
            }
        }
        try {
            $gw = (string)(net_host_index($slug)['network']['gateway'] ?? '');
        } catch (Throwable $e) {
            $gw = '';
        }
        $gwHtml = '<span class="mono">' . h($gw) . '</span>' . ($gw !== '' && isset($ipIndex[$gw]) ? ' <a href="' . h((string)$nodes[$ipIndex[$gw]]['href']) . '">' . h($nodes[$ipIndex[$gw]]['label']) . '</a>' : ($gw !== '' ? ' <span class="dim small">' . th('kein Eintrag') . '</span>' : ''));
        $total = $perNet[$slug]['all'] ?? 0;
        $conn = $perNet[$slug]['conn'] ?? 0;
        echo '<tr><td>' . ($vid !== null ? vid_chip($vid, $vnets) : h($meta['name'])) . '</td><td>' . $gwHtml . '</td><td>' . ($sw ? implode(' · ', $sw) : '<span class="dim">' . th('auf keinem Switch') . '</span>') . '</td>'
            . '<td>' . th('{a} von {b}', ['a' => $conn, 'b' => $total]) . '</td><td class="col-act"><a class="icon-btn" href="' . h(url(['p' => 'netmap', 'net' => $slug]) . '#map') . '" title="' . th('Im Plan hervorheben') . '" aria-label="' . th('Im Plan hervorheben') . '">' . icon('topology', 14) . '</a></td></tr>';
    }
    echo '</tbody></table></div>';
    page_end();
}
