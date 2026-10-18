<?php
/**
 * Übersicht, Netzwerk-Seite (Host-Tabelle wie eine Datenbank, Statusfarben +
 * Legende), Neues Netzwerk (mehrere auf einmal), bearbeiten/löschen, Suche, Export.
 */

declare(strict_types=1);

function net_or_404(string $slug): array
{
    if (!net_valid_slug($slug)) {
        throw new NetNotFound(t('Ungültiger Netzwerk-Kurzname.'));
    }
    return net_load($slug);
}

function net_quick_integrity(array $net): array
{
    [$sealOk, $why] = net_seal_ok($net);
    $bad = 0;
    foreach (['network' => [1 => $net['network']], 'hosts' => $net['hosts'], 'services' => $net['services']] as $t => $rows) {
        foreach ($rows as $r) {
            if (!sqlfile_row_valid($net['slug'], $t, $r)) {
                $bad++;
            }
        }
    }
    return ['ok' => $sealOk && $bad === 0, 'seal' => $sealOk, 'why' => $why, 'bad_rows' => $bad];
}

function host_target(array $h): ?string
{
    if (!empty($h['ip']) && ip_valid((string)$h['ip'])) {
        return (string)$h['ip'];
    }
    return !empty($h['hostname']) && hostname_valid((string)$h['hostname']) ? (string)$h['hostname'] : null;
}

/** Statusklasse eines Hosts: up | down | unknown | nocheck */
function host_state(array $h, ?array $s): string
{
    if ((int)$h['monitor'] !== 1) {
        return 'nocheck';
    }
    return $s === null ? 'unknown' : ($s['ok'] ? 'up' : 'down');
}

/** Statusklasse eines Dienstes: up | down | unknown | nocheck */
function svc_state(array $sv, ?array $ss): string
{
    if ($sv['status_check'] !== null && (int)$sv['status_check'] === 0) {
        return 'nocheck';
    }
    if ($ss === null || !array_key_exists('ok', $ss) || $ss['ok'] === null) {
        return 'unknown';
    }
    return $ss['ok'] ? 'up' : 'down';
}

function state_title(string $state): string
{
    return match ($state) {
        'up' => t('verfügbar / online'), 'down' => t('nicht verfügbar / offline'),
        'nocheck' => t('ohne Statusprüfung angelegt'), default => t('unbekannt (noch nicht geprüft)'),
    };
}

function status_dot(array $h, ?array $s): string
{
    $state = host_state($h, $s);
    $t = state_title($state) . ($s !== null && $state !== 'nocheck' ? ' · ' . fmt_ago((int)$s['ts']) . (isset($s['avg']) && $s['avg'] !== null ? ' · ' . $s['avg'] . ' ms' : '') : '');
    return '<i class="dot ' . $state . '" title="' . h($t) . '"></i>';
}

function svc_chip(array $sv, ?array $ss): string
{
    $state = svc_state($sv, $ss);
    $title = $sv['proto'] . ($sv['port'] ? '/' . $sv['port'] : '') . ' · ' . state_title($state) . ($ss && $state !== 'nocheck' ? ' · ' . $ss['state'] . ' · ' . fmt_ago((int)$ss['ts']) : '');
    return '<span class="svc-chip ' . $state . '" data-svc="' . (int)$sv['id'] . '" title="' . h($title) . '">' . h($sv['name']) . '</span>';
}

function net_kind_label(array $n): string
{
    if ($n['kind'] === 'VLAN') {
        return $n['vlan_id'] !== null ? 'VLAN ' . $n['vlan_id'] : 'VLAN';
    }
    return t(NET_KINDS[$n['kind']] ?? (string)$n['kind']) . ($n['vlan_id'] !== null ? ' · VLAN ' . $n['vlan_id'] : '');
}

function fmt_bytes(int $b): string
{
    return $b < 1024 ? $b . ' B' : ($b < 1048576 ? fmt_num($b / 1024, 1) . ' KB' : fmt_num($b / 1048576, 1) . ' MB');
}

function stat_tile(string $label, string $value, string $sub, string $ic, string $cls = ''): string
{
    return '<div class="stat-tile ' . h($cls) . '">' . icon($ic, 18, 'stat-icon') . '<div class="stat-label">' . h($label) . '</div><div class="stat-value">' . h($value) . '</div><div class="stat-sub">' . h($sub) . '</div></div>';
}

function audit_row_compact(array $r): string
{
    $res = match ($r['result']) {
        'ok' => '', 'fail', 'denied' => ' bad', default => ' warn',
    };
    return '<div class="track-list-row audit-mini' . $res . '"><div class="tl-info"><div class="tl-title">' . h(audit_label((string)$r['action']))
        . ($r['object_label'] ? ' · <span class="dim">' . h($r['object_label']) . '</span>' : '') . '</div>'
        . '<div class="tl-artist">' . h((string)($r['username'] ?? '–')) . ' · ' . h((string)$r['ip']) . ($r['net'] ? ' · ' . h($r['net']) : '') . '</div></div>'
        . '<div class="tl-actions"><span class="dim small">' . h(fmt_datetime(substr((string)$r['ts'], 0, 19), true)) . '</span></div></div>';
}

// --- Übersicht ---------------------------------------------------------------------------------

