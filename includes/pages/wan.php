<?php
/**
 * WAN-Verwaltung: Anschlüsse (Liste, Detail mit Leitungsweg, Bearbeiten), Prüfung per Ping,
 * Zugangsdaten (verschlüsselt, Anzeige nur für Portal-Admins mit Audit), Vorlagen
 * (eingebaut + eigene) anlegen und anwenden.
 */

declare(strict_types=1);

function wan_or_404(int $id): array
{
    $w = wan_get($id);
    if ($w === null) {
        render_error_page(t('Nicht gefunden'), t('WAN-Anschluss nicht gefunden.'), 404);
        exit;
    }
    return $w;
}

function wan_type_icon(string $type): string
{
    return ['lte' => 'mobile', 'sat' => 'radar', 'cable' => 'modem', 'ftth' => 'link', 'fixed' => 'rack', 'vdsl' => 'modem', 'adsl' => 'modem', 'sdsl' => 'modem'][$type] ?? 'globe';
}

function wan_mbit(?int $m): string
{
    if (!$m) {
        return '–';
    }
    return $m >= 1000 && $m % 1000 === 0 ? ($m / 1000) . ' Gbit/s' : $m . ' Mbit/s';
}

/** Status-Punkt aus dem Prüf-Cache. */
function wan_dot(array $w, array $status): string
{
    $s = $status[(string)$w['id']] ?? null;
    $cls = $s === null || $s['ok'] === null ? 'unknown' : ($s['ok'] ? 'up' : 'down');
    $tip = $s === null ? t('noch nicht geprüft') : (($s['ok'] ? t('online') : t('offline')) . ' · ' . $s['target'] . ($s['avg'] !== null ? ' · ' . $s['avg'] . ' ms' : '') . ' · ' . date('d.m. H:i', (int)$s['ts']));
    return '<i class="dot ' . $cls . '" title="' . h($tip) . '"></i>';
}

/** Leitungsweg: Router → (VLAN/Anmeldung) → Modem/ONT → Anbieter → Internet. */
function wan_path(array $w, ?array $router, array $status): string
{
    $s = $status[(string)$w['id']] ?? null;
    $state = $s === null || $s['ok'] === null ? 'unknown' : ($s['ok'] ? 'up' : 'down');
    $node = static fn(string $ic, string $title, string $sub, string $cls = '') => '<div class="wp-node ' . $cls . '">' . icon($ic, 22) . '<b>' . h($title) . '</b><small>' . $sub . '</small></div>';
    $link = static fn(string $label) => '<div class="wp-link"><span>' . $label . '</span></div>';
    $o = '<div class="wan-path st-' . $state . '">';
    $o .= $node('router', $router !== null ? (string)$router['hostname'] : t('Router'), h($router !== null ? (string)$router['ip'] : t('nicht verknüpft')) . ((string)$w['router_iface'] !== '' ? ' · ' . h((string)$w['router_iface']) : ''), $router === null ? 'muted' : '');
    $o .= $link(h(WAN_AUTH[$w['auth']] ?? $w['auth']) . ($w['wan_vlan'] ? ' · VLAN ' . (int)$w['wan_vlan'] : '') . ($w['mtu'] ? ' · MTU ' . (int)$w['mtu'] : ''));
    $o .= $node(wan_type_icon((string)$w['type']), (string)$w['modem'] !== '' ? (string)$w['modem'] : t('Modem / Abschluss'), h(WAN_TYPES[$w['type']] ?? $w['type']));
    $o .= $link(h(wan_mbit($w['down_mbit'] === null ? null : (int)$w['down_mbit'])) . ' ↓ · ' . h(wan_mbit($w['up_mbit'] === null ? null : (int)$w['up_mbit'])) . ' ↑');
    $o .= $node('cloud', (string)$w['provider'] !== '' ? (string)$w['provider'] : t('Anbieter'), h((string)$w['product']));
    $o .= $link(h(WAN_IPV4[$w['ipv4_mode']] ?? '') . ($w['ipv6_mode'] !== 'none' ? ' · IPv6' . ($w['ipv6_len'] ? ' /' . (int)$w['ipv6_len'] : '') : ''));
    $o .= $node('globe', 'Internet', '<span class="mono">' . h((string)$w['public_ip'] ?: ($w['ipv4_mode'] === 'cgnat' || $w['ipv4_mode'] === 'dslite' ? t('keine eigene IPv4') : t('dynamisch'))) . '</span>', 'inet');
    return $o . '</div>';
}

