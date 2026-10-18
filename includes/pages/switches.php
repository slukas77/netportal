<?php
/**
 * Switch-Verwaltung: Liste, Detail mit Frontansicht, Ports (Netzwerke untagged/
 * tagged, angeschlossenes Gerät), VLANs, Vorlagen anwenden, Konfiguration
 * erzeugen/einlesen, Vorlagen (Originale + eigene).
 */

declare(strict_types=1);

function switch_or_404(int $id): array
{
    $sw = switch_get($id);
    if ($sw === null) {
        render_error_page(t('Nicht gefunden'), t('Switch nicht gefunden.'), 404);
        exit;
    }
    return $sw;
}

/** Alle Einträge des Portals für die Geräteauswahl: [slug => [name, hosts[id => label]]] */
function portal_host_options(): array
{
    $out = [];
    foreach (net_list() as $slug => $meta) {
        try {
            $idx = net_host_index($slug);
        } catch (Throwable $e) {
            continue;
        }
        if ($idx['hosts'] !== []) {
            $out[$slug] = ['name' => $meta['name'], 'hosts' => $idx['hosts']];
        }
    }
    return $out;
}

function vid_chip(?int $vid, array $nets, array $swVlans = [], bool $tagged = false): string
{
    if ($vid === null) {
        return '';
    }
    $name = $nets[$vid]['name'] ?? ($swVlans[$vid] ?? ($vid === 1 ? 'DEFAULT_VLAN' : ''));
    return '<span class="vchip' . ($tagged ? ' tagged' : '') . '" style="--vc:' . vid_color($vid) . '" title="' . h('VLAN ' . $vid . ($name !== '' ? ' · ' . $name : '')) . '"><b>' . $vid . '</b>' . ($name !== '' ? h($name) : '') . '</span>';
}

function port_state(array $p): string
{
    if ((int)$p['enabled'] === 0) {
        return 'off';
    }
    if ($p['untagged'] === null && (string)$p['tagged'] === '') {
        return 'none';
    }
    return (string)$p['tagged'] !== '' ? 'trunk' : 'access';
}

/** Frontansicht wie am Gerät: Ports in Gruppen, ungerade oben, gerade unten. */
function switch_faceplate(array $tpl, array $portsByLabel, array $nets, ?string $editBase = null, string $highlight = '', array $hostNames = []): string
{
    $groups = [];
    foreach ($tpl['port_list'] as $label => $pi) {
        $groups[$pi['group']][] = $pi;
    }
    $o = '<div class="faceplate"><div class="fp-brand"><span class="fp-vendor">' . h((string)$tpl['vendor']) . '</span><span class="fp-model">' . h((string)$tpl['model']) . '</span>'
        . '<span class="fp-pn">' . h((string)$tpl['product_no']) . '</span>' . ((string)($tpl['console'] ?? '') !== '' ? '<span class="fp-console" title="' . th('Konsole') . ': ' . h((string)$tpl['console']) . '">Console</span>' : '') . '</div><div class="fp-ports">';
    foreach ($groups as $gi => $list) {
        $odd = (string)$tpl['layout'] === 'oddeven' && count($list) > 1;
        $rows = $odd ? [array_values(array_filter($list, static fn($x, $i) => $i % 2 === 0, ARRAY_FILTER_USE_BOTH)), array_values(array_filter($list, static fn($x, $i) => $i % 2 === 1, ARRAY_FILTER_USE_BOTH))] : [$list];
        $o .= '<div class="fp-group conn-' . h($list[0]['conn']) . '" title="' . h($list[0]['type']) . '">';
        foreach ($rows as $ri => $row) {
            $o .= '<div class="fp-row">';
            foreach ($row as $ci => $pi) {
                if ($ci > 0 && $ci % 6 === 0) {
                    $o .= '<span class="fp-gap"></span>';
                }
                $p = $portsByLabel[$pi['label']] ?? null;
                $state = $p ? port_state($p) : 'none';
                $vid = $p && $p['untagged'] !== null ? (int)$p['untagged'] : null;
                $dev = $p && $p['device_host'] ? ($hostNames[$p['device_net'] . ':' . $p['device_host']] ?? '') : '';
                $tip = t('Port {p}', ['p' => $pi['label']]) . ' · ' . $pi['type'];
                if ($p) {
                    $tip .= ((string)$p['name'] !== '' ? ' · ' . $p['name'] : '') . ((int)$p['enabled'] === 0 ? ' · ' . t('ausgeschaltet') : '')
                        . ($vid !== null ? ' · untagged VLAN ' . $vid . (isset($nets[$vid]) ? ' (' . $nets[$vid]['name'] . ')' : '') : '')
                        . ((string)$p['tagged'] !== '' ? ' · tagged ' . $p['tagged'] : '') . ($p['trunk'] ? ' · ' . $p['trunk'] : '') . ($dev !== '' ? ' · ' . t('Gerät') . ': ' . $dev : '');
                }
                $tag = $editBase !== null ? 'a' : 'span';
                $href = $editBase !== null ? ' href="' . h($editBase . '&port=' . rawurlencode($pi['label'])) . '"' : '';
                $o .= '<' . $tag . $href . ' class="fp-port st-' . $state . (in_array($pi['conn'], ['sfp', 'sfpplus'], true) ? ' sfp' : '') . ($pi['conn'] === 'combo' ? ' combo' : '') . ($highlight === $pi['label'] ? ' hl' : '') . ($dev !== '' ? ' has-dev' : '') . ($p && $p['trunk'] ? ' lag' : '') . '"'
                    . ' style="--vc:' . vid_color($vid) . '" title="' . h($tip) . '"><span class="fp-num">' . h($pi['label']) . '</span></' . $tag . '>';
            }
            $o .= '</div>';
        }
        $o .= '</div>';
    }
    return $o . '</div></div>';
}

function switch_legend(array $ports, array $nets): string
{
    $vids = [];
    foreach ($ports as $p) {
        if ($p['untagged'] !== null) {
            $vids[(int)$p['untagged']] = true;
        }
        foreach (vid_list((string)$p['tagged']) as $v) {
            $vids[$v] = true;
        }
    }
    ksort($vids);
    $o = '<div class="legend sw-legend"><span class="legend-title">' . th('Legende') . '</span>';
    foreach (array_keys($vids) as $v) {
        $o .= vid_chip($v, $nets);
    }
    return $o . '<span class="legend-item"><i class="fp-mini st-trunk"></i>' . th('tagged (Trunk)') . '</span><span class="legend-item"><i class="fp-mini st-off"></i>' . th('ausgeschaltet') . '</span>'
        . '<span class="legend-item"><i class="fp-mini st-none"></i>' . th('kein Netzwerk') . '</span><span class="legend-item"><i class="fp-mini has-dev"></i>' . th('Gerät zugeordnet') . '</span></div>';
}

function host_name_map(array $opts): array
{
    $m = [];
    foreach ($opts as $slug => $g) {
        foreach ($g['hosts'] as $id => $h) {
            $m[$slug . ':' . $id] = (string)$h['hostname'];
        }
    }
    return $m;
}

// --- Liste -------------------------------------------------------------------------------------