function page_dashboard(): void
{
    $nets = net_list();
    $totHosts = $totSvc = $up = $down = 0;
    foreach ($nets as $n) {
        $totHosts += (int)$n['hosts'];
        $totSvc += (int)$n['services'];
        $hl = net_health($n);
        $up += $hl['up'];
        $down += $hl['down'];
    }
    page_start(t('Übersicht'), 'dashboard');
    $actions = can('edit') ? btn(t('Neues Netzwerk'), url(['p' => 'net_new']), 'primary', 'plus') : '';
    heading(t('Übersicht'), th('{n} Netzwerke · {h} Hosts · {s} Dienste', ['n' => count($nets), 'h' => $totHosts, 's' => $totSvc]), $actions);

    $last = integrity_last();
    echo '<div class="stat-grid">'
        . stat_tile(t('Netzwerke'), (string)count($nets), t('LAN, VLAN, WLAN …'), 'lan')
        . stat_tile(t('Hosts'), (string)$totHosts, t('{n} Dienste erfasst', ['n' => $totSvc]), 'server')
        . stat_tile(t('Online'), (string)$up, $down > 0 ? t('{n} offline', ['n' => $down]) : t('keiner offline'), 'pulse', $down > 0 ? 'warn' : 'ok')
        . stat_tile(t('Integrität'), $last === null ? '?' : ($last['ok'] ? 'OK' : t('VERLETZT')), $last === null ? t('noch nicht geprüft') : t('geprüft {t}', ['t' => fmt_ago((int)$last['ts'])]), 'shield', $last === null ? '' : ($last['ok'] ? 'ok' : 'bad'))
        . '</div>';

    echo '<h2 class="section-label spaced">' . th('Netzwerke') . '</h2>';
    if ($nets === []) {
        echo '<div class="empty-state">' . icon('lan', 44) . '<p>' . th('Noch keine Netzwerke angelegt.') . '</p>'
            . (can('edit') ? btn(t('Erstes Netzwerk anlegen'), url(['p' => 'net_new']), 'primary', 'plus') : '') . '</div>';
    } else {
        echo '<div class="station-grid">';
        foreach ($nets as $n) {
            $hl = net_health($n);
            $total = max(1, $hl['up'] + $hl['down'] + $hl['unknown']);
            echo '<a class="station-card" href="' . h(url(['p' => 'net', 'n' => $n['slug']])) . '">'
                . '<div class="station-cover kind-' . h(strtolower((string)$n['kind'])) . '">' . icon(net_icon((string)$n['kind']), 26) . '</div>'
                . '<div class="station-meta"><div class="station-name">' . h($n['name']) . '</div>'
                . '<div class="station-sub">' . h(net_kind_label($n)) . ($n['cidr'] !== '' ? ' · <span class="mono">' . h($n['cidr']) . '</span>' : '') . '</div>'
                . '<div class="health-bar" aria-hidden="true"><progress class="hb-up" max="' . $total . '" value="' . $hl['up'] . '"></progress></div>'
                . '<div class="station-foot"><span>' . th('{n} Hosts', ['n' => (int)$n['hosts']]) . '</span><span>' . th('{n} online', ['n' => $hl['up']])
                . ($hl['down'] ? ' · <b class="txt-bad">' . th('{n} offline', ['n' => $hl['down']]) . '</b>' : '') . '</span></div></div></a>';
        }
        if (can('edit')) {
            echo '<a class="station-card add-card" href="' . h(url(['p' => 'net_new'])) . '"><div class="station-cover">' . icon('plus', 26) . '</div><div class="station-meta"><div class="station-name">' . th('Neues Netzwerk') . '</div><div class="station-sub">' . th('ein oder mehrere LANs/VLANs anlegen') . '</div></div></a>';
        }
        echo '</div>';
    }

    if (can('audit')) {
        $rows = sys_all('SELECT * FROM audit ORDER BY id DESC LIMIT 8');
        echo '<h2 class="section-label spaced">' . th('Letzte Aktivität') . ' <a class="section-link" href="' . h(url(['p' => 'audit'])) . '">' . th('Audit-Log') . '</a></h2><div class="track-list compact">';
        foreach ($rows as $r) {
            echo audit_row_compact($r);
        }
        echo '</div>';
    }
    page_end();
}

// --- Netzwerk-Seite ------------------------------------------------------------------------------