function wan_router(array $w): ?array
{
    if (!$w['router_net'] || !$w['router_host']) {
        return null;
    }
    try {
        return net_load((string)$w['router_net'])['hosts'][(int)$w['router_host']] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

// --- Liste --------------------------------------------------------------------------------------

function page_wans(): void
{
    $list = wan_links();
    $status = wan_status_all();
    page_start(t('WAN-Anschlüsse'), 'wans', [[t('WAN-Anschlüsse'), null]]);
    heading(t('WAN-Anschlüsse'), th('Internet-Anschlüsse je Standort: Zugang, Adressen, Router, Vertrag und Erreichbarkeit'),
        btn(t('Vorlagen'), url(['p' => 'wan_templates']), 'secondary', 'copy')
        . ($list !== [] && can('export') ? btn('CSV', url(['a' => 'wan_csv']), 'ghost', 'csv') : '')
        . ($list !== [] && can('ping') ? '<button type="button" class="pill-btn secondary" id="wanCheckAll">' . icon('pulse', 15) . th('Alle prüfen') . '</button>' : '')
        . (can('edit') ? btn(t('Neuer Anschluss'), url(['p' => 'wan_edit']), 'primary', 'plus') : ''));
    if ($list === []) {
        echo '<div class="empty-state">' . icon('globe', 40) . '<p>' . th('Noch keine WAN-Anschlüsse erfasst.') . '</p>'
            . '<p class="hint">' . th('Vorlagen für Telekom VDSL/Glasfaser (PPPoE, VLAN 7), Glasfaser IPoE, Kabel, LTE/5G-Backup, Business mit festen IPs und Starlink – oder eigene Vorlagen.') . '</p>'
            . (can('edit') ? btn(t('Ersten Anschluss anlegen'), url(['p' => 'wan_edit']), 'primary', 'plus') : '') . '</div>';
        page_end();
        return;
    }
    // Übersicht
    $down = $up = $cost = 0;
    $warn = 0;
    $sites = [];
    foreach ($list as $w) {
        if (in_array($w['status'], ['aktiv', 'backup'], true)) {
            $down += (int)$w['down_mbit'];
            $up += (int)$w['up_mbit'];
            $cost += (float)str_replace(',', '.', (string)$w['cost_month']);
        }
        $warn += wan_warnings($w) !== [] ? 1 : 0;
        $sites[(string)$w['site']] = true;
    }
    $online = count(array_filter($list, static fn($w) => ($status[(string)$w['id']]['ok'] ?? null) === true));
    $tile = static fn(string $label, string $value, string $sub, string $cls = '', string $id = '') => '<div class="stat-tile ' . $cls . '"><div class="stat-label">' . $label . '</div><div class="stat-value"' . ($id !== '' ? ' id="' . $id . '"' : '') . '>' . $value . '</div><div class="stat-sub">' . $sub . '</div></div>';
    echo '<div class="stat-grid">'
        . $tile(th('Anschlüsse'), (string)count($list), th('{n} Standort(e)', ['n' => count($sites)]))
        . $tile(th('Erreichbar'), $online . '<small> / ' . count($list) . '</small>', th('bei der letzten Prüfung'), $online === count($list) ? 'ok' : '', 'wanOnline')
        . $tile(th('Bandbreite gesamt'), h(wan_mbit($down)), '↑ ' . h(wan_mbit($up)))
        . $tile(th('Kosten / Monat'), h(number_format($cost, 2, ',', '.')) . '<small> €</small>', th('aktive Anschlüsse'))
        . $tile(th('Hinweise'), (string)$warn, th('Vertrag / Daten'), $warn ? 'warn' : '') . '</div>';

    echo '<div class="table-wrap"><table class="data-table" id="wanTable"><thead><tr><th class="col-dot"></th><th>' . th('Name') . '</th><th>' . th('Standort') . '</th><th>' . th('Anbieter / Produkt') . '</th>'
        . '<th>' . th('Zugang') . '</th><th>' . th('Bandbreite') . '</th><th>' . th('Öffentliche IP') . '</th><th>' . th('Router') . '</th><th>' . th('Prio') . '</th><th class="ping-col">Ping</th></tr></thead><tbody>';
    foreach ($list as $w) {
        $r = wan_router($w);
        $s = $status[(string)$w['id']] ?? null;
        $href = url(['p' => 'wan', 'id' => $w['id']]);
        $warnings = wan_warnings($w);
        echo '<tr data-href="' . h($href) . '" data-wan="' . (int)$w['id'] . '"><td class="col-dot">' . wan_dot($w, $status) . '</td>'
            . '<td><div class="cell-title">' . icon(wan_type_icon((string)$w['type']), 15, 'cell-icon') . '<a href="' . h($href) . '">' . h($w['name']) . '</a>'
            . ($w['status'] !== 'aktiv' ? ' <span class="badge ' . ($w['status'] === 'backup' ? 'state-unknown' : 'dim') . '">' . th(WAN_STATUS[$w['status']] ?? $w['status']) . '</span>' : '')
            . (store_row_ok('wan', 'wan_links', $w) ? '' : ' <span class="badge bad">' . th('Hash ungültig') . '</span>')
            . ($warnings ? ' <span class="badge ' . $warnings[0][0] . '" title="' . h(implode(' · ', array_column($warnings, 1))) . '">!</span>' : '') . '</div></td>'
            . '<td>' . h((string)$w['site']) . '</td><td>' . h((string)$w['provider']) . '<div class="cell-sub">' . h((string)$w['product']) . '</div></td>'
            . '<td>' . h(t(WAN_TYPES[$w['type']] ?? $w['type'])) . '<div class="cell-sub">' . h(t(WAN_AUTH[$w['auth']] ?? $w['auth'])) . ($w['wan_vlan'] ? ' · VLAN ' . (int)$w['wan_vlan'] : '') . '</div></td>'
            . '<td class="mono small">' . h(wan_mbit($w['down_mbit'] === null ? null : (int)$w['down_mbit'])) . '<div class="cell-sub">↑ ' . h(wan_mbit($w['up_mbit'] === null ? null : (int)$w['up_mbit'])) . '</div></td>'
            . '<td class="mono">' . h((string)$w['public_ip'] ?: '') . ((string)$w['public_ip'] === '' ? '<span class="dim small">' . h(t(WAN_IPV4[$w['ipv4_mode']] ?? '')) . '</span>' : '') . ((string)$w['public_net'] !== '' ? '<div class="cell-sub">' . h((string)$w['public_net']) . '</div>' : '') . '</td>'
            . '<td>' . ($r ? '<a href="' . h(url(['p' => 'host', 'n' => $w['router_net'], 'id' => $w['router_host']])) . '">' . h((string)$r['hostname']) . '</a>' : '<span class="dim">–</span>') . '</td>'
            . '<td class="mono">' . (int)($w['priority'] ?: 1) . '</td>'
            . '<td class="ping-cell" data-v="' . ($s && $s['ok'] && $s['avg'] !== null ? $s['avg'] : 99999) . '">' . ($s ? ($s['ok'] ? h($s['avg'] . ' ms') : '<span class="txt-bad">' . th('keine') . '</span>') . '<div class="cell-sub">' . h(date('d.m. H:i', (int)$s['ts'])) . '</div>' : '<span class="dim">–</span>') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    page_end();
}

// --- Detail -------------------------------------------------------------------------------------

function page_wan(): void
{
    $w = wan_or_404((int)($_GET['id'] ?? 0));
    $id = (int)$w['id'];
    $status = wan_status_all();
    $router = wan_router($w);
    $tpl = (string)$w['template'] !== '' ? wan_template((string)$w['template']) : null;
    $ok = store_row_ok('wan', 'wan_links', $w);
    page_start((string)$w['name'], 'wans', [[t('WAN-Anschlüsse'), url(['p' => 'wans'])], [(string)$w['name'], null]]);
    $s = $status[(string)$id] ?? null;
    echo '<section class="stage"><div class="stage-body"><div class="stage-cover">' . icon(wan_type_icon((string)$w['type']), 54) . '</div>'
        . '<div class="stage-info"><div class="stage-badges"><span class="live-badge"><i></i>' . h(t(WAN_TYPES[$w['type']] ?? $w['type'])) . '</span>'
        . badge(t(WAN_STATUS[$w['status']] ?? $w['status']), $w['status'] === 'aktiv' ? 'ok-soft' : ($w['status'] === 'backup' ? 'state-unknown' : 'dim'))
        . badge(t('Priorität {n}', ['n' => (int)($w['priority'] ?: 1)]), 'mono-badge')
        . ($tpl ? '<span class="badge dim">' . th('Vorlage: {t}', ['t' => $tpl['name']]) . '</span>' : '')
        . ($ok ? '' : '<span class="badge bad">' . th('Eintrag manipuliert') . '</span>') . '</div>'
        . '<div class="stage-station">' . h($w['name']) . '</div><div class="stage-title">' . h(trim($w['provider'] . ' · ' . $w['product'], ' ·')) . ((string)$w['site'] !== '' ? ' · ' . h((string)$w['site']) : '') . '</div></div>'
        . '<div class="stage-pills">' . (can('ping') ? '<button type="button" class="rec-pill" data-wan-check="' . $id . '">' . icon('pulse', 16) . '<span>' . th('Erreichbarkeit prüfen') . '</span></button>' : '')
        . ($router !== null && can('terminal') && terminal_available() ? '<a class="rec-pill secondary-pill" href="' . h(url(['p' => 'terminal', 'n' => $w['router_net'], 'id' => $w['router_host'], 'auto' => 1])) . '">' . icon('terminal', 16) . th('SSH zum Router') . '</a>' : '')
        . '</div></div></section>';

    $acts = '';
    if (can('edit')) {
        $acts .= btn(t('Bearbeiten'), url(['p' => 'wan_edit', 'id' => $id]), 'secondary', 'edit') . btn(t('Kopie anlegen'), url(['p' => 'wan_edit', 'copy' => $id]), 'ghost', 'copy');
    }
    if (can('delete')) {
        $acts .= post_button(t('Löschen'), ['a' => 'wan_delete'], 'danger', t('WAN-Anschluss „{s}“ löschen?', ['s' => $w['name']]), 'trash', ['id' => $id]);
    }
    echo '<div class="action-row">' . $acts . '</div>';

    foreach (wan_warnings($w) as [$cls, $msg]) {
        echo '<p class="note ' . ($cls === 'bad' ? 'bad' : 'warn') . '">' . icon('clock', 14) . ' ' . h($msg) . '</p>';
    }
    echo '<section class="card wp-card">' . wan_path($w, $router, $status)
        . '<div class="wp-status" id="wanStatus">' . ($s ? th('Letzte Prüfung {t}: {r}', ['t' => date('d.m.Y H:i', (int)$s['ts']), 'r' => ($s['ok'] ? t('erreichbar') . ($s['avg'] !== null ? ' · ' . $s['avg'] . ' ms' : '') : t('nicht erreichbar')) . ' · ' . $s['target']]) : th('Noch nicht geprüft.')) . '</div></section>';

    $u = wan_usable((string)$w['public_net']);
    $secretHtml = '<span class="dim">–</span>';
    if ((string)$w['secret'] !== '') {
        $secretHtml = '<span class="mono secret-mask" id="wanSecret">••••••••</span>' . (can('wan_secret') ? ' <button type="button" class="pill-btn ghost small" data-wan-secret="' . $id . '">' . icon('eye', 14) . th('Anzeigen') . '</button>' : ' <span class="dim small">' . th('nur Portal-Admins') . '</span>');
    }
    echo '<div class="grid-2 spaced"><section class="card"><h2 class="section-label">' . th('Zugang') . '</h2>' . kv_list([
        t('Zugangsart') => h(t(WAN_TYPES[$w['type']] ?? $w['type'])),
        t('Anmeldung') => h(t(WAN_AUTH[$w['auth']] ?? $w['auth'])),
        t('Benutzername') => (string)$w['username'] !== '' ? '<span class="mono">' . h((string)$w['username']) . '</span> ' . copy_btn((string)$w['username']) : '',
        t('Passwort') => $secretHtml,
        'WAN-VLAN' => $w['wan_vlan'] ? '<span class="vlan-tag">' . (int)$w['wan_vlan'] . '</span> ' . th('tagged am WAN-Port') : th('ohne VLAN'),
        'MTU' => $w['mtu'] ? (int)$w['mtu'] . ($w['auth'] === 'pppoe' ? ' <span class="dim small">· MSS ' . ((int)$w['mtu'] - 40) . '</span>' : '') : '',
        t('Modem / Abschluss') => h((string)$w['modem']),
        t('Router') => $router ? '<a href="' . h(url(['p' => 'host', 'n' => $w['router_net'], 'id' => $w['router_host']])) . '">' . h((string)$router['hostname']) . '</a> <span class="mono dim">' . h((string)$router['ip']) . '</span>' . ((string)$w['router_iface'] !== '' ? ' · ' . h((string)$w['router_iface']) : '') : '',
        t('Bandbreite') => h(wan_mbit($w['down_mbit'] === null ? null : (int)$w['down_mbit'])) . ' ↓ · ' . h(wan_mbit($w['up_mbit'] === null ? null : (int)$w['up_mbit'])) . ' ↑',
    ]) . '</section>';
    echo '<section class="card"><h2 class="section-label">' . th('Adressen') . '</h2>' . kv_list([
        'IPv4' => h(t(WAN_IPV4[$w['ipv4_mode']] ?? $w['ipv4_mode'])),
        t('Öffentliche IP') => (string)$w['public_ip'] !== '' ? '<span class="mono">' . h((string)$w['public_ip']) . '</span> ' . copy_btn((string)$w['public_ip']) : '',
        t('Festes Netz') => (string)$w['public_net'] !== '' ? '<span class="mono">' . h((string)$w['public_net']) . '</span>' . ($u ? '<div class="cell-sub">' . th('nutzbar {a} – {b} ({n} Adressen)', ['a' => $u['first'], 'b' => $u['last'], 'n' => $u['count']]) . '</div>' : '') : '',
        'Gateway' => '<span class="mono">' . h((string)$w['gateway']) . '</span>',
        'IPv6' => h(t(WAN_IPV6[$w['ipv6_mode']] ?? $w['ipv6_mode'])) . ($w['ipv6_len'] && $w['ipv6_mode'] !== 'none' ? ' · /' . (int)$w['ipv6_len'] : ''),
        t('IPv6-Präfix') => '<span class="mono">' . h((string)$w['ipv6_prefix']) . '</span>',
        'DNS' => '<span class="mono">' . h((string)$w['dns']) . '</span>',
        t('Prüfziel') => '<span class="mono">' . h((string)(wan_monitor_target($w) ?? '')) . '</span>' . ((string)$w['monitor_target'] === '' && wan_monitor_target($w) !== null ? ' <span class="dim small">(' . th('automatisch') . ')</span>' : ''),
    ]) . '</section></div>';

    $d = wan_days_left($w);
    echo '<div class="grid-2 spaced"><section class="card"><h2 class="section-label">' . th('Vertrag') . '</h2>' . kv_list([
        t('Kundennummer') => '<span class="mono">' . h((string)$w['customer_no']) . '</span>',
        t('Vertragsnummer') => '<span class="mono">' . h((string)$w['contract_no']) . '</span>',
        t('Leitungs-ID') => '<span class="mono">' . h((string)$w['line_id']) . '</span>',
        t('Störungs-Hotline') => (string)$w['hotline'] !== '' ? '<span class="mono">' . h((string)$w['hotline']) . '</span> ' . copy_btn((string)$w['hotline']) : '',
        'SLA' => h((string)$w['sla']),
        t('Laufzeit') => h(trim($w['contract_start'] . ' – ' . $w['contract_end'], ' –')) . ($d !== null ? ' <span class="badge ' . ($d < wan_notice_days((string)$w['notice']) ? 'bad' : 'dim') . '">' . th('noch {n} Tage', ['n' => $d]) . '</span>' : ''),
        t('Kündigungsfrist') => h((string)$w['notice']),
        t('Kosten / Monat') => (string)$w['cost_month'] !== '' ? h((string)$w['cost_month']) . ' €' : '',
        t('Notizen') => nl2br(h((string)$w['notes'])),
    ]) . '</section>';
    $sheet = wan_router_sheet($w);
    $lin = wan_linux_config($w);
    echo '<section class="card"><h2 class="section-label">' . th('Einstellungen am Router') . ' ' . copy_btn($sheet) . '</h2><pre class="console small">' . h($sheet) . '</pre>'
        . '<details class="sub"><summary>' . th('Linux (systemd-networkd / pppd)') . '</summary><pre class="console small">' . h($lin) . '</pre>' . copy_btn($lin) . '</details></section></div>';

    if (can('edit')) {
        $opts = [];
        foreach (wan_template_list() as $ref => $t2) {
            $opts[$ref] = ($t2['builtin'] ? '★ ' : '') . $t2['name'];
        }
        echo '<section class="card spaced"><h2 class="section-label">' . th('Vorlage anwenden') . '</h2>'
            . '<form method="post" action="' . h(url(['a' => 'wan_apply'])) . '" class="inline-vlan" data-confirm="' . th('Werte der Vorlage übernehmen? Adressen, Zugangsdaten und Vertrag bleiben erhalten.') . '">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
            . f_select('template', t('Vorlage'), $opts, (string)$w['template'])
            . '<div class="field check-field">' . f_check('only_empty', t('nur leere Felder füllen'), false) . '</div>'
            . '<div class="field"><span>&nbsp;</span><button class="pill-btn primary small" type="submit">' . icon('copy', 14) . th('Anwenden') . '</button></div></form>'
            . '<p class="hint">' . th('Übernimmt Anbieter, Produkt, Zugangsart, Anmeldung, IPv4/IPv6, VLAN, MTU, Bandbreite, Priorität, DNS, Modem und SLA.') . '</p></section>';
    }
    page_end();
}

// --- Anlegen / Bearbeiten -----------------------------------------------------------------------

function wan_blank(): array
{
    $w = array_fill_keys(array_keys(store_def('wan')['schema']['wan_links']), null);
    $w['status'] = 'aktiv';
    return $w;
}

function wan_apply_values(array $w, array $tpl, bool $onlyEmpty = false): array
{
    foreach (WAN_TPL_FIELDS as $k) {
        if (!array_key_exists($k, $tpl)) {
            continue;
        }
        if ($onlyEmpty && $w[$k] !== null && $w[$k] !== '') {
            continue;
        }
        $w[$k] = $tpl[$k] === '' ? ($w[$k] ?? null) : $tpl[$k];
    }
    return $w;
}

function page_wan_edit(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $copy = (int)($_GET['copy'] ?? 0);
    if ($id > 0) {
        $w = wan_or_404($id);
    } elseif ($copy > 0) {
        $w = wan_or_404($copy);
        $w['id'] = null;
        $w['name'] = t('{n} (Kopie)', ['n' => $w['name']]);
        $w['secret'] = null;
        $w['public_ip'] = $w['public_net'] = $w['line_id'] = $w['contract_no'] = null;
    } else {
        $w = wan_blank();
        $ref = get_str('template', 30) ?: 'o:tk-vdsl';
        $tpl = wan_template($ref);
        if ($tpl !== null) {
            $w = wan_apply_values($w, $tpl);
            $w['template'] = $ref;
        }
    }
    $title = $id > 0 ? t('WAN-Anschluss bearbeiten') : t('Neuer WAN-Anschluss');
    page_start($title, 'wans', [[t('WAN-Anschlüsse'), url(['p' => 'wans'])], [$id > 0 ? (string)$w['name'] : $title, null]]);
    heading($title, th('database/wan.sql – Passwort AES-256-GCM-verschlüsselt'));

    $tplData = [];
    $tplOpts = ['' => '— ' . t('ohne Vorlage') . ' —'];
    foreach (wan_template_list() as $ref => $t2) {
        $tplOpts[$ref] = ($t2['builtin'] ? '★ ' : '') . $t2['name'];
        $tplData[$ref] = array_intersect_key($t2, array_flip([...WAN_TPL_FIELDS, 'user_hint']));
    }
    $routerOpts = '<option value="">— ' . th('kein Eintrag') . ' —</option>';
    // Router-Auswahl: Router, Firewalls und Modems (bei vielen Einträgen), dazu der gewählte Eintrag
    $all = portal_host_options();
    $many = array_sum(array_map(static fn($g) => count($g['hosts']), $all)) > 300;
    foreach ($all as $slug => $g) {
        if ($many) {
            $g['hosts'] = array_filter($g['hosts'], static fn($hh) => in_array($hh['kind'], ['Router', 'Firewall', 'Sonstiges'], true)
                || ((string)$w['router_net'] === $slug && (int)$w['router_host'] === (int)$hh['id']));
            if ($g['hosts'] === []) {
                continue;
            }
        }
        $routerOpts .= '<optgroup label="' . h($g['name']) . '">';
        foreach ($g['hosts'] as $hid => $hh) {
            $routerOpts .= '<option value="' . h($slug . ':' . $hid) . '"' . ((string)$w['router_net'] === $slug && (int)$w['router_host'] === $hid ? ' selected' : '') . '>' . h($hh['hostname'] . ($hh['ip'] ? ' · ' . $hh['ip'] : '') . ($hh['kind'] ? ' · ' . $hh['kind'] : '')) . '</option>';
        }
        $routerOpts .= '</optgroup>';
    }
    $tr = static fn(array $a): array => array_map(static fn($v) => t($v), $a);
    $hint = (string)($tplData[(string)$w['template']]['user_hint'] ?? '');
    echo '<script type="application/json" id="wanTpls">' . json_encode($tplData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>';
    echo '<form method="post" action="' . h(url(['a' => 'wan_save'])) . '" class="form-layout" autocomplete="off" id="wanForm">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$w['id'] . '">'
        . '<section class="card"><h2 class="section-label">' . th('Anschluss') . '</h2><div class="form-grid cols-4">'
        . f_select('template', t('Vorlage'), $tplOpts, (string)$w['template'], ['class' => 'span2', 'id' => 'wanTemplate', 'hint' => th('Auswahl füllt die Felder der Vorlage aus')])
        . f_text('name', t('Name') . ' *', (string)$w['name'], ['required' => true, 'maxlength' => 60, 'placeholder' => 'WAN1 Zentrale'])
        . f_select('status', t('Status'), $tr(WAN_STATUS), (string)$w['status'])
        . f_text('site', t('Standort'), (string)$w['site'], ['maxlength' => 80, 'placeholder' => t('z. B. Zentrale')])
        . f_text('provider', t('Anbieter'), (string)$w['provider'], ['maxlength' => 60])
        . f_text('product', t('Produkt / Tarif'), (string)$w['product'], ['maxlength' => 80])
        . f_select('type', t('Zugangsart'), $tr(WAN_TYPES), (string)$w['type'])
        . f_text('down_mbit', t('Download (Mbit/s)'), (string)$w['down_mbit'], ['inputmode' => 'numeric', 'maxlength' => 6])
        . f_text('up_mbit', t('Upload (Mbit/s)'), (string)$w['up_mbit'], ['inputmode' => 'numeric', 'maxlength' => 6])
        . f_select('priority', t('Priorität'), ['1' => '1 · ' . t('primär'), '2' => '2 · ' . t('Failover'), '3' => '3', '4' => '4'], (string)($w['priority'] ?: 1))
        . f_text('modem', t('Modem / Abschluss'), (string)$w['modem'], ['maxlength' => 80])
        . '</div></section>'
        . '<section class="card"><h2 class="section-label">' . th('Zugang & Router') . '</h2><div class="form-grid cols-4">'
        . f_select('auth', t('Anmeldung'), $tr(WAN_AUTH), (string)$w['auth'])
        . f_text('wan_vlan', 'WAN-VLAN', (string)$w['wan_vlan'], ['inputmode' => 'numeric', 'maxlength' => 4, 'placeholder' => t('leer = ohne')])
        . f_text('mtu', 'MTU', (string)$w['mtu'], ['inputmode' => 'numeric', 'maxlength' => 5, 'placeholder' => '1500'])
        . f_text('router_iface', t('Router-Interface'), (string)$w['router_iface'], ['maxlength' => 20, 'class' => 'mono-input', 'placeholder' => 'igb1 / eth1'])
        . f_text('username', t('Benutzername (PPPoE)'), (string)$w['username'], ['maxlength' => 120, 'class' => 'span2 mono-input', 'hint' => '<span id="wanUserHint">' . h($hint) . '</span>'])
        . f_text('secret', t('Passwort'), '', ['type' => 'password', 'maxlength' => 128, 'autocomplete' => 'new-password', 'placeholder' => (string)$w['secret'] !== '' ? t('gespeichert – leer lassen = unverändert') : '', 'hint' => th('wird verschlüsselt gespeichert')])
        . ((string)$w['secret'] !== '' ? '<div class="field check-field">' . f_check('secret_clear', t('Passwort entfernen'), false) . '</div>' : '<div></div>')
        . '<label class="field span2"><span>' . th('Router (Eintrag im Portal)') . '</span><select name="router">' . $routerOpts . '</select></label>'
        . f_text('monitor_target', t('Prüfziel (Ping)'), (string)$w['monitor_target'], ['maxlength' => 253, 'class' => 'mono-input', 'placeholder' => t('leer = Gateway bzw. öffentliche IP')])
        . '</div></section>'
        . '<section class="card"><h2 class="section-label">' . th('Adressen') . '</h2><div class="form-grid cols-4">'
        . f_select('ipv4_mode', 'IPv4', $tr(WAN_IPV4), (string)$w['ipv4_mode'])
        . f_text('public_ip', t('Öffentliche IP'), (string)$w['public_ip'], ['maxlength' => 45, 'class' => 'mono-input', 'placeholder' => '203.0.113.10'])
        . f_text('public_net', t('Festes Netz (CIDR)'), (string)$w['public_net'], ['maxlength' => 49, 'class' => 'mono-input', 'placeholder' => '203.0.113.8/29'])
        . f_text('gateway', 'Gateway', (string)$w['gateway'], ['maxlength' => 45, 'class' => 'mono-input'])
        . f_select('ipv6_mode', 'IPv6', $tr(WAN_IPV6), (string)$w['ipv6_mode'])
        . f_text('ipv6_len', t('Präfixlänge'), (string)$w['ipv6_len'], ['inputmode' => 'numeric', 'maxlength' => 3, 'placeholder' => '56'])
        . f_text('ipv6_prefix', t('IPv6-Präfix'), (string)$w['ipv6_prefix'], ['maxlength' => 49, 'class' => 'mono-input', 'placeholder' => '2001:db8:1200::/56'])
        . f_text('dns', 'DNS', (string)$w['dns'], ['maxlength' => 200, 'class' => 'mono-input', 'placeholder' => t('leer = vom Anbieter')])
        . '</div></section>'
        . '<section class="card"><h2 class="section-label">' . th('Vertrag') . '</h2><div class="form-grid cols-4">'
        . f_text('customer_no', t('Kundennummer'), (string)$w['customer_no'], ['maxlength' => 40])
        . f_text('contract_no', t('Vertragsnummer'), (string)$w['contract_no'], ['maxlength' => 40])
        . f_text('line_id', t('Leitungs-ID'), (string)$w['line_id'], ['maxlength' => 60])
        . f_text('hotline', t('Störungs-Hotline'), (string)$w['hotline'], ['maxlength' => 40])
        . f_text('contract_start', t('Vertragsbeginn'), (string)$w['contract_start'], ['type' => 'date'])
        . f_text('contract_end', t('Vertragsende'), (string)$w['contract_end'], ['type' => 'date'])
        . f_text('notice', t('Kündigungsfrist'), (string)$w['notice'], ['maxlength' => 40, 'placeholder' => t('z. B. 3 Monate')])
        . f_text('cost_month', t('Kosten / Monat (€)'), (string)$w['cost_month'], ['maxlength' => 12, 'inputmode' => 'decimal'])
        . f_text('sla', 'SLA', (string)$w['sla'], ['maxlength' => 80, 'class' => 'span2'])
        . f_textarea('notes', t('Notizen'), (string)$w['notes'], 2, ['class' => 'span2'])
        . '</div></section>'
        . '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Speichern') . '</button>' . btn(t('Abbrechen'), $id > 0 ? url(['p' => 'wan', 'id' => $id]) : url(['p' => 'wans']), 'ghost') . '</div></form>';
    page_end();
}

/** Formularwerte prüfen → [row, errors]. */
function wan_from_input(): array
{
    $clean = static fn(string $k, int $n): string => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', in_str($k, $n)) ?? ''), 0, $n);
    $int = static function (string $k, int $min, int $max): ?int {
        $v = trim(in_str($k, 8));
        return $v === '' || !ctype_digit($v) ? null : max($min, min($max, (int)$v));
    };
    $err = [];
    $row = [
        'name' => $clean('name', 60), 'site' => $clean('site', 80), 'provider' => $clean('provider', 60), 'product' => $clean('product', 80), 'modem' => $clean('modem', 80),
        'status' => array_key_exists(in_str('status', 12), WAN_STATUS) ? in_str('status', 12) : 'aktiv',
        'type' => array_key_exists(in_str('type', 10), WAN_TYPES) ? in_str('type', 10) : 'other',
        'auth' => array_key_exists(in_str('auth', 10), WAN_AUTH) ? in_str('auth', 10) : 'dhcp',
        'ipv4_mode' => array_key_exists(in_str('ipv4_mode', 10), WAN_IPV4) ? in_str('ipv4_mode', 10) : 'dynamic',
        'ipv6_mode' => array_key_exists(in_str('ipv6_mode', 10), WAN_IPV6) ? in_str('ipv6_mode', 10) : 'none',
        'down_mbit' => $int('down_mbit', 0, 400000), 'up_mbit' => $int('up_mbit', 0, 400000), 'priority' => $int('priority', 1, 9) ?? 1,
        'wan_vlan' => $int('wan_vlan', 1, 4094), 'mtu' => $int('mtu', 576, 9216), 'ipv6_len' => $int('ipv6_len', 32, 128),
        'username' => $clean('username', 120), 'router_iface' => preg_replace('/[^A-Za-z0-9._:-]/', '', in_str('router_iface', 20)),
        'public_ip' => trim(in_str('public_ip', 45)), 'public_net' => trim(in_str('public_net', 49)), 'gateway' => trim(in_str('gateway', 45)),
        'ipv6_prefix' => trim(in_str('ipv6_prefix', 49)), 'dns' => '', 'monitor_target' => trim(in_str('monitor_target', 253)),
        'customer_no' => $clean('customer_no', 40), 'contract_no' => $clean('contract_no', 40), 'line_id' => $clean('line_id', 60), 'hotline' => $clean('hotline', 40),
        'sla' => $clean('sla', 80), 'notice' => $clean('notice', 40), 'notes' => mb_substr(in_str('notes', 2000), 0, 2000),
        'contract_start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', in_str('contract_start', 10)) ? in_str('contract_start', 10) : '',
        'contract_end' => preg_match('/^\d{4}-\d{2}-\d{2}$/', in_str('contract_end', 10)) ? in_str('contract_end', 10) : '',
        'cost_month' => preg_match('/^\d{1,7}([.,]\d{1,2})?$/', trim(in_str('cost_month', 12))) ? str_replace('.', ',', trim(in_str('cost_month', 12))) : '',
    ];
    $tpl = in_str('template', 30);
    $row['template'] = $tpl !== '' && wan_template($tpl) !== null ? $tpl : null;
    if ($row['name'] === '') {
        $err[] = t('Name fehlt.');
    }
    foreach (['public_ip' => t('Öffentliche IP'), 'gateway' => 'Gateway'] as $k => $l) {
        if ($row[$k] !== '' && !filter_var($row[$k], FILTER_VALIDATE_IP)) {
            $err[] = t('{f}: keine gültige IP-Adresse.', ['f' => $l]);
        }
    }
    foreach (['public_net' => t('Festes Netz'), 'ipv6_prefix' => t('IPv6-Präfix')] as $k => $l) {
        if ($row[$k] !== '') {
            $c = cidr_parse($row[$k]);
            if ($c === null) {
                $err[] = t('{f}: bitte in CIDR-Schreibweise (z. B. 203.0.113.8/29).', ['f' => $l]);
            } else {
                $row[$k] = $c['text'];
            }
        }
    }
    if ($row['public_ip'] !== '' && $row['public_net'] !== '' && !ip_in_cidr($row['public_ip'], $row['public_net'])) {
        $err[] = t('Öffentliche IP liegt nicht im festen Netz.');
    }
    if ($row['monitor_target'] !== '' && net_target($row['monitor_target']) === null) {
        $err[] = t('Prüfziel: IP-Adresse oder Hostname angeben.');
    }
    $dns = array_filter(preg_split('/[\s,;]+/', in_str('dns', 200)) ?: [], static fn($x) => filter_var($x, FILTER_VALIDATE_IP) !== false);
    $row['dns'] = implode(' ', $dns);
    $r = in_str('router', 80);
    $row['router_net'] = $row['router_host'] = null;
    if (preg_match('/^([a-z0-9-]+):(\d+)$/', $r, $m) && isset(net_list()[$m[1]]) && isset(net_load($m[1])['hosts'][(int)$m[2]])) {
        $row['router_net'] = $m[1];
        $row['router_host'] = (int)$m[2];
    }
    foreach ($row as $k => $v) {
        if ($v === '' && !in_array($k, ['name'], true)) {
            $row[$k] = null;
        }
    }
    return [$row, $err];
}

function action_wan_save(): void
{
    $id = in_int('id');
    $old = $id > 0 ? wan_or_404($id) : null;
    [$row, $err] = wan_from_input();
    if ($err !== []) {
        foreach ($err as $e) {
            flash($e, 'error');
        }
        redirect(url(['p' => 'wan_edit', 'id' => $id ?: null]));
    }
    $pw = (string)(input()['secret'] ?? '');
    $pw = substr(str_replace(["\0", "\r", "\n"], '', $pw), 0, 128);
    $row['secret'] = $old['secret'] ?? null;
    $secretChange = null;
    if (in_bool('secret_clear')) {
        $row['secret'] = null;
        $secretChange = 'entfernt';
    } elseif ($pw !== '') {
        $row['secret'] = secret_encrypt($pw);
        $secretChange = $old && $old['secret'] ? 'geändert' : 'gesetzt';
    }
    $wid = store_write('wan', static function (array &$st) use ($row, $old): int {
        $u = current_actor();
        return $old ? store_put('wan', $st, 'wan_links', array_merge($old, $row, ['updated_at' => now_str(), 'updated_by' => $u]))
            : store_put('wan', $st, 'wan_links', $row + ['created_at' => now_str(), 'created_by' => $u, 'updated_at' => now_str(), 'updated_by' => $u]);
    });
    $log = $old ? ['aenderungen' => audit_diff($old, array_merge($old, $row))] : ['daten' => $row];
    if ($secretChange !== null) {
        $log['passwort'] = $secretChange;
    }
    audit($old ? 'wan_update' : 'wan_create', $log, null, 'ok', ['type' => 'wan', 'id' => $wid, 'label' => $row['name']]);
    flash($old ? t('WAN-Anschluss gespeichert.') : t('WAN-Anschluss „{s}“ angelegt.', ['s' => $row['name']]));
    redirect(url(['p' => 'wan', 'id' => $wid]));
}

function action_wan_delete(): void
{
    $w = wan_or_404(in_int('id'));
    store_write('wan', static function (array &$st) use ($w): void {
        unset($st['wan_links'][(int)$w['id']]);
    });
    audit('wan_delete', ['datensatz' => $w], null, 'ok', ['type' => 'wan', 'id' => $w['id'], 'label' => $w['name']]);
    flash(t('WAN-Anschluss „{s}“ gelöscht.', ['s' => $w['name']]));
    redirect(url(['p' => 'wans']));
}

function action_wan_apply(): void
{
    $w = wan_or_404(in_int('id'));
    $ref = in_str('template', 30);
    $tpl = wan_template($ref);
    if ($tpl === null) {
        flash(t('Vorlage nicht gefunden.'), 'error');
        redirect(url(['p' => 'wan', 'id' => $w['id']]));
    }
    $new = wan_apply_values($w, $tpl, in_bool('only_empty'));
    $new['template'] = $ref;
    store_write('wan', static function (array &$st) use ($new): void {
        store_put('wan', $st, 'wan_links', array_merge($new, ['updated_at' => now_str(), 'updated_by' => current_actor()]));
    });
    audit('wan_apply', ['vorlage' => $tpl['name'], 'aenderungen' => audit_diff($w, $new)], null, 'ok', ['type' => 'wan', 'id' => $w['id'], 'label' => $w['name']]);
    flash(t('Vorlage „{t}“ angewendet.', ['t' => $tpl['name']]));
    redirect(url(['p' => 'wan', 'id' => $w['id']]));
}

function action_wan_check(): void
{
    $ids = in_int('id') > 0 ? [in_int('id')] : array_map(static fn($w) => (int)$w['id'], wan_links());
    session_write_close();
    $out = [];
    foreach (array_slice($ids, 0, 50) as $id) {
        $w = wan_get($id);
        if ($w === null) {
            continue;
        }
        $r = wan_check($w);
        $out[$id] = ['ok' => $r['ok'], 'avg' => $r['avg'], 'loss' => $r['loss'], 'target' => $r['target'], 'error' => $r['error'] ?? null,
            'text' => $r['ok'] === null ? ($r['error'] ?? '') : t('Letzte Prüfung {t}: {r}', ['t' => date('d.m.Y H:i'), 'r' => ($r['ok'] ? t('erreichbar') . ($r['avg'] !== null ? ' · ' . $r['avg'] . ' ms' : '') : t('nicht erreichbar')) . ' · ' . $r['target']])];
        audit('wan_check', ['anschluss' => $w['name'], 'ziel' => $r['target'], 'ok' => $r['ok'], 'avg_ms' => $r['avg'], 'verlust_pct' => $r['loss']], null, $r['ok'] ? 'ok' : 'warn', ['type' => 'wan', 'id' => $id, 'label' => $w['name']]);
    }
    json_out(['results' => $out]);
}

function action_wan_secret(): void
{
    $w = wan_or_404(in_int('id'));
    $pw = secret_decrypt((string)$w['secret']);
    audit('wan_secret', ['anschluss' => $w['name'], 'benutzer' => $w['username'], 'ergebnis' => $pw === null ? 'nicht entschlüsselbar' : 'angezeigt'], null, $pw === null ? 'error' : 'ok', ['type' => 'wan', 'id' => $w['id'], 'label' => $w['name']]);
    if ($pw === null) {
        json_out(['error' => t('Passwort kann nicht entschlüsselt werden (Schlüssel geändert?).')], 409);
    }
    json_out(['secret' => $pw]);
}

function action_wan_csv(): void
{
    $list = wan_links();
    audit('export_csv', ['wan' => count($list)], null, 'ok', ['type' => 'wan', 'label' => 'wan.sql']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="wan-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['id', 'name', 'site', 'status', 'provider', 'product', 'type', 'auth', 'ipv4_mode', 'public_ip', 'public_net', 'gateway', 'ipv6_mode', 'ipv6_prefix', 'ipv6_len',
        'dns', 'wan_vlan', 'mtu', 'down_mbit', 'up_mbit', 'priority', 'username', 'router_net', 'router_host', 'router_iface', 'modem', 'monitor_target',
        'customer_no', 'contract_no', 'line_id', 'hotline', 'sla', 'contract_start', 'contract_end', 'notice', 'cost_month', 'notes'];
    fputcsv($out, [...$cols, 'row_hash'], ';', '"', '');
    foreach ($list as $w) {
        $line = [];
        foreach ($cols as $c) {
            $v = (string)($w[$c] ?? '');
            $line[] = preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        }
        fputcsv($out, [...$line, $w['row_hash']], ';', '"', '');
    }
    fclose($out);
}

// --- Vorlagen -----------------------------------------------------------------------------------

function wan_tpl_facts(array $t2): string
{
    return '<div class="tpl-facts">'
        . '<span>' . icon(wan_type_icon((string)$t2['type']), 13) . h(t(WAN_TYPES[$t2['type']] ?? $t2['type'])) . '</span>'
        . '<span>' . h(t(WAN_AUTH[$t2['auth']] ?? $t2['auth'])) . '</span>'
        . ($t2['wan_vlan'] ? '<span>VLAN ' . (int)$t2['wan_vlan'] . '</span>' : '')
        . ($t2['mtu'] ? '<span>MTU ' . (int)$t2['mtu'] . '</span>' : '')
        . '<span>IPv4 ' . h(t(WAN_IPV4[$t2['ipv4_mode']] ?? '')) . '</span>'
        . ($t2['ipv6_mode'] !== 'none' ? '<span>IPv6 ' . h(['pd' => 'PD', 'slaac' => 'SLAAC', 'static' => t('statisch')][$t2['ipv6_mode']] ?? $t2['ipv6_mode']) . ($t2['ipv6_len'] ? ' /' . (int)$t2['ipv6_len'] : '') . '</span>' : '')
        . '<span>' . h(wan_mbit($t2['down_mbit'])) . ' / ' . h(wan_mbit($t2['up_mbit'])) . '</span>'
        . ((int)$t2['priority'] > 1 ? '<span>' . th('Priorität {n}', ['n' => (int)$t2['priority']]) . '</span>' : '')
        . '</div>';
}

function page_wan_templates(): void
{
    $list = wan_template_list();
    $used = [];
    foreach (wan_links() as $w) {
        $used[(string)$w['template']][] = $w['name'];
    }
    page_start(t('WAN-Vorlagen'), 'wans', [[t('WAN-Anschlüsse'), url(['p' => 'wans'])], [t('Vorlagen'), null]]);
    heading(t('WAN-Vorlagen'), th('Eingebaute Vorlagen (nicht änderbar) und eigene Vorlagen'), can('edit') ? btn(t('Neue Vorlage'), url(['p' => 'wan_template_edit']), 'primary', 'plus') : '');
    foreach ([true, false] as $builtin) {
        echo '<h2 class="section-label spaced">' . ($builtin ? th('Eingebaute Vorlagen') : th('Eigene Vorlagen')) . '</h2>';
        $items = array_filter($list, static fn($t) => $t['builtin'] === $builtin);
        if ($items === []) {
            echo '<p class="dim">' . th('Noch keine eigenen Vorlagen. Tipp: eine eingebaute Vorlage kopieren und anpassen.') . '</p>';
        }
        echo '<div class="tpl-grid">';
        foreach ($items as $ref => $t2) {
            echo '<section class="card tpl-card"><div class="tpl-head">' . icon(wan_type_icon((string)$t2['type']), 26, 'tpl-ic') . '<div><strong>' . h($t2['name']) . '</strong>'
                . ($builtin ? ' <span class="badge state-unknown">' . th('eingebaut') . '</span>' : '') . '<div class="cell-sub">' . h(trim($t2['provider'] . ' · ' . $t2['product'], ' ·')) . '</div></div></div>'
                . wan_tpl_facts($t2) . '<p class="small dim">' . h($t2['description']) . '</p>'
                . '<div class="tpl-foot"><span class="dim small">' . (isset($used[$ref]) ? th('benutzt von {l}', ['l' => implode(', ', $used[$ref])]) : '') . '</span>'
                . '<span class="tpl-acts">' . btn(t('Ansehen'), url(['p' => 'wan_template', 'ref' => $ref]), 'ghost small')
                . (can('edit') ? btn(t('Kopie als eigene Vorlage'), url(['p' => 'wan_template_edit', 'copy' => $ref]), 'secondary small', 'copy')
                    . (!$builtin ? btn(t('Bearbeiten'), url(['p' => 'wan_template_edit', 'ref' => $ref]), 'secondary small', 'edit') : '')
                    . btn(t('Anschluss mit dieser Vorlage'), url(['p' => 'wan_edit', 'template' => $ref]), 'ghost small', 'plus') : '')
                . '</span></div></section>';
        }
        echo '</div>';
    }
    page_end();
}

function page_wan_template(): void
{
    $t2 = wan_template(get_str('ref', 30));
    if ($t2 === null) {
        render_error_page(t('Nicht gefunden'), t('Vorlage nicht gefunden.'), 404);
        exit;
    }
    page_start($t2['name'], 'wans', [[t('WAN-Anschlüsse'), url(['p' => 'wans'])], [t('Vorlagen'), url(['p' => 'wan_templates'])], [$t2['name'], null]]);
    heading($t2['name'], h($t2['description']), can('edit') ? btn(t('Kopie als eigene Vorlage'), url(['p' => 'wan_template_edit', 'copy' => $t2['ref']]), 'secondary', 'copy')
        . (!$t2['builtin'] ? btn(t('Bearbeiten'), url(['p' => 'wan_template_edit', 'ref' => $t2['ref']]), 'secondary', 'edit') : '')
        . btn(t('Anschluss mit dieser Vorlage'), url(['p' => 'wan_edit', 'template' => $t2['ref']]), 'primary', 'plus') : '');
    $demo = wan_apply_values(wan_blank(), $t2) + ['id' => 0];
    $demo['name'] = $t2['name'];
    echo '<section class="card wp-card">' . wan_path($demo, null, []) . '</section>';
    echo '<div class="grid-2"><section class="card">' . kv_list([
        t('Anbieter') => h($t2['provider']), t('Produkt / Tarif') => h($t2['product']), t('Zugangsart') => h(t(WAN_TYPES[$t2['type']] ?? $t2['type'])),
        t('Anmeldung') => h(t(WAN_AUTH[$t2['auth']] ?? $t2['auth'])), t('Zugangsdaten') => h($t2['user_hint']), 'WAN-VLAN' => $t2['wan_vlan'] ? (string)(int)$t2['wan_vlan'] : th('ohne VLAN'),
        'MTU' => (string)$t2['mtu'], t('Modem / Abschluss') => h($t2['modem']),
    ]) . '</section><section class="card">' . kv_list([
        'IPv4' => h(t(WAN_IPV4[$t2['ipv4_mode']] ?? '')), 'IPv6' => h(t(WAN_IPV6[$t2['ipv6_mode']] ?? '')) . ($t2['ipv6_len'] ? ' · /' . (int)$t2['ipv6_len'] : ''),
        'DNS' => h($t2['dns'] ?: t('vom Anbieter')), t('Bandbreite') => h(wan_mbit($t2['down_mbit']) . ' ↓ · ' . wan_mbit($t2['up_mbit']) . ' ↑'),
        t('Priorität') => (string)(int)$t2['priority'], 'SLA' => h($t2['sla']),
    ]) . '</section></div>';
    page_end();
}

function page_wan_template_edit(): void
{
    $ref = get_str('ref', 30);
    $copy = get_str('copy', 30);
    $t2 = $ref !== '' ? wan_template($ref) : ($copy !== '' ? wan_template($copy) : null);
    if ($ref !== '' && ($t2 === null || $t2['builtin'])) {
        render_error_page(t('Nicht änderbar'), t('Eingebaute Vorlagen können nicht geändert werden – bitte eine Kopie anlegen.'), 403);
        exit;
    }
    $isNew = $ref === '';
    $t2 ??= ['name' => '', 'provider' => '', 'product' => '', 'type' => 'ftth', 'auth' => 'pppoe', 'ipv4_mode' => 'dynamic', 'ipv6_mode' => 'pd', 'ipv6_len' => 56,
        'wan_vlan' => null, 'mtu' => 1492, 'down_mbit' => null, 'up_mbit' => null, 'priority' => 1, 'dns' => '', 'user_hint' => '', 'modem' => '', 'sla' => '', 'description' => '', 'base' => ''];
    if ($isNew && $copy !== '') {
        $t2['name'] = t('{n} – eigene Vorlage', ['n' => $t2['name']]);
    }
    $title = $isNew ? t('Neue WAN-Vorlage') : t('Vorlage bearbeiten');
    $tr = static fn(array $a): array => array_map(static fn($v) => t($v), $a);
    page_start($title, 'wans', [[t('WAN-Anschlüsse'), url(['p' => 'wans'])], [t('Vorlagen'), url(['p' => 'wan_templates'])], [$title, null]]);
    heading($title, $copy !== '' ? th('Kopie von {r}', ['r' => $copy]) : '');
    echo '<form method="post" action="' . h(url(['a' => 'wan_tpl_save'])) . '" class="form-layout" autocomplete="off">' . csrf_field()
        . '<input type="hidden" name="ref" value="' . h($isNew ? '' : $ref) . '"><input type="hidden" name="base" value="' . h((string)($t2['base'] ?? '')) . '">'
        . '<section class="card"><div class="form-grid cols-4">'
        . f_text('name', t('Name der Vorlage') . ' *', (string)$t2['name'], ['required' => true, 'maxlength' => 80, 'class' => 'span2'])
        . f_text('provider', t('Anbieter'), (string)$t2['provider'], ['maxlength' => 60]) . f_text('product', t('Produkt / Tarif'), (string)$t2['product'], ['maxlength' => 80])
        . f_select('type', t('Zugangsart'), $tr(WAN_TYPES), (string)$t2['type']) . f_select('auth', t('Anmeldung'), $tr(WAN_AUTH), (string)$t2['auth'])
        . f_text('wan_vlan', 'WAN-VLAN', (string)$t2['wan_vlan'], ['inputmode' => 'numeric', 'maxlength' => 4]) . f_text('mtu', 'MTU', (string)$t2['mtu'], ['inputmode' => 'numeric', 'maxlength' => 5])
        . f_select('ipv4_mode', 'IPv4', $tr(WAN_IPV4), (string)$t2['ipv4_mode']) . f_select('ipv6_mode', 'IPv6', $tr(WAN_IPV6), (string)$t2['ipv6_mode'])
        . f_text('ipv6_len', t('Präfixlänge'), (string)$t2['ipv6_len'], ['inputmode' => 'numeric', 'maxlength' => 3]) . f_text('dns', 'DNS', (string)$t2['dns'], ['maxlength' => 200, 'class' => 'mono-input'])
        . f_text('down_mbit', t('Download (Mbit/s)'), (string)$t2['down_mbit'], ['inputmode' => 'numeric', 'maxlength' => 6]) . f_text('up_mbit', t('Upload (Mbit/s)'), (string)$t2['up_mbit'], ['inputmode' => 'numeric', 'maxlength' => 6])
        . f_select('priority', t('Priorität'), ['1' => '1 · ' . t('primär'), '2' => '2 · ' . t('Failover'), '3' => '3', '4' => '4'], (string)($t2['priority'] ?: 1))
        . f_text('modem', t('Modem / Abschluss'), (string)$t2['modem'], ['maxlength' => 80])
        . f_text('user_hint', t('Hinweis Zugangsdaten'), (string)$t2['user_hint'], ['maxlength' => 160, 'class' => 'span2'])
        . f_text('sla', 'SLA', (string)$t2['sla'], ['maxlength' => 80, 'class' => 'span2'])
        . f_textarea('description', t('Beschreibung'), (string)$t2['description'], 3, ['class' => 'span2'])
        . '</div></section><div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Vorlage speichern') . '</button>' . btn(t('Abbrechen'), url(['p' => 'wan_templates']), 'ghost') . '</div></form>';
    if (!$isNew && can('delete')) {
        echo '<section class="card danger-zone spaced-top">' . post_button(t('Vorlage löschen'), ['a' => 'wan_tpl_delete'], 'danger', t('Vorlage löschen?'), 'trash', ['ref' => $ref]) . '</section>';
    }
    page_end();
}

function action_wan_tpl_save(): void
{
    $ref = in_str('ref', 30);
    $old = $ref !== '' ? wan_template($ref) : null;
    if ($ref !== '' && ($old === null || $old['builtin'])) {
        flash(t('Eingebaute Vorlagen können nicht geändert werden – bitte eine Kopie anlegen.'), 'error');
        redirect(url(['p' => 'wan_templates']));
    }
    [$r] = wan_from_input();
    $row = ['name' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', in_str('name', 80)) ?? ''), 0, 80), 'user_hint' => mb_substr(in_str('user_hint', 160), 0, 160),
        'description' => mb_substr(in_str('description', 1000), 0, 1000), 'base' => preg_replace('/[^a-z0-9-]/', '', in_str('base', 30))];
    foreach (WAN_TPL_FIELDS as $k) {
        $row[$k] = $r[$k];
    }
    if ($row['name'] === '') {
        flash(t('Name fehlt.'), 'error');
        redirect(url(['p' => 'wan_template_edit', 'ref' => $ref ?: null]));
    }
    $id = store_write('wan', static function (array &$st) use ($row, $old): int {
        $u = current_actor();
        return $old ? store_put('wan', $st, 'wan_templates', array_merge($old['row'], $row, ['updated_at' => now_str(), 'updated_by' => $u]))
            : store_put('wan', $st, 'wan_templates', $row + ['created_at' => now_str(), 'created_by' => $u, 'updated_at' => now_str(), 'updated_by' => $u]);
    });
    audit('wan_tpl_save', ['vorlage' => $row['name'], 'aktion' => $old ? 'geändert' : 'angelegt'], null, 'ok', ['type' => 'wan_template', 'id' => $id, 'label' => $row['name']]);
    flash(t('Vorlage „{n}“ gespeichert.', ['n' => $row['name']]));
    redirect(url(['p' => 'wan_template', 'ref' => 'c:' . $id]));
}

function action_wan_tpl_delete(): void
{
    $ref = in_str('ref', 30);
    $t2 = wan_template($ref);
    if ($t2 === null || $t2['builtin']) {
        flash(t('Eingebaute Vorlagen können nicht gelöscht werden.'), 'error');
        redirect(url(['p' => 'wan_templates']));
    }
    foreach (wan_links() as $w) {
        if ((string)$w['template'] === $ref) {
            flash(t('Vorlage wird noch von „{s}“ benutzt.', ['s' => $w['name']]), 'error');
            redirect(url(['p' => 'wan_templates']));
        }
    }
    store_write('wan', static function (array &$st) use ($t2): void {
        unset($st['wan_templates'][$t2['id']]);
    });
    audit('wan_tpl_delete', ['vorlage' => $t2['name']], null, 'ok', ['type' => 'wan_template', 'id' => $t2['id'], 'label' => $t2['name']]);
    flash(t('Vorlage „{n}“ gelöscht.', ['n' => $t2['name']]));
    redirect(url(['p' => 'wan_templates']));
}