function page_switches(): void
{
    $list = switches_list();
    page_start(t('Switches'), 'switches', [[t('Switches'), null]]);
    heading(t('Switches'), th('Managed Switches mit Ports, Netzwerken und angeschlossenen Geräten'),
        btn(t('Vorlagen'), url(['p' => 'switch_templates']), 'secondary', 'copy') . (can('edit') ? btn(t('Neuer Switch'), url(['p' => 'switch_edit']), 'primary', 'plus') : ''));
    if ($list === []) {
        echo '<div class="empty-state">' . icon('switch', 40) . '<p>' . th('Noch keine Switches erfasst.') . '</p>'
            . '<p class="hint">' . th('Original-Vorlagen: HP ProCurve 2610-24 (J9085A), 2510-24 (J9019A, J9019B) – oder eigene Vorlagen anlegen.') . '</p>'
            . (can('edit') ? btn(t('Ersten Switch anlegen'), url(['p' => 'switch_edit']), 'primary', 'plus') : '') . '</div>';
        page_end();
        return;
    }
    $nets = vid_networks();
    $st = store_load('switches');
    echo '<div class="table-wrap"><table class="data-table" id="swTable"><thead><tr><th class="col-dot"></th><th>' . th('Name') . '</th><th>' . th('Modell / Vorlage') . '</th><th>' . th('Management') . '</th>'
        . '<th>' . th('Ports') . '</th><th>' . th('Netzwerke') . '</th><th>' . th('Standort') . '</th></tr></thead><tbody>';
    foreach ($list as $sw) {
        $tpl = switch_template((string)$sw['template']);
        $ports = switch_ports((int)$sw['id']);
        $used = count(array_filter($ports, static fn($p) => $p['device_host'] || (string)$p['name'] !== ''));
        $vids = array_map(static fn($v) => (int)$v['vid'], switch_vlans((int)$sw['id']));
        $hostSt = null;
        if ($sw['net'] && $sw['host_id']) {
            $hostSt = status_get((string)$sw['net'])['hosts'][(string)$sw['host_id']] ?? null;
        }
        $dot = '<i class="dot ' . ($hostSt === null ? 'unknown' : ($hostSt['ok'] ? 'up' : 'down')) . '"></i>';
        $href = url(['p' => 'switch', 'id' => $sw['id']]);
        echo '<tr data-href="' . h($href) . '"><td class="col-dot">' . $dot . '</td><td><div class="cell-title">' . icon('switch', 15, 'cell-icon') . '<a href="' . h($href) . '">' . h($sw['name']) . '</a>'
            . (store_row_ok('switches', 'switches', $sw) ? '' : ' <span class="badge bad">' . th('Hash ungültig') . '</span>') . '</div></td>'
            . '<td>' . h($tpl['name'] ?? '?') . ' <span class="dim small">' . h((string)($tpl['product_no'] ?? '')) . '</span>' . (!empty($tpl['builtin']) ? '' : ' <span class="badge dim">' . th('eigene Vorlage') . '</span>') . '</td>'
            . '<td class="mono">' . h((string)$sw['mgmt_ip']) . ($sw['mgmt_vlan'] ? ' <span class="vlan-tag">' . (int)$sw['mgmt_vlan'] . '</span>' : '') . '</td>'
            . '<td>' . th('{u} von {n} belegt', ['u' => $used, 'n' => count($ports)]) . '</td>'
            . '<td class="chips">' . implode('', array_map(static fn($v) => vid_chip($v, $nets), array_slice($vids, 0, 8))) . '</td>'
            . '<td>' . h((string)$sw['location']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    page_end();
}

// --- Detail ------------------------------------------------------------------------------------

function page_switch(): void
{
    $sw = switch_or_404((int)($_GET['id'] ?? 0));
    $sid = (int)$sw['id'];
    $tpl = switch_template((string)$sw['template']);
    $ports = switch_ports($sid);
    $vlans = switch_vlans($sid);
    $set = switch_settings($sw);
    $nets = vid_networks();
    $opts = portal_host_options();
    $names = host_name_map($opts);
    $byLabel = [];
    foreach ($ports as $p) {
        $byLabel[(string)$p['pnum']] = $p;
    }
    $swVlanNames = [];
    foreach ($vlans as $v) {
        $swVlanNames[(int)$v['vid']] = (string)$v['name'];
    }
    $host = null;
    if ($sw['net'] && $sw['host_id']) {
        try {
            $host = net_load((string)$sw['net'])['hosts'][(int)$sw['host_id']] ?? null;
        } catch (Throwable $e) {
            $host = null;
        }
    }
    $ok = store_row_ok('switches', 'switches', $sw);
    page_start((string)$sw['name'], 'switches', [[t('Switches'), url(['p' => 'switches'])], [(string)$sw['name'], null]]);
    echo '<section class="stage" data-net="' . h((string)$sw['net']) . '" data-host="' . (int)$sw['host_id'] . '"><div class="stage-body"><div class="stage-cover">' . icon('switch', 54) . '</div>'
        . '<div class="stage-info"><div class="stage-badges"><span class="live-badge"><i></i>' . h($tpl['name'] ?? '?') . '</span>' . badge((string)($tpl['product_no'] ?? ''), 'mono-badge')
        . (!empty($tpl['builtin']) ? '<span class="badge state-unknown">' . th('Original-Vorlage') . '</span>' : '<span class="badge dim">' . th('eigene Vorlage') . '</span>')
        . ($ok ? '' : '<span class="badge bad">' . th('Eintrag manipuliert') . '</span>') . '</div>'
        . '<div class="stage-station">' . h($sw['name']) . '</div><div class="stage-title"><span class="mono">' . h((string)$sw['mgmt_ip'] ?: t('keine IP')) . '</span>'
        . ($sw['mgmt_vlan'] ? ' · VLAN ' . (int)$sw['mgmt_vlan'] : '') . ($sw['firmware'] ? ' · ' . h((string)$sw['firmware']) : '') . ($sw['location'] ? ' · ' . h((string)$sw['location']) : '') . '</div></div>'
        . ($host !== null ? '<div class="stage-pills">' . (can('terminal') && terminal_available() ? '<a class="rec-pill secondary-pill" href="' . h(url(['p' => 'terminal', 'n' => $sw['net'], 'id' => $sw['host_id'], 'auto' => 1])) . '">' . icon('terminal', 16) . th('SSH-Terminal') . '</a>' : '')
            . '<a class="rec-pill" href="' . h(url(['p' => 'host', 'n' => $sw['net'], 'id' => $sw['host_id']])) . '">' . icon('pulse', 16) . (can('ping') ? th('Eintrag & Ping') : th('Eintrag')) . '</a></div>' : '')
        . '</div></section>';

    $acts = '';
    if (can('edit')) {
        $acts .= btn(t('Bearbeiten'), url(['p' => 'switch_edit', 'id' => $sid]), 'secondary', 'edit');
    }
    if (can('switch_config')) {
        $acts .= btn(t('Konfiguration erzeugen'), url(['p' => 'switch_config', 'id' => $sid]), 'secondary', 'file');
    }
    if (can('delete')) {
        $acts .= post_button(t('Löschen'), ['a' => 'switch_delete'], 'danger', t('Switch „{s}“ mit allen Ports und VLANs löschen? Der Eintrag im Netzwerk bleibt.', ['s' => $sw['name']]), 'trash', ['id' => $sid]);
    }
    echo '<div class="action-row">' . $acts . '</div>';

    // Frontansicht
    echo '<section class="card fp-card">' . ($tpl ? switch_faceplate($tpl, $byLabel, $nets, can('edit') ? url(['p' => 'switch_port', 'id' => $sid]) : null, '', $names) : '<p class="note warn">' . th('Vorlage nicht gefunden.') . '</p>')
        . switch_legend($ports, $nets) . '</section>';

    // Ports
    echo '<h2 class="section-label spaced">' . th('Ports ({n})', ['n' => count($ports)]) . '</h2>';
    echo '<div class="table-wrap"><table class="data-table compact sw-ports"><thead><tr><th>Port</th><th>' . th('Typ') . '</th><th>' . th('Name') . '</th><th>' . th('Netzwerk (untagged)') . '</th><th>' . th('Netzwerke (tagged)') . '</th><th>Trunk</th><th>' . th('Gerät') . '</th><th>' . th('Geschw.') . '</th><th class="col-act"></th></tr></thead><tbody>';
    foreach ($ports as $p) {
        $pi = $tpl['port_list'][$p['pnum']] ?? ['type' => '?', 'conn' => 'rj45'];
        $dev = '';
        if ($p['device_net'] && $p['device_host']) {
            $dev = '<a href="' . h(url(['p' => 'host', 'n' => $p['device_net'], 'id' => $p['device_host']])) . '">' . h($names[$p['device_net'] . ':' . $p['device_host']] ?? '#' . $p['device_host']) . '</a>';
        }
        echo '<tr class="' . ((int)$p['enabled'] === 0 ? 'row-off' : '') . '" id="port-' . h((string)$p['pnum']) . '"><td class="mono"><b>' . h((string)$p['pnum']) . '</b></td><td class="small dim">' . h($pi['type']) . '</td>'
            . '<td>' . h((string)$p['name']) . ((int)$p['enabled'] === 0 ? ' <span class="badge dim">' . th('aus') . '</span>' : '') . '</td>'
            . '<td>' . vid_chip($p['untagged'] !== null ? (int)$p['untagged'] : null, $nets, $swVlanNames) . '</td>'
            . '<td class="chips">' . implode('', array_map(static fn($v) => vid_chip($v, $nets, $swVlanNames, true), vid_list((string)$p['tagged']))) . '</td>'
            . '<td class="mono small">' . h((string)$p['trunk']) . '</td><td>' . $dev . '</td><td class="mono small">' . h((string)($p['speed'] ?: 'auto')) . '</td>'
            . '<td class="col-act">' . (can('edit') ? '<a class="icon-btn" href="' . h(url(['p' => 'switch_port', 'id' => $sid, 'port' => $p['pnum']])) . '" title="' . th('Bearbeiten') . '" aria-label="' . th('Bearbeiten') . '">' . icon('edit', 14) . '</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';

    // Mehrere Ports auf einmal
    if (can('edit')) {
        echo '<details class="card sub" id="bulkBox"><summary>' . icon('edit', 14) . ' <span>' . th('Mehrere Ports auf einmal bearbeiten') . '</span></summary>'
            . '<form method="post" action="' . h(url(['a' => 'switch_ports_bulk'])) . '" class="form-layout">' . csrf_field() . '<input type="hidden" name="id" value="' . $sid . '">'
            . '<div class="form-grid cols-4">'
            . f_text('ports', t('Ports'), '', ['required' => true, 'placeholder' => '1-12,15', 'class' => 'mono-input', 'hint' => th('Bereiche mit Bindestrich, mehrere mit Komma')])
            . f_select('untagged', t('Netzwerk (untagged)'), ['keep' => '— ' . t('unverändert') . ' —'] + switch_vid_options($nets, $swVlanNames), 'keep')
            . f_select('tagged_mode', t('Netzwerke (tagged)'), ['keep' => '— ' . t('unverändert') . ' —', 'set' => t('ersetzen durch Auswahl'), 'add' => t('Auswahl hinzufügen'), 'clear' => t('alle entfernen')], 'keep')
            . f_select('enabled', t('Status'), ['keep' => '— ' . t('unverändert') . ' —', '1' => t('eingeschaltet'), '0' => t('ausgeschaltet')], 'keep')
            . f_text('name', t('Name (leer = unverändert)'), '', ['maxlength' => 60])
            . f_select('trunk', 'Trunk', ['keep' => '— ' . t('unverändert') . ' —', '' => t('kein Trunk')] + array_combine(array_map(static fn($i) => 'Trk' . $i, range(1, 12)), array_map(static fn($i) => 'Trk' . $i . ' (LACP)', range(1, 12))), 'keep')
            . f_select('speed', t('Geschwindigkeit'), ['keep' => '— ' . t('unverändert') . ' —'] + array_combine(SWITCH_SPEEDS, SWITCH_SPEEDS), 'keep')
            . '</div>' . switch_tagged_checks('tagged', [], $nets, $swVlanNames)
            . '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Auf Ports anwenden') . '</button></div></form></details>';
    }

    // VLANs + Einstellungen
    echo '<section class="card spaced-top"><h2 class="section-label">' . th('VLANs auf dem Switch') . '</h2>'
        . '<div class="table-wrap"><table class="data-table compact sw-vlans"><thead><tr><th>VLAN</th><th>' . th('Netzwerk im Portal') . '</th><th>' . th('IP des Switches') . '</th><th>' . th('Ports') . '</th></tr></thead><tbody>';
    foreach ($vlans as $v) {
        $vid = (int)$v['vid'];
        $u = count(array_filter($ports, static fn($p) => $p['untagged'] !== null && (int)$p['untagged'] === $vid));
        $tg = count(array_filter($ports, static fn($p) => in_array($vid, vid_list((string)$p['tagged']), true)));
        $net = $nets[$vid] ?? null;
        echo '<tr><td><span class="vchip" style="--vc:' . vid_color($vid) . '"><b>' . $vid . '</b>' . h((string)$v['name']) . '</span></td>'
            . '<td>' . ($net ? '<a href="' . h(url(['p' => 'net', 'n' => $net['slug']])) . '">' . h($net['name']) . '</a><div class="cell-sub mono">' . h($net['cidr']) . '</div>' : '<span class="dim">–</span>') . '</td>'
            . '<td class="mono">' . h((string)$v['ip'] ?: ((int)$sw['mgmt_vlan'] === $vid ? (string)$sw['mgmt_ip'] : '')) . '</td>'
            . '<td class="small nowrap">' . th('{u} untagged · {t} tagged', ['u' => $u, 't' => $tg]) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (can('edit')) {
        echo '<form method="post" action="' . h(url(['a' => 'switch_vlan_save'])) . '" class="inline-vlan">' . csrf_field() . '<input type="hidden" name="id" value="' . $sid . '">'
            . f_select('net', t('Netzwerk als VLAN hinzufügen'), ['' => '— ' . t('auswählen') . ' —'] + array_combine(array_column($nets, 'slug'), array_map(static fn($vid, $n) => 'VLAN ' . $vid . ' · ' . $n['name'], array_keys($nets), $nets)), '')
            . f_text('vid', t('oder VLAN-ID'), '', ['inputmode' => 'numeric', 'maxlength' => 4]) . f_text('vname', t('Name'), '', ['maxlength' => 32])
            . '<div class="field"><span>&nbsp;</span><button class="pill-btn secondary small" type="submit">' . icon('plus', 14) . th('Hinzufügen') . '</button></div></form>';
    }
    echo '</section><section class="card"><h2 class="section-label">' . th('Einstellungen') . '</h2>' . kv_list([
        t('Hostname') => h($set['hostname'] !== '' ? $set['hostname'] : (string)$sw['name']),
        'Gateway' => '<span class="mono">' . h($set['gateway']) . '</span>',
        'SNTP' => '<span class="mono">' . h($set['sntp']) . '</span>' . ($set['timezone'] !== '' ? ' · UTC' . ((int)$set['timezone'] >= 0 ? '+' : '') . round((int)$set['timezone'] / 60, 1) : '') . ($set['dst'] ? ' · ' . h($set['dst']) : ''),
        'SNMP' => $set['snmp_community'] !== '' ? h(($set['snmp_rw'] === '1' ? t('lesen/schreiben') : t('nur lesen')) . ' · ' . $set['snmp_location'] . ($set['snmp_contact'] ? ' · ' . $set['snmp_contact'] : '')) . ($set['snmp_community'] === 'public' ? ' <span class="badge warn">community „public“</span>' : '') : '<span class="dim">' . th('aus') . '</span>',
        'Syslog' => '<span class="mono">' . h($set['syslog']) . '</span>',
        t('Zugriff') => implode(' ', [badge('SSH', $set['ssh'] === '1' ? 'ok-soft' : 'dim'), badge('Telnet', $set['telnet'] === '1' ? 'warn' : 'dim'), badge('Web', $set['web'] === '1' ? 'warn' : 'dim'), badge('STP', $set['stp'] === '1' ? 'ok-soft' : 'dim')]),
        t('Erlaubte Manager') => '<span class="mono">' . h($set['managers']) . '</span>',
        t('Seriennummer') => h((string)$sw['serial']),
        t('Notizen') => nl2br(h((string)$sw['notes'])),
    ]) . '</section>';

    // Vorlage anwenden / Konfiguration einlesen
    if (can('edit')) {
        $tplOpts = [];
        foreach (switch_template_list() as $ref => $t2) {
            $tplOpts[$ref] = ($t2['builtin'] ? '★ ' : '') . $t2['name'] . ' · ' . $t2['product_no'] . ($t2['builtin'] ? ' (' . t('Original') . ')' : '');
        }
        echo '<div class="grid-2 spaced"><section class="card"><h2 class="section-label">' . th('Vorlage anwenden') . '</h2>'
            . '<form method="post" action="' . h(url(['a' => 'switch_apply'])) . '" data-confirm="' . th('Vorlage auf diesen Switch anwenden?') . '">' . csrf_field() . '<input type="hidden" name="id" value="' . $sid . '">'
            . '<div class="form-grid">' . f_select('template', t('Vorlage'), $tplOpts, (string)$sw['template'], ['class' => 'span2'])
            . '<div class="field check-field span2">' . f_check('ports', t('Port-Vorgaben übernehmen (Namen, Netzwerke, Trunks)'), true) . '</div>'
            . '<div class="field check-field span2">' . f_check('settings', t('Einstellungen übernehmen (nur gesetzte Werte)'), true) . '</div></div>'
            . '<p class="hint">' . th('Der Port-Aufbau wird an die Vorlage angepasst. Angeschlossene Geräte und Notizen bleiben erhalten.') . '</p>'
            . '<button class="pill-btn primary" type="submit">' . icon('copy', 15) . th('Vorlage anwenden') . '</button></form></section>';
        echo '<section class="card"><h2 class="section-label">' . th('Konfiguration einlesen (ProCurve)') . '</h2>'
            . '<form method="post" action="' . h(url(['a' => 'switch_import'])) . '" data-confirm="' . th('Ports, VLANs und Einstellungen aus der Konfiguration übernehmen?') . '">' . csrf_field() . '<input type="hidden" name="id" value="' . $sid . '">'
            . f_textarea('config', t('Ausgabe von „show running-config“ einfügen'), '', 6, ['mono' => true, 'placeholder' => '; J9085A Configuration Editor; Created on release #R.11.xx'])
            . '<div class="form-actions"><button class="pill-btn secondary" type="submit">' . icon('download', 15) . th('Einlesen') . '</button></div></form></section></div>';
    }
    page_end();
}

/** Auswahl für untagged: VLANs aus dem Portal + Switch-VLANs. */
function switch_vid_options(array $nets, array $swVlans): array
{
    $o = ['none' => '— ' . t('kein Netzwerk (nur tagged)') . ' —'];
    $all = [1 => 'DEFAULT_VLAN'] + array_map(static fn($n) => $n['name'], $nets) + $swVlans;
    ksort($all);
    foreach ($all as $vid => $name) {
        $o[(string)$vid] = 'VLAN ' . $vid . ' · ' . ($nets[$vid]['name'] ?? $name);
    }
    return $o;
}

function switch_tagged_checks(string $field, array $checked, array $nets, array $swVlans): string
{
    $all = array_map(static fn($n) => $n['name'], $nets) + $swVlans;
    unset($all[1]);
    ksort($all);
    $o = '<div class="field"><span>' . th('Netzwerke (tagged) auswählen') . '</span><div class="vlan-checks">';
    foreach ($all as $vid => $name) {
        $o .= '<label class="vcheck" style="--vc:' . vid_color((int)$vid) . '"><input type="checkbox" name="' . h($field) . '[]" value="' . (int)$vid . '"' . (in_array((int)$vid, $checked, true) ? ' checked' : '') . '><span><b>' . (int)$vid . '</b> ' . h($nets[$vid]['name'] ?? $name) . '</span></label>';
    }
    return $o . '</div></div>';
}

// --- Port bearbeiten ----------------------------------------------------------------------------

function page_switch_port(): void
{
    $sw = switch_or_404((int)($_GET['id'] ?? 0));
    $sid = (int)$sw['id'];
    $label = get_str('port', 12);
    $tpl = switch_template((string)$sw['template']);
    if ($tpl === null) {
        render_error_page(t('Nicht gefunden'), t('Vorlage nicht gefunden.'), 404);
        exit;
    }
    $ports = switch_ports($sid);
    $p = null;
    $byLabel = [];
    foreach ($ports as $x) {
        $byLabel[(string)$x['pnum']] = $x;
        if ((string)$x['pnum'] === $label) {
            $p = $x;
        }
    }
    if ($p === null) {
        render_error_page(t('Nicht gefunden'), t('Port nicht gefunden.'), 404);
        exit;
    }
    $nets = vid_networks();
    $swVlanNames = [];
    foreach (switch_vlans($sid) as $v) {
        $swVlanNames[(int)$v['vid']] = (string)$v['name'];
    }
    $opts = portal_host_options();
    $pi = $tpl['port_list'][$label] ?? ['type' => '?'];
    page_start(t('Port {p}', ['p' => $label]), 'switches', [[t('Switches'), url(['p' => 'switches'])], [(string)$sw['name'], url(['p' => 'switch', 'id' => $sid])], [t('Port {p}', ['p' => $label]), null]]);
    heading(t('{s} · Port {p}', ['s' => $sw['name'], 'p' => $label]), h($pi['type']));
    echo '<section class="card fp-card">' . switch_faceplate($tpl, $byLabel, $nets, url(['p' => 'switch_port', 'id' => $sid]), $label, host_name_map($opts)) . '</section>';
    // Bei vielen Einträgen nur die Netzwerke dieses Switches anbieten (plus gewähltes Netzwerk)
    $total = array_sum(array_map(static fn($g) => count($g['hosts']), $opts));
    $dnet = get_str('dnet', 60);
    $shown = $opts;
    if ($total > 300) {
        $keep = [(string)$p['device_net'] => true, (string)$sw['net'] => true, $dnet => true];
        foreach (switch_vlans($sid) as $v) {
            if ($v['net']) {
                $keep[(string)$v['net']] = true;
            } elseif (isset($nets[(int)$v['vid']])) {
                $keep[$nets[(int)$v['vid']]['slug']] = true;
            }
        }
        $shown = array_intersect_key($opts, $keep);
        $netChoice = ['' => '— ' . t('Netzwerk wählen') . ' —'];
        foreach (array_diff_key($opts, $shown) as $slug => $g) {
            $netChoice[$slug] = $g['name'] . ' (' . count($g['hosts']) . ')';
        }
        if (count($netChoice) > 1) {
            echo '<form method="get" action="./" class="inline-vlan nm-filter"><input type="hidden" name="p" value="switch_port"><input type="hidden" name="id" value="' . $sid . '"><input type="hidden" name="port" value="' . h($label) . '">'
                . f_select('dnet', t('Gerät aus weiterem Netzwerk'), $netChoice, $dnet)
                . '<button class="pill-btn secondary small" type="submit">' . th('Anzeigen') . '</button>'
                . '<span class="hint">' . th('Angeboten werden die Netzwerke der VLANs auf diesem Switch.') . '</span></form>';
        }
    }
    $devOpts = '<option value="">— ' . th('kein Gerät') . ' —</option>';
    foreach ($shown as $slug => $g) {
        $devOpts .= '<optgroup label="' . h($g['name']) . '">';
        foreach ($g['hosts'] as $id => $h) {
            $v = $slug . ':' . $id;
            $devOpts .= '<option value="' . h($v) . '"' . ((string)$p['device_net'] === $slug && (int)$p['device_host'] === $id ? ' selected' : '') . '>' . h($h['hostname'] . ($h['ip'] ? ' · ' . $h['ip'] : '')) . '</option>';
        }
        $devOpts .= '</optgroup>';
    }
    echo '<form method="post" action="' . h(url(['a' => 'switch_port_save'])) . '" class="form-layout" autocomplete="off">' . csrf_field()
        . '<input type="hidden" name="id" value="' . $sid . '"><input type="hidden" name="port" value="' . h($label) . '">'
        . '<section class="card"><div class="form-grid cols-4">'
        . f_text('name', t('Name / Beschreibung'), (string)$p['name'], ['maxlength' => 60, 'class' => 'span2', 'placeholder' => t('z. B. PC Empfang')])
        . '<div class="field check-field">' . f_check('enabled', t('Port eingeschaltet'), (int)$p['enabled'] === 1) . '</div>'
        . f_select('speed', t('Geschwindigkeit'), array_combine(SWITCH_SPEEDS, SWITCH_SPEEDS), (string)($p['speed'] ?: 'auto'))
        . f_select('untagged', t('Netzwerk (untagged)'), switch_vid_options($nets, $swVlanNames), $p['untagged'] === null ? 'none' : (string)$p['untagged'], ['class' => 'span2'])
        . f_select('trunk', 'Trunk', ['' => t('kein Trunk')] + array_combine(array_map(static fn($i) => 'Trk' . $i, range(1, 12)), array_map(static fn($i) => 'Trk' . $i . ' (LACP)', range(1, 12))), (string)$p['trunk'])
        . '<label class="field"><span>' . th('Angeschlossenes Gerät') . '</span><select name="device">' . $devOpts . '</select></label>'
        . '</div>' . switch_tagged_checks('tagged', vid_list((string)$p['tagged']), $nets, $swVlanNames)
        . f_text('tagged_extra', t('Weitere VLAN-IDs (tagged)'), implode(',', array_diff(vid_list((string)$p['tagged']), array_keys($nets + $swVlanNames))), ['class' => 'mono-input', 'placeholder' => '100,200-205'])
        . f_textarea('notes', t('Notizen'), (string)$p['notes'], 2)
        . '</section><div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Speichern') . '</button>'
        . btn(t('Zurück'), url(['p' => 'switch', 'id' => $sid]) . '#port-' . rawurlencode($label), 'ghost') . '</div></form>';
    page_end();
}

function switch_port_values(array $p, array $in): array
{
    if (array_key_exists('name', $in)) {
        $p['name'] = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F"]/', '', (string)$in['name']) ?? ''), 0, 60);
    }
    if (array_key_exists('untagged', $in)) {
        $p['untagged'] = $in['untagged'] === 'none' || $in['untagged'] === '' ? null : max(1, min(4094, (int)$in['untagged']));
    }
    if (array_key_exists('tagged', $in)) {
        $p['tagged'] = implode(',', array_diff($in['tagged'], [$p['untagged'] ?? -1]));
    }
    foreach (['enabled', 'trunk', 'speed', 'device_net', 'device_host', 'notes'] as $k) {
        if (array_key_exists($k, $in)) {
            $p[$k] = $in[$k];
        }
    }
    return $p;
}

function action_switch_port_save(): void
{
    $sw = switch_or_404(in_int('id'));
    $sid = (int)$sw['id'];
    $label = in_str('port', 12);
    $tagged = array_values(array_unique(array_merge(array_map('intval', (array)(input()['tagged'] ?? [])), vid_list(in_str('tagged_extra', 200)))));
    $tagged = array_values(array_filter($tagged, static fn($v) => $v >= 1 && $v <= 4094));
    sort($tagged);
    $dev = in_str('device', 80);
    $devNet = $devHost = null;
    if (preg_match('/^([a-z0-9-]+):(\d+)$/', $dev, $m) && isset(net_list()[$m[1]]) && isset(net_load($m[1])['hosts'][(int)$m[2]])) {
        $devNet = $m[1];
        $devHost = (int)$m[2];
    } elseif ($dev !== '') {
        flash(t('Gerät nicht gefunden – Port ohne Gerät gespeichert.'), 'warn');
    }
    $trunk = in_str('trunk', 6);
    $speed = in_str('speed', 12);
    $vals = ['name' => in_str('name', 60), 'untagged' => in_str('untagged', 5), 'tagged' => $tagged, 'enabled' => in_bool('enabled') ? 1 : 0,
        'trunk' => preg_match('/^Trk\d{1,2}$/', $trunk) ? $trunk : null, 'speed' => in_array($speed, SWITCH_SPEEDS, true) ? $speed : 'auto',
        'device_net' => $devNet, 'device_host' => $devHost, 'notes' => in_str('notes', 1000)];
    [$old, $new] = store_write('switches', static function (array &$st) use ($sid, $label, $vals): array {
        foreach ($st['switch_ports'] as $p) {
            if ((int)$p['switch_id'] === $sid && (string)$p['pnum'] === $label) {
                $new = switch_port_values($p, $vals);
                store_put('switches', $st, 'switch_ports', $new);
                switch_ensure_vlans($st, $sid);
                return [$p, $new];
            }
        }
        throw new RuntimeException(t('Port nicht gefunden.'));
    });
    audit('switch_port', ['switch' => $sw['name'], 'port' => $label, 'aenderungen' => audit_diff($old, $new)], null, 'ok', ['type' => 'switch', 'id' => $sid, 'label' => $sw['name'] . ' #' . $label]);
    flash(t('Port {p} gespeichert.', ['p' => $label]));
    redirect(url(['p' => 'switch', 'id' => $sid]) . '#port-' . rawurlencode($label));
}

function action_switch_ports_bulk(): void
{
    $sw = switch_or_404(in_int('id'));
    $sid = (int)$sw['id'];
    $targets = port_range_expand(in_str('ports', 200));
    if ($targets === []) {
        flash(t('Bitte Ports angeben (z. B. 1-12,15).'), 'error');
        redirect(url(['p' => 'switch', 'id' => $sid]) . '#bulkBox');
    }
    $sel = array_values(array_filter(array_map('intval', (array)(input()['tagged'] ?? [])), static fn($v) => $v >= 1 && $v <= 4094));
    $mode = in_str('tagged_mode', 6);
    $vals = [];
    if (in_str('untagged', 5) !== 'keep') {
        $vals['untagged'] = in_str('untagged', 5);
    }
    if (in_str('enabled', 4) !== 'keep') {
        $vals['enabled'] = in_str('enabled', 4) === '1' ? 1 : 0;
    }
    if (in_str('name', 60) !== '') {
        $vals['name'] = in_str('name', 60);
    }
    $trunk = in_str('trunk', 6);
    if ($trunk !== 'keep') {
        $vals['trunk'] = preg_match('/^Trk\d{1,2}$/', $trunk) ? $trunk : null;
    }
    $speed = in_str('speed', 12);
    if ($speed !== 'keep' && in_array($speed, SWITCH_SPEEDS, true)) {
        $vals['speed'] = $speed;
    }
    $n = store_write('switches', static function (array &$st) use ($sid, $targets, $vals, $sel, $mode): int {
        $n = 0;
        foreach ($st['switch_ports'] as $p) {
            if ((int)$p['switch_id'] !== $sid || !in_array((string)$p['pnum'], $targets, true)) {
                continue;
            }
            $v = $vals;
            if ($mode === 'set') {
                $v['tagged'] = $sel;
            } elseif ($mode === 'add') {
                $v['tagged'] = array_values(array_unique([...vid_list((string)$p['tagged']), ...$sel]));
            } elseif ($mode === 'clear') {
                $v['tagged'] = [];
            }
            $new = switch_port_values($p, $v);
            if ($new !== $p) {
                store_put('switches', $st, 'switch_ports', $new);
                $n++;
            }
        }
        switch_ensure_vlans($st, $sid);
        return $n;
    });
    audit('switch_port', ['switch' => $sw['name'], 'ports' => port_range_compress($targets), 'werte' => $vals, 'tagged' => $mode . ' ' . implode(',', $sel), 'geaendert' => $n], null, 'ok', ['type' => 'switch', 'id' => $sid, 'label' => $sw['name']]);
    flash(t('{n} Port(s) geändert.', ['n' => $n]));
    redirect(url(['p' => 'switch', 'id' => $sid]));
}

function action_switch_vlan_save(): void
{
    $sw = switch_or_404(in_int('id'));
    $sid = (int)$sw['id'];
    $nets = vid_networks();
    $slug = in_str('net', 60);
    $vid = 0;
    $name = in_str('vname', 32);
    foreach ($nets as $v => $n) {
        if ($n['slug'] === $slug) {
            $vid = $v;
            $name = $name ?: $n['name'];
        }
    }
    if ($vid === 0) {
        $vid = in_int('vid');
    }
    if ($vid < 1 || $vid > 4094) {
        flash(t('VLAN-ID muss zwischen 1 und 4094 liegen'), 'error');
        redirect(url(['p' => 'switch', 'id' => $sid]));
    }
    store_write('switches', static function (array &$st) use ($sid, $vid, $name, $nets): void {
        foreach ($st['switch_vlans'] as $v) {
            if ((int)$v['switch_id'] === $sid && (int)$v['vid'] === $vid) {
                store_put('switches', $st, 'switch_vlans', array_merge($v, ['name' => mb_substr(preg_replace('/["\x00-\x1F]/', '', $name) ?? '', 0, 32) ?: $v['name']]));
                return;
            }
        }
        store_put('switches', $st, 'switch_vlans', ['switch_id' => $sid, 'vid' => $vid, 'name' => mb_substr(preg_replace('/["\x00-\x1F]/', '', $name) ?? '', 0, 32) ?: 'VLAN' . $vid, 'net' => $nets[$vid]['slug'] ?? null]);
    });
    audit('switch_update', ['switch' => $sw['name'], 'vlan' => $vid, 'name' => $name], null, 'ok', ['type' => 'switch', 'id' => $sid, 'label' => $sw['name']]);
    flash(t('VLAN {v} gespeichert.', ['v' => $vid]));
    redirect(url(['p' => 'switch', 'id' => $sid]));
}

// --- Anlegen / Bearbeiten --------------------------------------------------------------------------

function switch_settings_fields(array $set, string $prefix = 'set'): string
{
    $n = static fn(string $k): string => $prefix . '[' . $k . ']';
    $yn = static fn(string $k, string $label, string $hint = '') => '<div class="field check-field"><label class="check"><input type="hidden" name="' . h($n($k)) . '" value="0"><input type="checkbox" name="' . h($n($k)) . '" value="1"' . (($set[$k] ?? '') === '1' ? ' checked' : '') . '><span>' . h($label) . '</span>' . ($hint !== '' ? '<small>' . $hint . '</small>' : '') . '</label></div>';
    return '<div class="form-grid cols-4">'
        . f_text($n('hostname'), t('Hostname'), (string)($set['hostname'] ?? ''), ['maxlength' => 32, 'placeholder' => t('leer = Name des Switches')])
        . f_text($n('gateway'), 'Default-Gateway', (string)($set['gateway'] ?? ''), ['maxlength' => 15, 'class' => 'mono-input'])
        . f_text($n('sntp'), t('SNTP-Server'), (string)($set['sntp'] ?? ''), ['maxlength' => 64, 'class' => 'mono-input', 'placeholder' => '10.10.20.10'])
        . f_select($n('timezone'), t('Zeitzone'), ['' => '–', '0' => 'UTC', '60' => 'UTC+1 (MEZ)', '120' => 'UTC+2', '-300' => 'UTC−5'], (string)($set['timezone'] ?? ''))
        . f_select($n('dst'), t('Sommerzeit'), ['' => '–', 'Western-Europe' => 'Western-Europe', 'Continental-US-and-Canada' => 'Continental-US-and-Canada', 'none' => 'none'], (string)($set['dst'] ?? ''))
        . f_text($n('snmp_community'), t('SNMP-Community'), (string)($set['snmp_community'] ?? ''), ['maxlength' => 32, 'hint' => th('leer = SNMP aus')])
        . $yn('snmp_rw', t('SNMP schreibend (Unrestricted)'), th('aus = nur lesen (Operator)'))
        . f_text($n('snmp_location'), t('SNMP-Standort'), (string)($set['snmp_location'] ?? ''), ['maxlength' => 64])
        . f_text($n('snmp_contact'), t('SNMP-Kontakt'), (string)($set['snmp_contact'] ?? ''), ['maxlength' => 64])
        . f_text($n('syslog'), t('Syslog-Server'), (string)($set['syslog'] ?? ''), ['maxlength' => 64, 'class' => 'mono-input'])
        . f_text($n('managers'), t('Erlaubte Manager (IP/Netz)'), (string)($set['managers'] ?? ''), ['maxlength' => 120, 'class' => 'mono-input', 'placeholder' => '10.10.10.0/24'])
        . f_text($n('console_timeout'), t('Konsolen-Timeout (min)'), (string)($set['console_timeout'] ?? ''), ['inputmode' => 'numeric', 'maxlength' => 3])
        . $yn('ssh', 'SSH') . $yn('telnet', 'Telnet') . $yn('web', t('Web-Oberfläche')) . $yn('stp', 'Spanning Tree')
        . '</div>';
}

function switch_settings_from_input(array $in): array
{
    $out = [];
    foreach (SWITCH_SETTING_KEYS as $k) {
        $v = trim(str_replace(["\0", "\r", "\n", '"'], '', is_scalar($in[$k] ?? null) ? (string)$in[$k] : ''));
        $out[$k] = mb_substr($v, 0, 200);
    }
    foreach (['gateway', 'sntp', 'syslog'] as $k) {
        $out[$k] = implode(' ', array_filter(preg_split('/[\s,]+/', $out[$k]) ?: [], static fn($x) => ip_valid($x) || hostname_valid($x)));
    }
    $out['managers'] = implode(' ', array_filter(preg_split('/[\s,]+/', $out['managers']) ?: [], static fn($x) => cidr_parse(str_contains($x, '/') ? $x : $x . '/32') !== null));
    if ($out['timezone'] !== '' && !preg_match('/^-?\d{1,3}$/', $out['timezone'])) {
        $out['timezone'] = '';
    }
    if ($out['console_timeout'] !== '' && !ctype_digit($out['console_timeout'])) {
        $out['console_timeout'] = '';
    }
    $out['hostname'] = preg_replace('/[^A-Za-z0-9 ._-]/', '', $out['hostname']) ?? '';
    return $out;
}

function page_switch_edit(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $sw = $id > 0 ? switch_or_404($id) : array_fill_keys(array_keys(store_def('switches')['schema']['switches']), null);
    if ($id === 0) {
        $sw['template'] = get_str('template', 20) ?: 'o:J9085A';
        $sw['settings'] = json_encode(['ssh' => '1', 'telnet' => '0', 'web' => '0', 'stp' => '1', 'timezone' => '60', 'dst' => 'Western-Europe', 'snmp_community' => '', 'snmp_rw' => '0']);
    }
    $set = switch_settings($sw);
    $title = $id > 0 ? t('Switch bearbeiten') : t('Neuer Switch');
    page_start($title, 'switches', [[t('Switches'), url(['p' => 'switches'])], [$id > 0 ? (string)$sw['name'] : $title, null]]);
    heading($title, th('database/switches.sql'));
    $tplOpts = [];
    foreach (switch_template_list() as $ref => $t2) {
        $tplOpts[$ref] = $t2['name'] . ' · ' . $t2['product_no'] . ($t2['builtin'] ? ' (' . t('Original') . ')' : ' (' . t('eigene Vorlage') . ')');
    }
    $netOpts = ['' => '— ' . t('kein Netzwerk') . ' —'];
    foreach (net_list() as $slug => $n) {
        $netOpts[$slug] = $n['name'] . ($n['vlan_id'] !== null ? ' · VLAN ' . $n['vlan_id'] : '') . ' · ' . $n['cidr'];
    }
    echo '<form method="post" action="' . h(url(['a' => 'switch_save'])) . '" class="form-layout" autocomplete="off">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$sw['id'] . '">'
        . '<section class="card"><h2 class="section-label">' . th('Switch') . '</h2><div class="form-grid cols-4">'
        . f_text('name', t('Name') . ' *', (string)$sw['name'], ['required' => true, 'maxlength' => 60, 'placeholder' => 'SW-Core-01'])
        . f_select('template', t('Modell / Vorlage'), $tplOpts, (string)$sw['template'], ['class' => 'span2'])
        . f_text('location', t('Standort / Rack'), (string)$sw['location'], ['maxlength' => 120])
        . f_select('net', t('Management-Netzwerk'), $netOpts, (string)$sw['net'], ['class' => 'span2'])
        . f_text('mgmt_ip', t('Management-IP'), (string)$sw['mgmt_ip'], ['maxlength' => 15, 'class' => 'mono-input'])
        . f_text('mgmt_mask', t('Netzmaske'), (string)($sw['mgmt_mask'] ?: '255.255.255.0'), ['maxlength' => 15, 'class' => 'mono-input'])
        . f_text('serial', t('Seriennummer'), (string)$sw['serial'], ['maxlength' => 40])
        . f_text('firmware', t('Firmware'), (string)$sw['firmware'], ['maxlength' => 40, 'placeholder' => 'R.11.xx'])
        . ($id === 0 ? '<div class="field check-field span2">' . f_check('link_host', t('Eintrag im Management-Netzwerk anlegen bzw. verknüpfen'), true, th('für Ping, Status und SSH-Terminal')) . '</div>' : '')
        . f_textarea('notes', t('Notizen'), (string)$sw['notes'], 2)
        . '</div></section><section class="card"><h2 class="section-label">' . th('Einstellungen') . '</h2>' . switch_settings_fields($set) . '</section>'
        . '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Speichern') . '</button>' . btn(t('Abbrechen'), $id > 0 ? url(['p' => 'switch', 'id' => $id]) : url(['p' => 'switches']), 'ghost') . '</div></form>';
    page_end();
}

function action_switch_save(): void
{
    $id = in_int('id');
    $old = $id > 0 ? switch_or_404($id) : null;
    $name = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', in_str('name', 60)) ?? '');
    $ref = in_str('template', 20);
    $net = in_str('net', 60);
    $ip = in_str('mgmt_ip', 15);
    $err = [];
    if ($name === '') {
        $err[] = t('Name fehlt.');
    }
    if (switch_template($ref) === null) {
        $err[] = t('Vorlage nicht gefunden.');
    }
    if ($net !== '' && !isset(net_list()[$net])) {
        $err[] = t('Netzwerk „{n}“ gibt es nicht.', ['n' => $net]);
    }
    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $err[] = t('IP-Adresse ungültig.');
    }
    if ($err !== []) {
        foreach ($err as $e) {
            flash($e, 'error');
        }
        redirect(url(['p' => 'switch_edit', 'id' => $id ?: null]));
    }
    $set = switch_settings_from_input((array)(input()['set'] ?? []));
    $netMeta = $net !== '' ? net_list()[$net] : null;
    $hostId = $old['host_id'] ?? null;
    // Eintrag im Netzwerk anlegen/verknüpfen
    if ($id === 0 && in_bool('link_host') && $net !== '' && $ip !== '') {
        $hostId = net_write($net, static function (array &$n) use ($ip, $name): int {
            foreach ($n['hosts'] as $h) {
                if ((string)$h['ip'] === $ip) {
                    return (int)$h['id'];
                }
            }
            return net_put($n, 'hosts', ['hostname' => $name, 'ip' => $ip, 'kind' => 'Switch', 'icon' => 'g:switch', 'monitor' => 1, 'mac' => MAC_NOT_FOUND,
                'created_at' => now_str(), 'created_by' => current_actor(), 'updated_at' => now_str(), 'updated_by' => current_actor()]);
        });
    }
    $row = ['name' => $name, 'template' => $ref, 'net' => $net ?: null, 'host_id' => $hostId, 'mgmt_vlan' => $netMeta['vlan_id'] ?? null, 'mgmt_ip' => $ip ?: null,
        'mgmt_mask' => cidr_mask_v4(in_str('mgmt_mask', 15)), 'serial' => in_str('serial', 40), 'firmware' => preg_replace('/[^A-Za-z0-9._-]/', '', in_str('firmware', 40)),
        'location' => in_str('location', 120), 'notes' => in_str('notes', 2000), 'settings' => json_encode($set, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    $sid = store_write('switches', static function (array &$st) use ($row, $old): int {
        $user = current_actor();
        if ($old !== null) {
            $sid = store_put('switches', $st, 'switches', array_merge($old, $row, ['updated_at' => now_str(), 'updated_by' => $user]));
            if ($old['template'] !== $row['template']) {
                switch_apply_template($st, $sid, $row['template'], false, false);
            }
            switch_ensure_vlans($st, $sid);
            return $sid;
        }
        $sid = store_put('switches', $st, 'switches', $row + ['created_at' => now_str(), 'created_by' => $user, 'updated_at' => now_str(), 'updated_by' => $user]);
        $settings = $row['settings'];
        switch_apply_template($st, $sid, $row['template'], true, false);
        // eigene Einstellungen aus dem Formular behalten
        store_put('switches', $st, 'switches', array_merge($st['switches'][$sid], ['settings' => $settings]));
        return $sid;
    });
    audit($old ? 'switch_update' : 'switch_create', $old ? ['aenderungen' => audit_diff($old, array_merge($old, $row))] : ['daten' => $row], null, 'ok', ['type' => 'switch', 'id' => $sid, 'label' => $name]);
    flash($old ? t('Switch gespeichert.') : t('Switch „{s}“ angelegt.', ['s' => $name]));
    redirect(url(['p' => 'switch', 'id' => $sid]));
}

function action_switch_delete(): void
{
    $sw = switch_or_404(in_int('id'));
    $sid = (int)$sw['id'];
    $removed = store_write('switches', static function (array &$st) use ($sid): array {
        $r = ['ports' => 0, 'vlans' => 0];
        unset($st['switches'][$sid]);
        foreach (['switch_ports' => 'ports', 'switch_vlans' => 'vlans'] as $t => $k) {
            foreach ($st[$t] as $i => $row) {
                if ((int)$row['switch_id'] === $sid) {
                    unset($st[$t][$i]);
                    $r[$k]++;
                }
            }
        }
        return $r;
    });
    audit('switch_delete', ['datensatz' => $sw, 'entfernt' => $removed], null, 'ok', ['type' => 'switch', 'id' => $sid, 'label' => $sw['name']]);
    flash(t('Switch „{s}“ gelöscht.', ['s' => $sw['name']]));
    redirect(url(['p' => 'switches']));
}

function action_switch_apply(): void
{
    $sw = switch_or_404(in_int('id'));
    $sid = (int)$sw['id'];
    $ref = in_str('template', 20);
    $sum = store_write('switches', static fn(array &$st): array => switch_apply_template($st, $sid, $ref, in_bool('ports'), in_bool('settings')));
    audit('switch_apply', ['switch' => $sw['name'], 'vorlage' => $ref, 'ergebnis' => $sum], null, 'ok', ['type' => 'switch', 'id' => $sid, 'label' => $sw['name']]);
    flash(t('Vorlage angewendet: {a} Ports neu, {r} entfernt, {c} geändert, {s} Einstellungen.', ['a' => $sum['added'], 'r' => $sum['removed'], 'c' => $sum['changed'], 's' => $sum['settings']]));
    redirect(url(['p' => 'switch', 'id' => $sid]));
}

function action_switch_import(): void
{
    $sw = switch_or_404(in_int('id'));
    $sid = (int)$sw['id'];
    $cfg = switch_parse_procurve((string)(input()['config'] ?? ''));
    if ($cfg['vlans'] === [] && empty($cfg['settings']['hostname'])) {
        flash(t('Keine ProCurve-Konfiguration erkannt (vlan- bzw. hostname-Zeilen fehlen).'), 'error');
        redirect(url(['p' => 'switch', 'id' => $sid]));
    }
    $sum = store_write('switches', static fn(array &$st): array => switch_import($st, $sid, $cfg));
    audit('switch_import', ['switch' => $sw['name'], 'ergebnis' => $sum], null, 'ok', ['type' => 'switch', 'id' => $sid, 'label' => $sw['name']]);
    flash(t('Konfiguration eingelesen: {p} Ports, {v} VLANs, {t} Trunks{m}.', ['p' => $sum['ports'], 'v' => $sum['vlans'], 't' => $sum['trunks'],
        'm' => $sum['product'] ? ' · ' . $sum['product'] . ' ' . $sum['firmware'] : '']) . ($sum['unknown'] ? ' ' . t('{n} Zeilen nicht ausgewertet.', ['n' => $sum['unknown']]) : ''));
    redirect(url(['p' => 'switch', 'id' => $sid]));
}

// --- Konfiguration --------------------------------------------------------------------------------

function page_switch_config(): void
{
    $sw = switch_or_404((int)($_GET['id'] ?? 0));
    $cfg = switch_config((int)$sw['id']);
    $tpl = switch_template((string)$sw['template']);
    page_start(t('Konfiguration'), 'switches', [[t('Switches'), url(['p' => 'switches'])], [(string)$sw['name'], url(['p' => 'switch', 'id' => $sw['id']])], [t('Konfiguration'), null]]);
    heading(t('Konfiguration · {s}', ['s' => $sw['name']]), h(($tpl['cli'] ?? '') === 'procurve' ? t('ProCurve-CLI (running-config) – zum Einspielen in der Konsole oder per SSH-Terminal') : t('Portplan (Klartext)')),
        btn(t('Download .cfg'), url(['a' => 'switch_cfg_download', 'id' => $sw['id']]), 'primary', 'download') . copy_btn($cfg, t('Kopieren'))
        . ($sw['net'] && $sw['host_id'] && can('terminal') && terminal_available() ? btn(t('SSH-Terminal'), url(['p' => 'terminal', 'n' => $sw['net'], 'id' => $sw['host_id'], 'auto' => 1]), 'secondary', 'terminal') : ''));
    echo '<pre class="console sw-config">' . h($cfg) . '</pre>';
    echo '<p class="hint">' . th('Einspielen: im SSH-Terminal „configure“ eingeben, die Zeilen einfügen und mit „write memory“ speichern. Die erste Zeile (Kommentar) wird ignoriert.') . '</p>';
    audit('switch_config', ['switch' => $sw['name'], 'sha256' => hash('sha256', $cfg)], null, 'ok', ['type' => 'switch', 'id' => $sw['id'], 'label' => $sw['name']]);
    page_end();
}

function action_switch_cfg_download(): void
{
    $sw = switch_or_404((int)($_GET['id'] ?? 0));
    $cfg = switch_config((int)$sw['id']);
    audit('switch_config', ['switch' => $sw['name'], 'download' => true, 'sha256' => hash('sha256', $cfg)], null, 'ok', ['type' => 'switch', 'id' => $sw['id'], 'label' => $sw['name']]);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . (preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$sw['name']) ?: 'switch') . '.cfg"');
    header('Cache-Control: no-store');
    echo $cfg;
}

// --- Vorlagen ---------------------------------------------------------------------------------------

function page_switch_templates(): void
{
    $list = switch_template_list();
    $used = [];
    foreach (switches_list() as $sw) {
        $used[(string)$sw['template']][] = $sw['name'];
    }
    page_start(t('Switch-Vorlagen'), 'switches', [[t('Switches'), url(['p' => 'switches'])], [t('Vorlagen'), null]]);
    heading(t('Switch-Vorlagen'), th('Original-Vorlagen nach Herstellerangaben (nicht änderbar) und eigene Vorlagen'), can('edit') ? btn(t('Neue Vorlage'), url(['p' => 'switch_template_edit']), 'primary', 'plus') : '');
    foreach ([true, false] as $builtin) {
        echo '<h2 class="section-label spaced">' . ($builtin ? th('Original-Vorlagen') : th('Eigene Vorlagen')) . '</h2>';
        $items = array_filter($list, static fn($t) => $t['builtin'] === $builtin);
        if ($items === []) {
            echo '<p class="dim">' . th('Noch keine eigenen Vorlagen. Tipp: ein Original kopieren und Port-Vorgaben ergänzen.') . '</p>';
        }
        echo '<div class="tpl-grid wide">';
        foreach ($items as $ref => $t2) {
            echo '<section class="card tpl-card"><div class="tpl-head"><div><strong>' . h($t2['name']) . '</strong> ' . badge($t2['product_no'], 'mono-badge')
                . ($builtin ? ' <span class="badge state-unknown">' . th('Original') . '</span>' : '') . '<div class="cell-sub">' . h($t2['description']) . '</div></div></div>'
                . '<div class="fp-scroll">' . switch_faceplate($t2, [], []) . '</div>'
                . '<div class="tpl-foot"><span class="dim small">' . th('{n} Ports', ['n' => count($t2['port_list'])]) . ' · CLI ' . h($t2['cli']) . (isset($used[$ref]) ? ' · ' . th('benutzt von {l}', ['l' => implode(', ', $used[$ref])]) : '') . '</span>'
                . '<span class="tpl-acts">' . btn(t('Ansehen'), url(['p' => 'switch_template', 'ref' => $ref]), 'ghost small')
                . (can('edit') ? btn(t('Kopie als eigene Vorlage'), url(['p' => 'switch_template_edit', 'copy' => $ref]), 'secondary small', 'copy')
                    . (!$builtin ? btn(t('Bearbeiten'), url(['p' => 'switch_template_edit', 'ref' => $ref]), 'secondary small', 'edit') : '')
                    . btn(t('Switch mit dieser Vorlage'), url(['p' => 'switch_edit', 'template' => $ref]), 'ghost small', 'plus') : '')
                . '</span></div></section>';
        }
        echo '</div>';
    }
    page_end();
}

function page_switch_template(): void
{
    $t2 = switch_template(get_str('ref', 20));
    if ($t2 === null) {
        render_error_page(t('Nicht gefunden'), t('Vorlage nicht gefunden.'), 404);
        exit;
    }
    page_start($t2['name'], 'switches', [[t('Switches'), url(['p' => 'switches'])], [t('Vorlagen'), url(['p' => 'switch_templates'])], [$t2['name'], null]]);
    heading($t2['name'] . ' (' . $t2['product_no'] . ')', h($t2['description']), can('edit') ? btn(t('Kopie als eigene Vorlage'), url(['p' => 'switch_template_edit', 'copy' => $t2['ref']]), 'secondary', 'copy')
        . (!$t2['builtin'] ? btn(t('Bearbeiten'), url(['p' => 'switch_template_edit', 'ref' => $t2['ref']]), 'secondary', 'edit') : '') : '');
    echo '<section class="card fp-card">' . switch_faceplate($t2, [], []) . '</section><div class="grid-2"><section class="card"><h2 class="section-label">' . th('Hardware') . '</h2>';
    echo kv_list([t('Hersteller') => h($t2['vendor']), t('Modell') => h($t2['model']), t('Produktnummer') => '<span class="mono">' . h($t2['product_no']) . '</span>',
        t('Software') => h($t2['software']), t('Konsole') => h((string)$t2['console']), t('Werks-Hostname') => h((string)$t2['default_hostname']),
        t('Konfigurations-Sprache') => h($t2['cli'] === 'procurve' ? 'ProCurve / ProVision CLI' : t('allgemein (Portplan)'))]);
    echo '<table class="mini-table"><thead><tr><th>' . th('Ports') . '</th><th>' . th('Typ') . '</th><th>' . th('Anschluss') . '</th></tr></thead><tbody>';
    foreach (preg_split('/\R/', $t2['ports']) ?: [] as $line) {
        $f = array_map('trim', explode(';', $line));
        if (($f[0] ?? '') !== '') {
            echo '<tr><td class="mono">' . h($f[0]) . '</td><td>' . h($f[1] ?? '') . '</td><td>' . h(['rj45' => 'RJ-45', 'sfp' => 'SFP', 'combo' => 'RJ-45 / SFP', 'sfpplus' => 'SFP+'][$f[2] ?? 'rj45'] ?? '') . '</td></tr>';
        }
    }
    echo '</tbody></table></section><section class="card"><h2 class="section-label">' . ($t2['builtin'] ? th('Werkseinstellungen') : th('Einstellungen der Vorlage')) . '</h2>';
    $kv = [];
    foreach ($t2['settings'] as $k => $v) {
        if ((string)$v !== '') {
            $kv[$k] = '<span class="mono">' . h((string)$v) . '</span>';
        }
    }
    echo kv_list($kv ?: [t('Einstellungen') => '–']);
    echo '<h2 class="section-label spaced">' . th('Port-Vorgaben') . '</h2><pre class="console small">' . h($t2['port_presets'] !== '' ? $t2['port_presets'] : '; ' . t('alle Ports untagged in VLAN 1 (DEFAULT_VLAN)')) . '</pre></section></div>';
    page_end();
}

function page_switch_template_edit(): void
{
    $ref = get_str('ref', 20);
    $copy = get_str('copy', 20);
    $t2 = $ref !== '' ? switch_template($ref) : ($copy !== '' ? switch_template($copy) : null);
    if ($ref !== '' && ($t2 === null || $t2['builtin'])) {
        render_error_page(t('Nicht änderbar'), t('Original-Vorlagen können nicht geändert werden – bitte eine Kopie anlegen.'), 403);
        exit;
    }
    $isNew = $ref === '';
    $t2 ??= ['name' => '', 'vendor' => '', 'model' => '', 'product_no' => '', 'software' => '', 'cli' => 'procurve', 'layout' => 'oddeven', 'console' => '', 'default_hostname' => '',
        'ports' => "1-24;10/100/1000Base-T;rj45\n25-28;SFP;sfp", 'settings' => [], 'port_presets' => '', 'description' => '', 'base' => ''];
    if ($isNew && $copy !== '') {
        $t2['name'] = t('{n} – eigene Vorlage', ['n' => $t2['name']]);
        if ($t2['builtin'] ?? false) {
            $t2['settings'] = ['ssh' => '1', 'telnet' => '0', 'web' => '0', 'stp' => '1', 'timezone' => '60', 'dst' => 'Western-Europe', 'snmp_community' => '', 'snmp_rw' => '0'];
            $t2['port_presets'] = "# Ports;untagged;tagged;Name;an/aus;Trunk\n";
        }
    }
    $title = $isNew ? t('Neue Switch-Vorlage') : t('Vorlage bearbeiten');
    page_start($title, 'switches', [[t('Switches'), url(['p' => 'switches'])], [t('Vorlagen'), url(['p' => 'switch_templates'])], [$title, null]]);
    heading($title, $copy !== '' ? th('Kopie von {r}', ['r' => $copy]) : '');
    echo '<form method="post" action="' . h(url(['a' => 'switch_tpl_save'])) . '" class="form-layout" autocomplete="off">' . csrf_field()
        . '<input type="hidden" name="ref" value="' . h($isNew ? '' : $ref) . '"><input type="hidden" name="base" value="' . h((string)($t2['base'] ?? '')) . '">'
        . '<section class="card"><h2 class="section-label">' . th('Hardware') . '</h2><div class="form-grid cols-4">'
        . f_text('name', t('Name der Vorlage') . ' *', (string)$t2['name'], ['required' => true, 'maxlength' => 80, 'class' => 'span2'])
        . f_text('vendor', t('Hersteller'), (string)$t2['vendor'], ['maxlength' => 40]) . f_text('model', t('Modell'), (string)$t2['model'], ['maxlength' => 40])
        . f_text('product_no', t('Produktnummer'), (string)$t2['product_no'], ['maxlength' => 20, 'class' => 'mono-input']) . f_text('software', t('Software'), (string)$t2['software'], ['maxlength' => 20])
        . f_select('cli', t('Konfigurations-Sprache'), ['procurve' => 'ProCurve / ProVision CLI', 'generic' => t('allgemein (Portplan)')], (string)$t2['cli'])
        . f_select('layout', t('Frontansicht'), ['oddeven' => t('zwei Reihen (ungerade oben)'), 'row' => t('eine Reihe')], (string)$t2['layout'])
        . f_text('console', t('Konsole'), (string)$t2['console'], ['maxlength' => 40]) . f_text('default_hostname', t('Werks-Hostname'), (string)$t2['default_hostname'], ['maxlength' => 40])
        . f_textarea('ports', t('Port-Aufbau (je Zeile: Ports;Typ;rj45|sfp|combo|sfpplus)'), (string)$t2['ports'], 4, ['mono' => true, 'class' => 'span2'])
        . f_textarea('description', t('Beschreibung'), (string)$t2['description'], 4, ['class' => 'span2'])
        . '</div></section>'
        . '<section class="card"><h2 class="section-label">' . th('Port-Vorgaben') . '</h2>'
        . '<p class="hint">' . th('Je Zeile: Ports;untagged-VLAN;tagged-VLANs;Name;an/aus;Trunk – leere Felder bleiben unverändert. „none“ = kein untagged-VLAN, „all“ = alle VLANs tagged. Beispiel: 1-22;30;;Arbeitsplatz;an · 25-26;1;all;Uplink;an;Trk1') . '</p>'
        . f_textarea('port_presets', '', (string)$t2['port_presets'], 6, ['mono' => true, 'placeholder' => "1-22;30;;Arbeitsplatz;an\n23-24;;;frei;aus\n25-26;1;all;Uplink;an;Trk1"])
        . '</section><section class="card"><h2 class="section-label">' . th('Einstellungen der Vorlage') . '</h2><p class="hint">' . th('Nur gesetzte Werte werden beim Anwenden übernommen.') . '</p>'
        . switch_settings_fields($t2['settings']) . '</section>'
        . '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Vorlage speichern') . '</button>' . btn(t('Abbrechen'), url(['p' => 'switch_templates']), 'ghost') . '</div></form>';
    if (!$isNew && can('delete')) {
        echo '<section class="card danger-zone spaced-top">' . post_button(t('Vorlage löschen'), ['a' => 'switch_tpl_delete'], 'danger', t('Vorlage löschen?'), 'trash', ['ref' => $ref]) . '</section>';
    }
    page_end();
}