function page_net(): void
{
    $net = net_or_404(get_str('n', 60));
    $n = $net['network'];
    $st = status_get($net['slug']);
    $integ = net_quick_integrity($net);
    $info = cidr_info($n['cidr']);
    $svcByHost = [];
    foreach ($net['services'] as $s) {
        $svcByHost[(int)$s['host_id']][] = $s;
    }
    $counts = ['up' => 0, 'down' => 0, 'unknown' => 0, 'nocheck' => 0];
    foreach ($net['hosts'] as $hh) {
        $counts[host_state($hh, $st['hosts'][(string)$hh['id']] ?? null)]++;
    }
    $used = array_filter(array_map(static fn($x) => (string)$x['ip'], $net['hosts']));
    $inNet = array_filter($used, static fn($ip) => ip_in_cidr($ip, $n['cidr']));

    page_start($n['name'], 'net:' . $net['slug'], [[t('Netzwerke'), url()], [$n['name'], null]]);
    $badges = '<span class="live-badge"><i></i>' . h(net_kind_label($n)) . '</span>'
        . ($n['cidr'] ? badge((string)$n['cidr'], 'mono-badge') : '')
        . ($integ['ok'] ? '<span class="badge ok-soft">' . icon('shield', 12) . th('Integrität OK') . '</span>'
            : '<span class="badge bad" title="' . h($integ['why'] ?: t('{n} ungültige Einträge', ['n' => $integ['bad_rows']])) . '">' . icon('shield-x', 12) . th('Integrität verletzt') . '</span>');
    echo '<section class="stage"><div class="stage-body"><div class="stage-cover kind-' . h(strtolower((string)$n['kind'])) . '">' . icon(net_icon((string)$n['kind']), 54) . '</div>'
        . '<div class="stage-info"><div class="stage-badges">' . $badges . '</div><div class="stage-station">' . h($n['name']) . '</div>'
        . '<div class="stage-title">' . h(trim(($n['location'] ? $n['location'] . ' · ' : '') . (string)$n['description']) ?: t('Netzwerk-Datei database/net-{s}.sql', ['s' => $net['slug']])) . '</div></div>'
        . (can('ping') ? '<button type="button" class="rec-pill" id="pingAll" data-url="' . h(url(['a' => 'ping_network'])) . '" data-net="' . h($net['slug']) . '">' . icon('pulse', 16) . th('Alle pingen') . '</button>' : '')
        . '</div></section>';

    if (!$integ['ok']) {
        echo '<div class="flash flash-error">' . th('Diese Netzwerk-Datei hat ihre Integritätsprüfung nicht bestanden.')
            . ($integ['seal'] ? '' : ' (' . h($integ['why']) . ' – ' . th('Schreibschutz aktiv') . ')')
            . ($integ['bad_rows'] ? ' · ' . th('{n} Einträge mit ungültigem Hash', ['n' => $integ['bad_rows']]) : '') . ' '
            . (can('integrity') ? '<a href="' . h(url(['p' => 'integrity', 'n' => $net['slug']])) . '">' . th('Details ansehen') . '</a>' : th('Bitte einen Portal-Admin informieren.')) . '</div>';
    }

    $acts = '';
    if (can('edit')) {
        $acts .= btn(t('Host hinzufügen'), url(['p' => 'host_edit', 'n' => $net['slug']]), 'primary', 'plus');
        $acts .= btn(t('Bearbeiten'), url(['p' => 'net_edit', 'n' => $net['slug']]), 'secondary', 'edit');
    }
    if (can('check') && $net['services'] !== []) {
        $acts .= '<button type="button" class="pill-btn secondary" id="svcCheckNet" data-url="' . h(url(['a' => 'svc_check_network'])) . '" data-net="' . h($net['slug']) . '">' . icon('check', 15) . th('Dienste prüfen') . '</button>';
    }
    $acts .= btn(t('SQL-Datei'), url(['a' => 'net_download', 'n' => $net['slug']]), 'secondary', 'db');
    $acts .= btn('CSV', url(['a' => 'net_csv', 'n' => $net['slug']]), 'secondary', 'csv');
    echo '<div class="action-row">' . $acts . '</div>';

    $usable = $info ? $info['usable'] : null;
    $monitored = count($net['hosts']) - $counts['nocheck'];
    $onlineSub = $counts['down'] > 0 ? t('{n} offline', ['n' => $counts['down']])
        : ($counts['up'] === 0 ? t('noch nicht gepingt') : ($counts['unknown'] > 0 ? t('{n} noch ungeprüft', ['n' => $counts['unknown']]) : t('alle erreichbar')));
    echo '<div class="stat-grid">'
        . stat_tile(t('Hosts'), (string)count($net['hosts']), t('{n} Dienste', ['n' => count($net['services'])]), 'server')
        . stat_tile(t('Online'), $counts['up'] . ' / ' . $monitored, $onlineSub, 'pulse', $counts['down'] > 0 ? 'warn' : ($counts['up'] > 0 ? 'ok' : ''))
        . '<div class="stat-tile">' . icon('chip', 18, 'stat-icon') . '<div class="stat-label">' . th('IP-Belegung') . '</div><div class="stat-value">' . count($inNet)
        . ($usable !== null && is_finite((float)$usable) ? '<small> / ' . fmt_num((float)$usable) . '</small>' : '') . '</div>'
        . ($usable !== null && is_finite((float)$usable) ? '<progress class="usage" max="' . (int)$usable . '" value="' . count($inNet) . '"></progress>' : '<div class="stat-sub">' . ($info ? 'IPv6' : th('kein CIDR hinterlegt')) . '</div>') . '</div>'
        . stat_tile(t('Datei'), fmt_bytes($net['bytes']), 'SHA-256 ' . substr($net['sha256'], 0, 12) . '…', 'file')
        . '</div>';

    echo '<div class="table-toolbar"><div class="filter-input">' . icon('search', 14) . '<input type="search" id="tableFilter" placeholder="' . th('Hosts filtern (IP, Name, MAC, Dienst …)') . '" autocomplete="off"></div>'
        . '<select id="kindFilter" aria-label="' . th('Typ') . '"><option value="">' . th('Alle Typen') . '</option>';
    $kinds = array_unique(array_filter(array_map(static fn($x) => (string)$x['kind'], $net['hosts'])));
    sort($kinds);
    foreach ($kinds as $k) {
        echo '<option value="' . h($k) . '">' . th($k) . '</option>';
    }
    echo '</select><select id="stateFilter" aria-label="' . th('Status') . '"><option value="">' . th('Alle Zustände') . '</option>'
        . '<option value="up">' . th('online') . '</option><option value="down">' . th('offline') . '</option><option value="unknown">' . th('unbekannt') . '</option><option value="nocheck">' . th('ohne Statusprüfung') . '</option></select>'
        . '<span class="dim small" id="filterCount">' . th('{n} Einträge', ['n' => count($net['hosts'])]) . '</span></div>';

    if ($net['hosts'] === []) {
        echo '<div class="empty-state small">' . icon('server', 40) . '<p>' . th('Noch keine Hosts in diesem Netzwerk.') . '</p>'
            . (can('edit') ? btn(t('Ersten Host anlegen'), url(['p' => 'host_edit', 'n' => $net['slug']]), 'primary', 'plus') : '') . '</div>';
    } else {
        echo '<div class="table-wrap"><table class="data-table" id="hostTable" data-net="' . h($net['slug']) . '"><thead><tr>'
            . '<th class="col-dot"></th><th data-sort="ip">' . th('IP-Adresse') . '</th><th data-sort="text">' . th('Name') . '</th><th data-sort="text">' . th('Typ') . '</th><th data-sort="text">MAC</th>'
            . '<th data-sort="text">' . th('System') . '</th><th>' . th('Dienste') . '</th><th data-sort="num">' . th('Antwort') . '</th><th class="col-act"></th></tr></thead><tbody>';
        foreach ($net['hosts'] as $hst) {
            $s = $st['hosts'][(string)$hst['id']] ?? null;
            $state = host_state($hst, $s);
            $href = url(['p' => 'host', 'n' => $net['slug'], 'id' => $hst['id']]);
            $chips = '';
            foreach ($svcByHost[(int)$hst['id']] ?? [] as $sv) {
                $chips .= svc_chip($sv, $st['services'][(string)$sv['id']] ?? null);
            }
            $outside = $hst['ip'] && $n['cidr'] && !ip_in_cidr((string)$hst['ip'], $n['cidr']);
            $search = mb_strtolower(implode(' ', [$hst['ip'], $hst['hostname'], $hst['mac'], $hst['hw_id'], $hst['kind'], $hst['os'], $hst['vendor'], $hst['model'], $hst['tags'], $hst['location'], $hst['owner'],
                implode(' ', array_map(static fn($x) => $x['name'] . ' ' . $x['port'], $svcByHost[(int)$hst['id']] ?? []))]));
            $mac = (string)$hst['mac'] === MAC_NOT_FOUND ? '<span class="badge warn">not found</span>' : h($hst['mac'] ?? '');
            echo '<tr data-href="' . h($href) . '" data-id="' . (int)$hst['id'] . '" data-state="' . $state . '" data-kind="' . h((string)$hst['kind']) . '" data-search="' . h($search) . '">'
                . '<td class="col-dot">' . status_dot($hst, $s) . '</td>'
                . '<td class="mono" data-v="' . h(ip_sort_key((string)$hst['ip'])) . '"><a href="' . h($href) . '">' . h($hst['ip'] ?: '–') . '</a>' . ($outside ? ' <span class="badge warn" title="' . th('liegt nicht in {c}', ['c' => $n['cidr']]) . '">' . th('außerhalb') . '</span>' : '') . '</td>'
                . '<td><div class="cell-title">' . host_icon_html($hst, 16, 'cell-icon') . h($hst['hostname']) . (!empty($hst['master_id']) ? ' <span class="badge master-badge" title="' . th('vom Master gepflegt') . '">' . icon('crown', 11) . 'Master</span>' : '') . '</div>' . ($hst['location'] ? '<div class="cell-sub">' . h($hst['location']) . '</div>' : '') . '</td>'
                . '<td>' . th((string)($hst['kind'] ?? '')) . '</td>'
                . '<td class="mono small">' . $mac . '</td>'
                . '<td><div>' . h($hst['os'] ?? '') . '</div><div class="cell-sub">' . h(trim(($hst['vendor'] ?? '') . ' ' . ($hst['model'] ?? ''))) . '</div></td>'
                . '<td class="chips">' . $chips . '</td>'
                . '<td class="ping-cell" data-v="' . h((string)($s['avg'] ?? 99999)) . '">' . ($state === 'nocheck' ? '<span class="dim">' . th('aus') . '</span>' : ($s === null ? '<span class="dim">–</span>' : ($s['ok'] ? h(($s['avg'] ?? '?') . ' ms') : '<span class="txt-bad">' . th('keine') . '</span>')))
                . '<div class="cell-sub">' . ($s && $state !== 'nocheck' ? h(fmt_ago((int)$s['ts'])) : '') . '</div></td>'
                . '<td class="col-act"><button type="button" class="icon-btn row-ping" data-id="' . (int)$hst['id'] . '" title="Ping" aria-label="Ping ' . h($hst['hostname']) . '">' . icon('pulse', 15) . '</button>'
                . (host_target($hst) !== null ? (can('terminal') && terminal_available() ? '<a class="icon-btn" href="' . h(url(['p' => 'terminal', 'n' => $net['slug'], 'id' => $hst['id'], 'auto' => 1])) . '" title="' . th('SSH-Terminal im Portal') . '" aria-label="' . th('SSH-Terminal') . ' ' . h($hst['hostname']) . '">' . icon('terminal', 15) . '</a>' : '')
                    : '')
                . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo status_legend();

    $kv = [
        t('Kurzname / Datei') => '<span class="mono">database/net-' . h($net['slug']) . '.sql</span>',
        t('Typ') => h(t(NET_KINDS[$n['kind']] ?? (string)$n['kind'])) . ($n['vlan_id'] !== null ? ' · VLAN-ID <span class="mono">' . (int)$n['vlan_id'] . '</span>' : ''),
        t('Netz (CIDR)') => $info ? '<span class="mono">' . h($info['text']) . '</span>' : h($n['cidr']),
        t('Netzmaske') => $info ? '<span class="mono">' . h($info['netmask']) . '</span>' : '',
        t('Bereich') => $info ? '<span class="mono">' . h($info['first'] . ' – ' . $info['last']) . '</span>' : '',
        'Broadcast' => $info && $info['broadcast'] ? '<span class="mono">' . h($info['broadcast']) . '</span>' : '',
        'Gateway' => $n['gateway'] ? '<span class="mono">' . h($n['gateway']) . '</span>' : '',
        'DNS' => $n['dns'] ? '<span class="mono">' . h($n['dns']) . '</span>' : '',
        t('DHCP-Bereich') => $n['dhcp_range'] ? '<span class="mono">' . h($n['dhcp_range']) . '</span>' : '',
        t('Domäne') => h($n['domain']),
        t('Nächste freie IP') => ($free = cidr_next_free($n['cidr'], $used, $n['gateway'])) ? '<span class="mono">' . h($free) . '</span> ' . copy_btn($free) : '',
    ];
    $meta = [
        t('Angelegt') => h(fmt_datetime($n['created_at']) . ' · ' . $n['created_by']),
        t('Geändert') => h(fmt_datetime($n['updated_at']) . ' · ' . $n['updated_by']),
        t('Datei-SHA-256') => '<span class="mono small break">' . h($net['sha256']) . '</span>',
        t('Einträge') => th('{n} (je mit HMAC)', ['n' => count($net['hosts']) + count($net['services']) + 1]),
    ];
    echo '<div class="grid-2 spaced"><section class="card"><h2 class="section-label">' . th('Netzwerk') . '</h2>' . kv_list($kv) . '</section>'
        . '<section class="card"><h2 class="section-label">' . th('Datei & Verlauf') . '</h2>' . kv_list($meta);
    if (can('delete')) {
        echo '<div class="card-foot">' . post_button(t('Netzwerk löschen'), ['a' => 'net_delete'], 'danger',
            t('Netzwerk „{n}“ löschen? Die SQL-Datei wird nach database/archiv/ verschoben.', ['n' => $n['name']]), 'trash', ['n' => $net['slug']]) . '</div>';
    }
    echo '</section></div>';
    audit('net_view', [], null, 'ok', ['net' => $net['slug'], 'type' => 'network', 'label' => $n['name']]);
    page_end();
}

// --- Netzwerk anlegen (mehrere) / bearbeiten ------------------------------------------------------

function net_validate(array $in, ?string $currentSlug = null): array
{
    $err = [];
    $warn = [];
    $name = trim(str_replace(["\r", "\n", "\0"], ' ', (string)($in['name'] ?? '')));
    $kind = (string)($in['kind'] ?? 'LAN');
    $row = [
        'name' => mb_substr($name, 0, 80),
        'kind' => isset(NET_KINDS[$kind]) ? $kind : 'LAN',
        'vlan_id' => trim((string)($in['vlan_id'] ?? '')),
        'cidr' => trim((string)($in['cidr'] ?? '')),
        'gateway' => trim((string)($in['gateway'] ?? '')),
        'dns' => trim(preg_replace('/[\s,;]+/', ', ', (string)($in['dns'] ?? '')) ?? '', ', '),
        'dhcp_range' => trim((string)($in['dhcp_range'] ?? '')),
        'domain' => trim((string)($in['domain'] ?? '')),
        'location' => mb_substr(trim((string)($in['location'] ?? '')), 0, 120),
        'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 2000),
    ];
    if ($row['name'] === '') {
        $err[] = t('Name fehlt');
    }
    if ($row['vlan_id'] !== '') {
        if (!ctype_digit($row['vlan_id']) || (int)$row['vlan_id'] < 1 || (int)$row['vlan_id'] > 4094) {
            $err[] = t('VLAN-ID muss zwischen 1 und 4094 liegen');
        }
        $row['vlan_id'] = (int)$row['vlan_id'];
    } else {
        $row['vlan_id'] = null;
        if ($row['kind'] === 'VLAN') {
            $err[] = t('Für ein VLAN ist die VLAN-ID Pflicht');
        }
    }
    if ($row['cidr'] !== '') {
        $c = cidr_parse($row['cidr']);
        if ($c === null) {
            $err[] = t('Netz (CIDR) ungültig – z. B. 10.0.20.0/24');
        } else {
            if (!$c['exact']) {
                $warn[] = t('{n}: CIDR auf Netzadresse {c} korrigiert', ['n' => $row['name'], 'c' => $c['text']]);
            }
            $row['cidr'] = $c['text'];
        }
    }
    if ($row['gateway'] !== '') {
        if (!ip_valid($row['gateway'])) {
            $err[] = t('Gateway ist keine gültige IP-Adresse');
        } elseif ($row['cidr'] !== '' && !ip_in_cidr($row['gateway'], $row['cidr'])) {
            $err[] = t('Gateway liegt nicht im Netz {c}', ['c' => $row['cidr']]);
        }
    }
    if ($row['dns'] !== '') {
        foreach (explode(', ', $row['dns']) as $d) {
            if (!ip_valid($d)) {
                $err[] = t('DNS-Server „{d}“ ist keine IP-Adresse', ['d' => $d]);
            }
        }
    }
    if ($row['dhcp_range'] !== '') {
        if (!preg_match('/^\s*(\S+)\s*(?:-|–|bis|to)\s*(\S+)\s*$/u', $row['dhcp_range'], $m) || !ip_valid($m[1]) || !ip_valid($m[2])) {
            $err[] = t('DHCP-Bereich als „von - bis“ angeben (z. B. 10.0.20.100 - 10.0.20.199)');
        } else {
            $row['dhcp_range'] = $m[1] . ' - ' . $m[2];
            if ($row['cidr'] !== '' && (!ip_in_cidr($m[1], $row['cidr']) || !ip_in_cidr($m[2], $row['cidr']))) {
                $err[] = t('DHCP-Bereich liegt nicht vollständig im Netz');
            }
        }
    }
    if ($row['domain'] !== '' && !hostname_valid($row['domain'])) {
        $err[] = t('Domäne ungültig');
    }
    $slug = trim((string)($in['slug'] ?? ''));
    $row['slug'] = $currentSlug ?? ($slug !== '' ? strtolower($slug) : net_slugify($row['name'] . ($row['vlan_id'] !== null && !str_contains($row['name'], (string)$row['vlan_id']) ? '-' . $row['vlan_id'] : '')));
    if (!net_valid_slug($row['slug'])) {
        $err[] = t('Kurzname ungültig (a–z, 0–9, Bindestrich)');
    } elseif ($currentSlug === null && is_file(net_file($row['slug']))) {
        $err[] = t('Kurzname „{s}“ ist schon vergeben', ['s' => $row['slug']]);
    }
    foreach (net_list() as $other) {
        if ($other['slug'] === $currentSlug) {
            continue;
        }
        if ($row['vlan_id'] !== null && $other['vlan_id'] !== null && (int)$other['vlan_id'] === $row['vlan_id']) {
            $warn[] = t('{n}: VLAN-ID {v} wird auch von „{o}“ benutzt', ['n' => $row['name'], 'v' => $row['vlan_id'], 'o' => $other['name']]);
        }
        if ($row['cidr'] !== '' && $other['cidr'] !== '' && cidr_overlap($row['cidr'], (string)$other['cidr'])) {
            $warn[] = t('{n}: {c} überschneidet sich mit „{o}“ ({oc})', ['n' => $row['name'], 'c' => $row['cidr'], 'o' => $other['name'], 'oc' => $other['cidr']]);
        }
    }
    return [$row, $err, $warn];
}

function cidr_overlap(string $a, string $b): bool
{
    $x = cidr_parse($a);
    $y = cidr_parse($b);
    if ($x === null || $y === null || $x['bits'] !== $y['bits']) {
        return false;
    }
    $m = cidr_mask(min($x['prefix'], $y['prefix']), intdiv($x['bits'], 8));
    return ($x['net'] & $m) === ($y['net'] & $m);
}

function page_net_new(array $rows = [], array $errors = []): void
{
    if ($rows === []) {
        $rows = [['name' => '', 'kind' => 'VLAN']];
    }
    page_start(t('Neues Netzwerk'), 'net_new', [[t('Netzwerke'), url()], [t('Neu'), null]]);
    heading(t('Neue Netzwerke anlegen'), th('Beliebig viele LANs/VLANs auf einmal. Jedes Netzwerk wird eine eigene SQL-Datei unter database/.'));
    echo '<form method="post" action="' . h(url(['a' => 'net_create_multi'])) . '" id="netMulti" autocomplete="off">' . csrf_field();
    echo '<div class="multi-list" id="multiList">';
    foreach ($rows as $i => $r) {
        echo net_multi_row($i, $r, $errors[$i] ?? []);
    }
    echo '</div><template id="multiTpl">' . net_multi_row('__I__', ['kind' => 'VLAN'], []) . '</template>';
    echo '<div class="form-actions"><button type="button" class="pill-btn secondary" id="multiAdd">' . icon('plus', 15) . th('Weitere Zeile') . '</button>'
        . '<button type="button" class="pill-btn secondary" id="multiSeries" title="' . th('z. B. VLAN 10–40 in Zehnerschritten') . '">' . icon('vlan', 15) . th('Serie erzeugen …') . '</button>'
        . '<span class="grow"></span><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Alle anlegen') . '</button>'
        . btn(t('Abbrechen'), url(), 'secondary') . '</div>';
    echo '</form>';
    echo '<dialog class="dialog" id="seriesDlg"><form method="dialog" class="dialog-body"><h2>' . th('VLAN-Serie erzeugen') . '</h2><div class="form-grid">'
        . f_text('s_prefix', t('Namensmuster'), 'VLAN {id}', ['hint' => th('{id} = VLAN-ID, {n} = laufende Nummer')])
        . f_text('s_start', t('Erste VLAN-ID'), '10', ['type' => 'number', 'min' => 1, 'max' => 4094])
        . f_text('s_count', t('Anzahl'), '4', ['type' => 'number', 'min' => 1, 'max' => 64])
        . f_text('s_step', t('Schrittweite'), '10', ['type' => 'number', 'min' => 1, 'max' => 1000])
        . f_text('s_cidr', t('Netz-Muster'), '10.0.{id}.0/24', ['hint' => th('leer lassen = ohne Netz')])
        . f_text('s_gw', t('Gateway-Muster'), '10.0.{id}.1')
        . '</div><div class="form-actions"><button class="pill-btn primary" value="ok">' . th('Zeilen erzeugen') . '</button><button class="pill-btn secondary" value="cancel">' . th('Abbrechen') . '</button></div></form></dialog>';
    page_end();
}

function net_multi_row(int|string $i, array $r, array $errors): string
{
    $v = static fn(string $k) => (string)($r[$k] ?? '');
    $n = 'net[' . h((string)$i) . ']';
    $kindOpts = '';
    foreach (NET_KINDS as $k => $label) {
        $kindOpts .= '<option value="' . h($k) . '"' . ($v('kind') === $k ? ' selected' : '') . '>' . th($label) . '</option>';
    }
    $err = $errors !== [] ? '<div class="row-errors">' . implode('', array_map(static fn($e) => '<span>' . h($e) . '</span>', $errors)) . '</div>' : '';
    return '<fieldset class="multi-row card' . ($errors !== [] ? ' has-error' : '') . '" data-index="' . h((string)$i) . '">'
        . '<div class="multi-head"><span class="multi-num" data-label="' . th('Netzwerk') . '"></span><button type="button" class="icon-btn multi-del" title="' . th('Zeile entfernen') . '" aria-label="' . th('Zeile entfernen') . '">' . icon('x', 15) . '</button></div>'
        . $err . '<div class="form-grid cols-4">'
        . '<label class="field span2"><span>' . th('Name') . ' *</span><input name="' . $n . '[name]" value="' . h($v('name')) . '" required maxlength="80" placeholder="' . th('z. B. Server-VLAN') . '"></label>'
        . '<label class="field"><span>' . th('Typ') . '</span><select name="' . $n . '[kind]">' . $kindOpts . '</select></label>'
        . '<label class="field"><span>VLAN-ID</span><input name="' . $n . '[vlan_id]" value="' . h($v('vlan_id')) . '" inputmode="numeric" maxlength="4" placeholder="1–4094"></label>'
        . '<label class="field"><span>' . th('Netz (CIDR)') . '</span><input name="' . $n . '[cidr]" value="' . h($v('cidr')) . '" class="mono" placeholder="10.0.20.0/24" spellcheck="false"></label>'
        . '<label class="field"><span>Gateway</span><input name="' . $n . '[gateway]" value="' . h($v('gateway')) . '" class="mono" placeholder="10.0.20.1" spellcheck="false"></label>'
        . '<label class="field"><span>' . th('DNS-Server') . '</span><input name="' . $n . '[dns]" value="' . h($v('dns')) . '" class="mono" placeholder="10.0.0.53, 10.0.0.54" spellcheck="false"></label>'
        . '<label class="field"><span>' . th('DHCP-Bereich') . '</span><input name="' . $n . '[dhcp_range]" value="' . h($v('dhcp_range')) . '" class="mono" placeholder="' . th('von - bis') . '" spellcheck="false"></label>'
        . '<label class="field"><span>' . th('Domäne') . '</span><input name="' . $n . '[domain]" value="' . h($v('domain')) . '" placeholder="firma.local" spellcheck="false"></label>'
        . '<label class="field"><span>' . th('Standort') . '</span><input name="' . $n . '[location]" value="' . h($v('location')) . '" maxlength="120"></label>'
        . '<label class="field span2"><span>' . th('Beschreibung') . '</span><input name="' . $n . '[description]" value="' . h($v('description')) . '" maxlength="2000"></label>'
        . '</div></fieldset>';
}

function action_net_create_multi(): void
{
    $rows = input()['net'] ?? [];
    if (!is_array($rows) || $rows === []) {
        flash(t('Keine Netzwerke angegeben.'), 'error');
        redirect(url(['p' => 'net_new']));
    }
    $rows = array_values(array_filter(array_slice($rows, 0, 100), static fn($r) => is_array($r) && trim(implode('', array_map('strval', array_diff_key($r, ['kind' => 1])))) !== ''));
    $valid = $errors = $warnings = $slugs = [];
    foreach ($rows as $i => $r) {
        [$row, $err, $warn] = net_validate($r);
        if (isset($slugs[$row['slug']])) {
            $err[] = t('Kurzname „{s}“ doppelt in dieser Liste', ['s' => $row['slug']]);
        }
        $slugs[$row['slug']] = true;
        if ($err !== []) {
            $errors[$i] = $err;
        }
        $warnings = [...$warnings, ...$warn];
        $valid[$i] = $row;
    }
    if ($rows === [] || $errors !== []) {
        flash($rows === [] ? t('Keine Netzwerke angegeben.') : t('{n} Zeile(n) enthalten Fehler – es wurde nichts angelegt.', ['n' => count($errors)]), 'error');
        audit('net_create', ['zeilen' => count($rows), 'fehler' => $errors], null, 'fail');
        page_net_new($rows, $errors);
        exit;
    }
    $created = [];
    foreach ($valid as $row) {
        net_create($row);
        $created[] = $row['slug'];
        audit('net_create', ['daten' => $row, 'datei' => 'database/net-' . $row['slug'] . '.sql'], null, 'ok', ['net' => $row['slug'], 'type' => 'network', 'label' => $row['name']]);
        try {
            foreach (masters_on_new_network($row['slug']) as $mname => $r) {
                if ($r !== null) {
                    flash(t('Master „{m}“ in „{n}“: {s}', ['m' => $mname, 'n' => $row['name'], 's' => master_state_done($r['state']) . ($r['ip'] ? ' ' . $r['ip'] : '')]),
                        in_array($r['state'], ['new', 'adopted', 'ok', 'updated'], true) ? 'ok' : 'warn');
                }
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'warn');
        }
    }
    foreach (array_unique($warnings) as $w) {
        flash(t('Hinweis: {w}', ['w' => $w]), 'warn');
    }
    flash(t('{n} Netzwerk(e) angelegt: {l}.', ['n' => count($created), 'l' => implode(', ', $created)]));
    redirect(count($created) === 1 ? url(['p' => 'net', 'n' => $created[0]]) : url());
}

function page_net_edit(): void
{
    $net = net_or_404(get_str('n', 60));
    $n = $net['network'];
    page_start(t('Netzwerk bearbeiten'), 'net:' . $net['slug'], [[t('Netzwerke'), url()], [$n['name'], url(['p' => 'net', 'n' => $net['slug']])], [t('Bearbeiten'), null]]);
    heading(t('Netzwerk bearbeiten'), '<span class="mono">database/net-' . h($net['slug']) . '.sql</span>');
    $kinds = array_map('t', NET_KINDS);
    echo '<form class="form-layout" method="post" action="' . h(url(['a' => 'net_save'])) . '" autocomplete="off">' . csrf_field() . '<input type="hidden" name="n" value="' . h($net['slug']) . '">';
    echo '<section class="card"><div class="form-grid cols-4">'
        . f_text('name', t('Name') . ' *', $n['name'], ['required' => true, 'maxlength' => 80, 'class' => 'span2'])
        . f_select('kind', t('Typ'), $kinds, $n['kind'])
        . f_text('vlan_id', 'VLAN-ID', $n['vlan_id'], ['inputmode' => 'numeric', 'maxlength' => 4])
        . f_text('cidr', t('Netz (CIDR)'), $n['cidr'], ['class' => 'mono-input'])
        . f_text('gateway', 'Gateway', $n['gateway'])
        . f_text('dns', t('DNS-Server'), $n['dns'])
        . f_text('dhcp_range', t('DHCP-Bereich'), $n['dhcp_range'])
        . f_text('domain', t('Domäne'), $n['domain'])
        . f_text('location', t('Standort'), $n['location'], ['maxlength' => 120])
        . f_textarea('description', t('Beschreibung'), $n['description'], 3, ['class' => 'span4'])
        . '</div><p class="hint">' . th('Der Kurzname (Dateiname) bleibt unverändert.') . '</p></section>';
    echo '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Speichern') . '</button>' . btn(t('Abbrechen'), url(['p' => 'net', 'n' => $net['slug']]), 'secondary') . '</div></form>';
    page_end();
}

function action_net_save(): void
{
    $slug = in_str('n', 60);
    net_or_404($slug);
    [$row, $err, $warn] = net_validate(input(), $slug);
    if ($err !== []) {
        foreach ($err as $e) {
            flash($e, 'error');
        }
        redirect(url(['p' => 'net_edit', 'n' => $slug]));
    }
    $diff = net_write($slug, static function (array &$net) use ($row): array {
        $old = $net['network'];
        $new = array_merge($old, $row, ['updated_at' => now_str(), 'updated_by' => current_actor()]);
        net_put($net, 'network', $new);
        return audit_diff($old, $new);
    });
    audit('net_update', ['aenderungen' => $diff], null, 'ok', ['net' => $slug, 'type' => 'network', 'label' => $row['name']]);
    foreach ($warn as $w) {
        flash(t('Hinweis: {w}', ['w' => $w]), 'warn');
    }
    flash($diff === [] ? t('Keine Änderungen.') : t('Netzwerk gespeichert ({n} Feld(er) geändert).', ['n' => count($diff)]));
    redirect(url(['p' => 'net', 'n' => $slug]));
}

function action_net_delete(): void
{
    $slug = in_str('n', 60);
    $net = net_or_404($slug);
    $archived = net_archive($slug);
    @unlink(CACHE_DIR . '/parsed/net-' . $slug . '.sql.json');
    @unlink(CACHE_DIR . '/hostidx/' . $slug . '.json');
    try {
        $refs = infra_forget_host($slug, null);
    } catch (StoreWriteBlocked $e) {
        $refs = ['fehler' => $e->getMessage()];
    }
    audit('net_delete', ['verweise_entfernt' => $refs, 'archiviert_als' => $archived, 'sha256' => $net['sha256'], 'hosts' => count($net['hosts']), 'dienste' => count($net['services']),
        'netzwerk' => $net['network']], null, 'ok', ['net' => $slug, 'type' => 'network', 'label' => $net['network']['name']]);
    flash(t('Netzwerk „{n}“ gelöscht (archiviert als {a}).', ['n' => $net['network']['name'], 'a' => $archived]));
    redirect(url());
}