function action_switch_tpl_save(): void
{
    $ref = in_str('ref', 20);
    $old = $ref !== '' ? switch_template($ref) : null;
    if ($ref !== '' && ($old === null || $old['builtin'])) {
        flash(t('Original-Vorlagen können nicht geändert werden – bitte eine Kopie anlegen.'), 'error');
        redirect(url(['p' => 'switch_templates']));
    }
    $clean = static fn(string $s, int $n): string => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F"]/', '', $s) ?? ''), 0, $n);
    $ports = mb_substr(str_replace("\r", '', (string)(input()['ports'] ?? '')), 0, 4000);
    $presets = mb_substr(str_replace(["\r", '"'], '', (string)(input()['port_presets'] ?? '')), 0, 8000);
    $row = [
        'name' => $clean(in_str('name', 80), 80), 'vendor' => $clean(in_str('vendor', 40), 40), 'model' => $clean(in_str('model', 40), 40),
        'product_no' => preg_replace('/[^A-Za-z0-9._-]/', '', in_str('product_no', 20)), 'software' => $clean(in_str('software', 20), 20),
        'cli' => in_str('cli', 10) === 'generic' ? 'generic' : 'procurve', 'layout' => in_str('layout', 10) === 'row' ? 'row' : 'oddeven',
        'console' => $clean(in_str('console', 40), 40), 'default_hostname' => $clean(in_str('default_hostname', 40), 40), 'ports' => $ports, 'port_presets' => $presets,
        'description' => mb_substr(in_str('description', 1000), 0, 1000), 'base' => preg_replace('/[^A-Za-z0-9]/', '', in_str('base', 20)),
        'settings' => json_encode(switch_settings_from_input((array)(input()['set'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $err = [];
    if ($row['name'] === '') {
        $err[] = t('Name fehlt.');
    }
    $pl = switch_ports_from_spec($ports);
    if ($pl === [] || count($pl) > 128) {
        $err[] = t('Port-Aufbau: 1 bis 128 Ports angeben (z. B. 1-24;10/100Base-TX;rj45).');
    }
    if ($err !== []) {
        foreach ($err as $e) {
            flash($e, 'error');
        }
        redirect(url(['p' => 'switch_template_edit', 'ref' => $ref ?: null]));
    }
    $id = store_write('switches', static function (array &$st) use ($row, $old): int {
        $u = current_actor();
        return $old ? store_put('switches', $st, 'switch_templates', array_merge($old['row'], $row, ['updated_at' => now_str(), 'updated_by' => $u]))
            : store_put('switches', $st, 'switch_templates', $row + ['created_at' => now_str(), 'created_by' => $u, 'updated_at' => now_str(), 'updated_by' => $u]);
    });
    audit('switch_tpl_save', ['vorlage' => $row['name'], 'aktion' => $old ? 'geändert' : 'angelegt', 'ports' => count($pl)], null, 'ok', ['type' => 'switch_template', 'id' => $id, 'label' => $row['name']]);
    flash(t('Vorlage „{n}“ gespeichert.', ['n' => $row['name']]));
    redirect(url(['p' => 'switch_template', 'ref' => 'c:' . $id]));
}

function action_switch_tpl_delete(): void
{
    $ref = in_str('ref', 20);
    $t2 = switch_template($ref);
    if ($t2 === null || $t2['builtin']) {
        flash(t('Original-Vorlagen können nicht gelöscht werden.'), 'error');
        redirect(url(['p' => 'switch_templates']));
    }
    foreach (switches_list() as $sw) {
        if ($sw['template'] === $ref) {
            flash(t('Vorlage wird noch von „{s}“ benutzt.', ['s' => $sw['name']]), 'error');
            redirect(url(['p' => 'switch_templates']));
        }
    }
    store_write('switches', static function (array &$st) use ($t2): void {
        unset($st['switch_templates'][$t2['id']]);
    });
    audit('switch_tpl_delete', ['vorlage' => $t2['name'], 'aktion' => 'gelöscht'], null, 'ok', ['type' => 'switch_template', 'id' => $t2['id'], 'label' => $t2['name']]);
    flash(t('Vorlage „{n}“ gelöscht.', ['n' => $t2['name']]));
    redirect(url(['p' => 'switch_templates']));
}