/** Alle Dienste (mit Statusprüfung) aller Hosts eines Netzwerks prüfen. */
/** Prüft alle Dienste eines Netzes mit aktivem Status-Check; liefert [service_id => Ergebnis]. */
function svc_check_network(array $net): array
{
    $byHost = [];
    foreach ($net['services'] as $s) {
        if ($s['status_check'] === null || (int)$s['status_check'] === 1) {
            $byHost[(int)$s['host_id']][] = $s;
        }
    }
    $upd = [];
    foreach ($byHost as $hid => $svcs) {
        $h = $net['hosts'][$hid] ?? null;
        $t = $h ? host_target($h) : null;
        $ip = $t !== null ? net_resolve($t) : null;
        $tcp = array_values(array_unique(array_filter(array_map(static fn($s) => $s['proto'] === 'tcp' ? (int)$s['port'] : 0, $svcs))));
        $scan = $ip !== null && $tcp !== [] ? net_scan_ports($ip, $tcp, null, true) : [];
        foreach ($svcs as $s) {
            $r = ['ok' => null, 'state' => t('unbekannt'), 'ms' => null, 'info' => '', 'ts' => time()];
            if ($ip === null) {
                $r = ['ok' => false, 'state' => t('keine Adresse'), 'ms' => null, 'info' => '', 'ts' => time()];
            } elseif ($s['proto'] === 'tcp' && (int)$s['port'] > 0) {
                $sc = $scan[(int)$s['port']] ?? ['state' => 'error', 'ms' => null, 'banner' => ''];
                $r = ['ok' => $sc['state'] === 'open', 'state' => $sc['state'], 'ms' => $sc['ms'], 'info' => $sc['banner'], 'ts' => time()];
                if ($r['ok'] && !empty($s['url']) && setting('tools.http_check')) {
                    $hc = net_http_check_fallback((string)$s['url'], (string)($h['hostname'] ?? ''));
                    $r = array_merge($r, ['ok' => $hc['ok'], 'state' => $hc['state'], 'info' => $hc['info']]);
                }
            } elseif (!empty($s['url']) && setting('tools.http_check')) {
                $r = array_merge($r, net_http_check_fallback((string)$s['url'], (string)($h['hostname'] ?? '')));
            }
            $upd[(int)$s['id']] = $r;
        }
    }
    status_put($net['slug'], ['services' => $upd]);
    return $upd;
}

function action_svc_check_network(): void
{
    $net = net_or_404(in_str('n', 60));
    session_write_close();
    @set_time_limit(300);
    $t0 = microtime(true);
    $upd = svc_check_network($net);
    $cnt = ['up' => 0, 'down' => 0, 'unknown' => 0];
    $out = [];
    foreach ($upd as $id => $r) {
        $state = $r['ok'] === null ? 'unknown' : ($r['ok'] ? 'up' : 'down');
        $cnt[$state]++;
        $out[$id] = ['state' => $state, 'title' => $r['state'] . ($r['ms'] !== null ? ' · ' . $r['ms'] . ' ms' : '')];
    }
    audit('svccheck_network', ['dienste' => count($upd), 'verfuegbar' => $cnt['up'], 'nicht_verfuegbar' => $cnt['down'], 'unbekannt' => $cnt['unknown'],
        'dauer_ms' => (int)round((microtime(true) - $t0) * 1000)], null, $cnt['down'] ? 'warn' : 'ok', ['net' => $net['slug'], 'type' => 'network', 'label' => $net['network']['name']]);
    json_out(['results' => $out, 'counts' => $cnt]);
}

// --- Suche -----------------------------------------------------------------------------------------

function page_search(): void
{
    $q = get_str('q', 100);
    $needle = mb_strtolower($q);
    $macNeedle = preg_replace('/[^0-9a-f]/', '', $needle) ?? '';
    $hits = [];
    $limit = 500;
    $more = false;
    if ($q !== '') {
        foreach (array_keys(net_list()) as $slug) {
            try {
                $idx = net_host_index($slug);
            } catch (Throwable $e) {
                continue;
            }
            $net = ['slug' => $slug, 'network' => $idx['network']];
            foreach ($idx['hosts'] as $hst) {
                $macHay = preg_replace('/[^0-9a-f]/', '', mb_strtolower($hst['mac'])) ?? '';
                if (str_contains($hst['hay'], $needle) || (strlen($macNeedle) >= 4 && str_contains($macHay, $macNeedle))) {
                    if (count($hits) >= $limit) {
                        $more = true;
                        break 2;
                    }
                    $hits[] = ['net' => $net, 'host' => $hst];
                }
            }
            if (ip_valid($q) && ip_in_cidr($q, (string)$idx['network']['cidr'])) {
                $hits[] = ['net' => $net, 'host' => null];
            }
        }
    }
    page_start(t('Suche'), 'search', [[t('Suche'), null]]);
    heading(t('Suche'), $q === '' ? th('Suchbegriff oben links eingeben') : th('{n} Treffer für „{q}“', ['n' => count($hits) . ($more ? '+' : ''), 'q' => $q]));
    if ($more) {
        echo '<p class="note warn">' . th('Mehr als {n} Treffer – nur die ersten {n} werden angezeigt. Bitte den Suchbegriff genauer fassen.', ['n' => $limit]) . '</p>';
    }
    if ($hits !== []) {
        echo '<div class="table-wrap"><table class="data-table"><thead><tr><th>' . th('Netzwerk') . '</th><th>' . th('IP-Adresse') . '</th><th>' . th('Name') . '</th><th>' . th('Typ') . '</th><th>MAC</th></tr></thead><tbody>';
        foreach ($hits as $hit) {
            $n = $hit['net'];
            if ($hit['host'] === null) {
                $href = url(['p' => 'net', 'n' => $n['slug']]);
                echo '<tr data-href="' . h($href) . '"><td><a href="' . h($href) . '">' . h($n['network']['name']) . '</a></td><td class="mono" colspan="4">' . th('{q} liegt im Netz {c}', ['q' => $q, 'c' => $n['network']['cidr']]) . '</td></tr>';
                continue;
            }
            $hst = $hit['host'];
            $href = url(['p' => 'host', 'n' => $n['slug'], 'id' => $hst['id']]);
            echo '<tr data-href="' . h($href) . '"><td>' . h($n['network']['name']) . ($n['network']['vlan_id'] !== null ? ' <span class="vlan-tag">' . (int)$n['network']['vlan_id'] . '</span>' : '') . '</td>'
                . '<td class="mono"><a href="' . h($href) . '">' . h($hst['ip'] ?: '–') . '</a></td><td><div class="cell-title">' . host_icon_html($hst, 15, 'cell-icon') . h($hst['hostname']) . '</div></td><td>' . th((string)($hst['kind'] ?? '')) . '</td><td class="mono small">' . h($hst['mac'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } elseif ($q !== '') {
        echo '<div class="empty-state small">' . icon('search', 36) . '<p>' . th('Nichts gefunden.') . '</p></div>';
    }
    page_end();
}

// --- Export ------------------------------------------------------------------------------------

function action_net_download(): void
{
    $net = net_or_404(get_str('n', 60));
    audit('download_sql', ['sha256' => $net['sha256'], 'bytes' => $net['bytes']], null, 'ok', ['net' => $net['slug'], 'type' => 'network', 'label' => $net['network']['name']]);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="net-' . $net['slug'] . '.sql"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($net['file']);
}

function action_net_csv(): void
{
    $net = net_or_404(get_str('n', 60));
    $svc = [];
    foreach ($net['services'] as $s) {
        $svc[(int)$s['host_id']][] = $s['name'] . ' (' . $s['proto'] . '/' . $s['port'] . ')';
    }
    audit('export_csv', ['hosts' => count($net['hosts'])], null, 'ok', ['net' => $net['slug'], 'type' => 'network', 'label' => $net['network']['name']]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="net-' . $net['slug'] . '-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['id', 'hostname', 'ip', 'mac', 'hw_id', 'kind', 'os', 'vendor', 'model', 'location', 'owner', 'ssh_user', 'ssh_port', 'iperf_port', 'monitor', 'tags', 'description'];
    fputcsv($out, [...$cols, 'services', 'row_hash'], ';', '"', '');
    foreach ($net['hosts'] as $hst) {
        $line = [];
        foreach ($cols as $c) {
            $v = (string)($hst[$c] ?? '');
            $line[] = preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        }
        fputcsv($out, [...$line, implode(', ', $svc[(int)$hst['id']] ?? []), $hst['row_hash']], ';', '"', '');
    }
    fclose($out);
}
