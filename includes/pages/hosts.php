<?php
/**
 * Host-Seite: Stammdaten (inkl. MAC/Hardware-Kennung, Icon), Ping, Dienste
 * (Statusfarben, Statusprüfung je Dienst), iperf3, SSH, Verlauf.
 */

declare(strict_types=1);

function host_or_404(array $net, int $id): array
{
    $h = $net['hosts'][$id] ?? null;
    if ($h === null) {
        throw new NetNotFound(t('Diesen Host gibt es in „{n}“ nicht (mehr).', ['n' => $net['network']['name']]));
    }
    return $h;
}

function host_ctx(array $net, array $h): array
{
    return ['net' => $net['slug'], 'type' => 'host', 'id' => $h['id'], 'label' => trim($h['hostname'] . ' ' . $h['ip'])];
}

function mac_html(?string $mac): string
{
    if ($mac === null || $mac === '') {
        return '';
    }
    return $mac === MAC_NOT_FOUND ? '<span class="badge warn">not found</span> <span class="dim small">' . th('manuell eintragen') . '</span>' : '<span class="mono">' . h($mac) . '</span>';
}

function page_host(): void
{
    $net = net_or_404(get_str('n', 60));
    $h = host_or_404($net, (int)($_GET['id'] ?? 0));
    $n = $net['network'];
    $st = status_get($net['slug']);
    $s = $st['hosts'][(string)$h['id']] ?? null;
    $services = array_filter($net['services'], static fn($x) => (int)$x['host_id'] === (int)$h['id']);
    usort($services, static fn($a, $b) => [(int)$a['port'], $a['name']] <=> [(int)$b['port'], $b['name']]);
    $target = host_target($h);
    $rowOk = sqlfile_row_valid($net['slug'], 'hosts', $h);
    $base = ['n' => $net['slug'], 'id' => $h['id']];
    $state = host_state($h, $s);

    page_start($h['hostname'], 'net:' . $net['slug'], [[t('Netzwerke'), url()], [$n['name'], url(['p' => 'net', 'n' => $net['slug']])], [$h['hostname'], null]]);
    $stateBadge = '<span class="badge state-' . $state . '" id="hostState">' . h(match ($state) { 'up' => t('online'), 'down' => t('offline'), 'nocheck' => t('ohne Statusprüfung'), default => t('noch nicht gepingt') } . ($state === 'up' && isset($s['avg']) ? ' · ' . $s['avg'] . ' ms' : '')) . '</span>';
    $badges = '<span class="live-badge"><i></i>' . th($h['kind'] ?: 'Host') . '</span>' . $stateBadge
        . ($n['vlan_id'] !== null ? badge('VLAN ' . $n['vlan_id'], 'mono-badge') : '')
        . ($rowOk ? '' : '<span class="badge bad">' . icon('shield-x', 12) . th('Eintrag manipuliert') . '</span>');
    echo '<section class="stage" data-net="' . h($net['slug']) . '" data-host="' . (int)$h['id'] . '"><div class="stage-body"><div class="stage-cover">' . host_icon_html($h, 54) . '</div>'
        . '<div class="stage-info"><div class="stage-badges">' . $badges . '</div><div class="stage-station">' . h($h['hostname']) . '</div>'
        . '<div class="stage-title"><span class="mono">' . h($h['ip'] ?: t('keine IP')) . '</span>' . ($h['mac'] && $h['mac'] !== MAC_NOT_FOUND ? ' · <span class="mono">' . h($h['mac']) . '</span>' : '') . ($h['os'] ? ' · ' . h($h['os']) : '') . '</div></div>'
        . ($target !== null && (can('ping') || can('terminal')) ? '<div class="stage-pills">' . (can('terminal') && terminal_available() ? '<a class="rec-pill secondary-pill" href="' . h(url(['p' => 'terminal', 'n' => $net['slug'], 'id' => $h['id'], 'auto' => 1])) . '">' . icon('terminal', 16) . th('SSH-Terminal') . '</a>' : '')
            . (can('ping') ? '<button type="button" class="rec-pill" id="hostPing" data-url="' . h(url(['a' => 'ping_host'])) . '">' . icon('pulse', 16) . 'Ping</button>' : '') . '</div>' : '')
        . '</div></section>';

    if (!$rowOk) {
        echo '<div class="flash flash-error">' . th('Der Hash dieses Eintrags stimmt nicht – die Daten wurden am Portal vorbei verändert.') . '</div>';
    }
    if (!empty($h['master_id'])) {
        $mm = master_get((int)$h['master_id']);
        echo '<div class="flash flash-info">' . icon('crown', 14) . ' ' . th('Dieser Eintrag wird vom Master „{m}“ gepflegt. Felder aus der Vorlage werden beim nächsten Abgleich wieder gesetzt; MAC und Hardware-Kennung bleiben.', ['m' => (string)($mm['name'] ?? '#' . $h['master_id'])])
            . ' <a href="' . h(url(['p' => 'master_edit', 'id' => $h['master_id']])) . '">' . th('Vorlage öffnen') . '</a></div>';
    }

    $acts = '';
    if (can('edit')) {
        $acts .= btn(t('Bearbeiten'), url(['p' => 'host_edit'] + $base), 'secondary', 'edit');
        $acts .= btn(t('Kopie anlegen'), url(['p' => 'host_edit', 'n' => $net['slug'], 'copy' => $h['id']]), 'secondary', 'copy');
    }
    if (can('detect') && $target !== null) {
        $acts .= '<button type="button" class="pill-btn secondary" id="hostDetect" data-url="' . h(url(['a' => 'detect_host'])) . '">' . icon('tag', 15) . th('MAC & Hardware ermitteln') . '</button>';
    }
    if (can('delete')) {
        $acts .= post_button(t('Löschen'), ['a' => 'host_delete'], 'danger', t('Host „{h}“ mit allen Diensten löschen?', ['h' => $h['hostname']]), 'trash', $base);
    }
    echo '<div class="action-row">' . $acts . '</div><div id="detectOut"></div>';

    $kv = [
        t('IP-Adresse') => $h['ip'] ? '<span class="mono">' . h($h['ip']) . '</span> ' . copy_btn((string)$h['ip'])
            . ($n['cidr'] && !ip_in_cidr((string)$h['ip'], $n['cidr']) ? ' <span class="badge warn">' . th('nicht in {c}', ['c' => $n['cidr']]) . '</span>' : '') : null,
        t('MAC-Adresse') => mac_html($h['mac']),
        t('Hardware-Kennung') => $h['hw_id'] === MAC_NOT_FOUND ? '<span class="badge warn">not found</span>' : ($h['hw_id'] ? '<span class="mono small break">' . h($h['hw_id']) . '</span>' : null),
        t('Typ') => th((string)$h['kind']),
        t('Betriebssystem') => h($h['os']),
        t('Hersteller / Modell') => h(trim($h['vendor'] . ' ' . $h['model'])),
        t('Standort') => h($h['location']),
        t('Verantwortlich') => h($h['owner']),
        'SSH' => $target !== null && can('ssh') ? '<span class="mono">' . h(ssh_command_line($h)) . '</span> ' . copy_btn(ssh_command_line($h)) : null,
        t('Überwachung') => (int)$h['monitor'] === 1 ? th('Ping bei „Alle pingen“') : '<span class="badge state-nocheck">' . th('ohne Statusprüfung') . '</span>',
        'Tags' => $h['tags'] ? implode(' ', array_map(static fn($t) => '<span class="svc-chip">' . h(trim($t)) . '</span>', explode(',', (string)$h['tags']))) : null,
    ];
    // Verknüpfungen: Switch-Port, verwalteter Switch, WAN-Anschlüsse
    $vnets = vid_networks();
    $links = [];
    foreach (switch_device_map()[$net['slug']][(int)$h['id']] ?? [] as $m) {
        $p = $m['port'];
        $links[] = '<a href="' . h(url(['p' => 'switch', 'id' => $m['switch']['id']]) . '#port-' . rawurlencode((string)$p['pnum'])) . '">' . icon('switch', 13) . ' ' . h($m['switch']['name']) . ' · ' . th('Port {p}', ['p' => $p['pnum']]) . '</a>'
            . vid_chip($p['untagged'] !== null ? (int)$p['untagged'] : null, $vnets) . implode('', array_map(static fn($v) => vid_chip($v, $vnets, [], true), vid_list((string)$p['tagged'])));
    }
    if ($links !== []) {
        $kv[t('Am Switch-Port')] = '<div class="host-links">' . implode('<br>', $links) . '</div>';
    }
    foreach (switches_list() as $sw) {
        if ((string)$sw['net'] === $net['slug'] && (int)$sw['host_id'] === (int)$h['id']) {
            $kv[t('Verwalteter Switch')] = '<a href="' . h(url(['p' => 'switch', 'id' => $sw['id']])) . '">' . icon('switch', 13) . ' ' . h($sw['name']) . '</a> ' . th('Ports, Netzwerke und Geräte ansehen');
        }
    }
    $np = netmap_host_path($net['slug'], (int)$h['id']);
    if ($np !== null && $np['steps'] !== []) {
        $steps = array_map(static fn($st) => '<a href="' . h((string)$st['node']['href']) . '">' . h((string)$st['node']['label']) . '</a>' . ($st['label'] !== '' ? ' <span class="dim small">' . h($st['label']) . '</span>' : ''), $np['steps']);
        $kv[t('Weg ins Internet')] = '<div class="nm-steps">' . implode(' <span class="dim">→</span> ', $steps) . ($np['internet'] ? '' : ' <span class="badge warn">' . th('kein Weg ins Internet') . '</span>')
            . ' <a class="icon-btn" href="' . h(url(['p' => 'netmap', 'focus' => nm_hid($net['slug'], (int)$h['id'])]) . '#map') . '" title="' . th('Weg im Plan zeigen') . '" aria-label="' . th('Weg im Plan zeigen') . '">' . icon('topology', 14) . '</a></div>';
    }
    $wl = wan_by_router($net['slug'], (int)$h['id']);
    if ($wl !== []) {
        $kv[t('WAN-Anschlüsse')] = implode(' ', array_map(static fn($w) => '<a class="svc-chip" href="' . h(url(['p' => 'wan', 'id' => $w['id']])) . '">' . icon('globe', 12) . ' ' . h($w['name']) . ' · ' . h((string)$w['provider']) . '</a>', $wl));
    }
    $meta = [
        t('Angelegt') => h(fmt_datetime($h['created_at']) . ' · ' . $h['created_by']),
        t('Geändert') => h(fmt_datetime($h['updated_at']) . ' · ' . $h['updated_by']),
        t('Eintrags-Hash') => '<span class="mono small break">' . h(substr((string)$h['row_hash'], 0, 32)) . '…</span> ' . ($rowOk ? '<span class="badge ok-soft">' . th('gültig') . '</span>' : '<span class="badge bad">' . th('ungültig') . '</span>'),
        t('Letzter Ping') => $s && $state !== 'nocheck' ? h(($s['ok'] ? t('online') : t('offline')) . ' · ' . fmt_ago((int)$s['ts']) . ' · ' . ($s['method'] ?? 'icmp')) : null,
    ];
    echo '<div class="grid-2"><section class="card"><h2 class="section-label">' . th('Stammdaten') . '</h2>' . kv_list($kv)
        . ($h['description'] ? '<p class="note">' . nl2br(h($h['description'])) . '</p>' : '') . '</section>'
        . '<section class="card"><h2 class="section-label">' . th('Ping & Status') . '</h2>' . kv_list($meta)
        . '<pre class="console" id="pingOut" data-empty="' . th('Noch kein Ping in dieser Sitzung.') . '">' . ($target === null ? th('Keine IP und kein gültiger Hostname – Ping nicht möglich.') : '') . '</pre></section></div>';

    // Dienste
    $svcActs = '';
    if ($services !== [] && can('check')) {
        $svcActs .= '<button type="button" class="pill-btn secondary small" id="svcCheck" data-url="' . h(url(['a' => 'svc_check'])) . '">' . icon('check', 14) . th('Dienste prüfen') . '</button>';
    }
    if (can('scan') && $target !== null) {
        $svcActs .= '<button type="button" class="pill-btn secondary small" id="svcScan" data-url="' . h(url(['a' => 'scan_host'])) . '"' . (can('edit') ? ' data-adopt="' . h(url(['a' => 'scan_adopt'])) . '"' : '') . '>' . icon('radar', 14) . th('Dienste erkennen') . '</button>';
    }
    echo '<h2 class="section-label spaced">' . th('Dienste ({n})', ['n' => count($services)]) . '<span class="section-actions">' . $svcActs . '</span></h2>';
    echo '<div id="scanResult"></div>';
    if ($services === []) {
        echo '<div class="empty-state small">' . icon('radar', 34) . '<p>' . th('Noch keine Dienste erfasst.') . (can('scan') ? ' ' . th('„Dienste erkennen“ prüft die üblichen Ports.') : '') . '</p></div>';
    } else {
        echo '<div class="table-wrap"><table class="data-table" id="svcTable"><thead><tr><th class="col-dot"></th><th>' . th('Dienst') . '</th><th>Port</th><th>' . th('Adresse') . '</th><th>' . th('Zustand') . '</th><th>' . th('Geprüft') . '</th><th class="col-act"></th></tr></thead><tbody>';
        foreach ($services as $sv) {
            $ss = $st['services'][(string)$sv['id']] ?? null;
            $sst = svc_state($sv, $ss);
            $svOk = sqlfile_row_valid($net['slug'], 'services', $sv);
            echo '<tr data-svc="' . (int)$sv['id'] . '" data-check="' . ($sst === 'nocheck' ? '0' : '1') . '"><td class="col-dot"><i class="dot ' . $sst . '" title="' . h(state_title($sst)) . '"></i></td>'
                . '<td><div class="cell-title"><span class="svc-chip ' . $sst . '">' . h($sv['name']) . '</span>' . ($svOk ? '' : ' <span class="badge bad">' . th('Hash ungültig') . '</span>') . '</div>' . ($sv['description'] ? '<div class="cell-sub">' . h($sv['description']) . '</div>' : '') . '</td>'
                . '<td class="mono">' . h($sv['proto'] . ($sv['port'] ? '/' . $sv['port'] : '')) . '</td>'
                . '<td>' . ($sv['url'] ? '<a href="' . h($sv['url']) . '" target="_blank" rel="noopener noreferrer" class="mono small">' . h($sv['url']) . '</a>' : '<span class="dim">–</span>') . '</td>'
                . '<td class="svc-state">' . ($sst === 'nocheck' ? '<span class="dim">' . th('ohne Statusprüfung') . '</span>' : ($ss ? h($ss['state'] . ($ss['ms'] !== null ? ' · ' . $ss['ms'] . ' ms' : '')) . ($ss['info'] ? '<div class="cell-sub">' . h($ss['info']) . '</div>' : '') : '<span class="dim">–</span>')) . '</td>'
                . '<td class="svc-ts small dim">' . ($ss && $sst !== 'nocheck' ? h(fmt_ago((int)$ss['ts'])) : '') . '</td>'
                . '<td class="col-act">' . (can('edit') ? '<button type="button" class="icon-btn svc-edit" title="' . th('Bearbeiten') . '" aria-label="' . th('Bearbeiten') . '" data-svc=\'' . h((string)json_encode(array_intersect_key($sv, array_flip(['id', 'name', 'proto', 'port', 'url', 'description', 'status_check'])))) . '\'>' . icon('edit', 14) . '</button>'
                    . post_button('', ['a' => 'service_delete'], 'icon-only', t('Dienst „{s}“ entfernen?', ['s' => $sv['name']]), 'trash', $base + ['sid' => $sv['id']], t('Löschen')) : '')
                . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo status_legend();
    if (can('edit')) {
        echo '<details class="card sub" id="svcFormBox"><summary>' . icon('plus', 14) . ' <span>' . th('Dienst hinzufügen') . '</span></summary>'
            . '<form method="post" action="' . h(url(['a' => 'service_save'])) . '" id="svcForm" autocomplete="off">' . csrf_field()
            . '<input type="hidden" name="n" value="' . h($net['slug']) . '"><input type="hidden" name="id" value="' . (int)$h['id'] . '"><input type="hidden" name="sid" value="">'
            . '<div class="form-grid cols-4">'
            . f_text('name', t('Name') . ' *', '', ['required' => true, 'maxlength' => 80, 'list' => 'svcNames', 'placeholder' => t('z. B. HTTPS, Proxmox, SSH')])
            . f_select('proto', t('Protokoll'), ['tcp' => 'TCP', 'udp' => 'UDP'], 'tcp')
            . f_text('port', 'Port', '', ['inputmode' => 'numeric', 'maxlength' => 5])
            . f_text('url', t('Adresse (URL)'), '', ['placeholder' => 'https://…', 'maxlength' => 300])
            . f_text('description', t('Beschreibung'), '', ['maxlength' => 300, 'class' => 'span3'])
            . '<div class="field check-field">' . f_check('status_check', t('Statusprüfung'), true, th('aus = Dienst nur dokumentieren (orange)')) . '</div>'
            . '</div><div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Speichern') . '</button><button class="pill-btn secondary" type="reset" id="svcReset">' . th('Leeren') . '</button></div></form>'
            . '<datalist id="svcNames">' . implode('', array_map(static fn($x) => '<option value="' . h($x) . '">', array_unique(array_values(WELL_KNOWN_PORTS)))) . '</datalist></details>';
    }

    // iperf3
    if (can('iperf') && $target !== null) {
        $last = $st['iperf'][(string)$h['id']] ?? null;
        $port = (int)($last['port'] ?? ($h['iperf_port'] ?: setting('tools.iperf_port', 5201)));
        echo '<h2 class="section-label spaced">iperf3</h2><section class="card iperf-card" data-url="' . h(url(['a' => 'iperf_run'])) . '">'
            . '<p class="hint">' . th('Durchsatztest vom Portal-Server zu {t}. Auf dem Ziel muss „iperf3 -s“ laufen (Standard-Port 5201).', ['t' => $target]) . (iperf_bin() === null ? ' <span class="badge bad">' . th('iperf3 auf dem Server nicht installiert') . '</span>' : '') . '</p>'
            . '<form id="iperfForm" class="form-grid cols-4" autocomplete="off">'
            . f_select('proto', t('Protokoll'), ['tcp' => 'TCP', 'udp' => 'UDP'], (string)($last['proto'] ?? 'tcp'), ['id' => 'iperfProto'])
            . f_text('port', 'Port', (string)$port, ['inputmode' => 'numeric', 'maxlength' => 5, 'hint' => th('Standard 5201 – hineinklicken zum Ändern'), 'title' => t('Standard 5201')])
            . f_text('duration', t('Dauer (Sekunden)'), (string)($last['duration'] ?? setting('tools.iperf_duration', 5)), ['type' => 'number', 'min' => 1, 'max' => (int)setting('tools.iperf_max_duration', 60)])
            . f_select('direction', t('Richtung'), ['up' => t('Server → Ziel (Upload)'), 'down' => t('Ziel → Server (Download, -R)')], (string)($last['direction'] ?? 'up'))
            . f_text('bandwidth', t('UDP-Bandbreite'), (string)($last['bandwidth'] ?? setting('tools.iperf_udp_bandwidth', '100M')), ['maxlength' => 8, 'hint' => th('z. B. 10M, 100M, 1G (nur UDP)'), 'class' => 'iperf-udp'])
            . f_text('streams', t('Parallele Streams'), (string)($last['streams'] ?? 1), ['type' => 'number', 'min' => 1, 'max' => 16])
            . '<div class="field span2"><span>&nbsp;</span><button class="pill-btn primary" type="submit">' . icon('gauge', 15) . th('Test starten') . '</button></div></form>'
            . '<div id="iperfOut">' . ($last ? iperf_result_html($last) : '') . '</div></section>';
    }

    // SSH (nur Power-User und Portal-Admins)
    if (can('ssh')) {
    echo '<h2 class="section-label spaced">SSH</h2><section class="card ssh-card" data-probe="' . h(url(['a' => 'ssh_probe'])) . '" data-exec="' . h(url(['a' => 'ssh_exec'])) . '">';
    if ($target === null) {
        echo '<p class="dim">' . th('Keine Adresse hinterlegt.') . '</p>';
    } else {
        echo '<div class="ssh-head"><div><div class="mono">' . h(ssh_command_line($h)) . '</div><div class="cell-sub">' . th('Die SSH-Verbindung baut der Portal-Server auf – auf deinem Rechner wird nichts gestartet oder installiert.') . '</div></div>'
            . (setting('ssh.enabled') ? '<button type="button" class="pill-btn secondary small" id="sshProbe">' . icon('key', 14) . th('Banner & Host-Key') . '</button>' : '') . '</div>'
            . '<div id="sshProbeOut"></div>';
        if (setting('ssh.exec_enabled')) {
            $problems = ssh_ready();
            if ($problems !== []) {
                echo '<p class="note warn">' . th('SSH-Befehle sind aktiviert, aber nicht einsatzbereit:') . ' ' . h(implode(' · ', $problems)) . '</p>';
            } else {
                $allow = array_values(array_filter(array_map('trim', explode("\n", (string)setting('ssh.allowlist', '')))));
                $free = setting('ssh.allow_free') && can('ssh_free');
                echo '<form class="ssh-exec" id="sshExec" autocomplete="off"><div class="form-grid cols-4">'
                    . f_text('ssh_user', t('Benutzer'), $h['ssh_user'] ?: setting('ssh.default_user', 'root'), ['maxlength' => 64])
                    . '<label class="field span3"><span>' . ($free ? th('Befehl (frei oder aus der Liste)') : th('Befehl (aus der Freigabeliste)')) . '</span>'
                    . ($free ? '<input name="command" list="sshAllow" class="mono" maxlength="1000" required spellcheck="false">' : '<select name="command" class="mono">' . implode('', array_map(static fn($c) => '<option>' . h($c) . '</option>', $allow)) . '</select>')
                    . '</label></div>'
                    . ($free ? '<datalist id="sshAllow">' . implode('', array_map(static fn($c) => '<option value="' . h($c) . '">', $allow)) . '</datalist>' : '')
                    . '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('terminal', 15) . th('Ausführen') . '</button><span class="hint">' . th('Anmeldung mit dem Portal-Schlüssel, Befehl und Ausgabe-Hash werden im Audit-Log festgehalten.') . '</span></div></form>'
                    . '<pre class="console" id="sshOut" hidden></pre>';
            }
        } else {
            echo '<p class="hint">' . th('SSH-Befehle aus dem Portal sind deaktiviert (Einstellungen → SSH).') . '</p>';
        }
    }
    echo '</section>';
    }

    $hist = sys_all("SELECT * FROM audit WHERE net = ? AND ((object_type = 'host' AND object_id = ?) OR (object_type = 'service' AND object_label LIKE ?)) AND action NOT IN ('host_view') ORDER BY id DESC LIMIT 40",
        [$net['slug'], (string)$h['id'], '%@' . $h['id']]);
    echo '<h2 class="section-label spaced">' . th('Verlauf') . (can('audit') ? ' <a class="section-link" href="' . h(url(['p' => 'audit', 'net' => $net['slug'], 'oid' => $h['id']])) . '">' . th('im Audit-Log') . '</a>' : '') . '</h2>';
    if ($hist === []) {
        echo '<p class="dim">' . th('Keine Einträge.') . '</p>';
    } else {
        echo '<div class="track-list compact">';
        foreach ($hist as $r) {
            echo audit_row_compact($r);
        }
        echo '</div>';
    }
    audit('host_view', [], null, 'ok', host_ctx($net, $h));
    page_end();
}

function iperf_result_html(array $r): string
{
    if (empty($r['ok'])) {
        return '<p class="note warn">' . h((string)($r['error'] ?? t('Fehler'))) . '</p>';
    }
    $tiles = '<div class="stat-grid iperf-tiles">'
        . stat_tile(t('Senden'), fmt_bps((float)$r['sent_bps']), $r['proto'] === 'udp' ? 'UDP' : 'TCP', 'gauge')
        . stat_tile(t('Empfangen'), fmt_bps((float)$r['recv_bps']), (string)($r['remote'] ?? ''), 'download');
    if ($r['proto'] === 'udp') {
        $tiles .= stat_tile(t('Jitter'), fmt_num((float)$r['jitter_ms'], 3) . ' ms', '', 'pulse')
            . stat_tile(t('Verlust'), fmt_num((float)$r['lost_pct'], 2) . ' %', $r['lost'] . ' / ' . $r['packets'] . ' ' . t('Pakete'), 'x', (float)$r['lost_pct'] > 1 ? 'warn' : 'ok');
    } else {
        $tiles .= stat_tile(t('Wiederholungen'), (string)(int)$r['retransmits'], 'TCP retransmits', 'backup', (int)$r['retransmits'] > 0 ? 'warn' : 'ok');
    }
    return $tiles . '</div><div class="hint">' . h(t('iperf3 {v} · {d} s · {ts}', ['v' => $r['version'] ?? '', 'd' => fmt_num((float)$r['duration'], 1), 'ts' => isset($r['ts']) ? fmt_ago((int)$r['ts']) : ''])) . ' · <span class="mono">iperf3 ' . h((string)($r['cmd'] ?? '')) . '</span></div>';
}

// --- Host bearbeiten ---------------------------------------------------------------------------

function page_host_edit(): void
{
    $net = net_or_404(get_str('n', 60));
    $id = (int)($_GET['id'] ?? 0);
    $n = $net['network'];
    if ($id > 0) {
        $h = host_or_404($net, $id);
    } else {
        $used = array_filter(array_map(static fn($x) => (string)$x['ip'], $net['hosts']));
        $h = array_fill_keys(array_keys(NET_SCHEMA['hosts']), null);
        $h['monitor'] = 1;
        $h['ip'] = cidr_next_free($n['cidr'], $used, $n['gateway']);
        if (($copy = (int)($_GET['copy'] ?? 0)) > 0 && isset($net['hosts'][$copy])) {
            $src = $net['hosts'][$copy];
            foreach (['kind', 'icon', 'os', 'vendor', 'model', 'location', 'owner', 'ssh_user', 'ssh_port', 'iperf_port', 'monitor', 'tags', 'description'] as $k) {
                $h[$k] = $src[$k];
            }
            $h['hostname'] = $src['hostname'] . '-' . t('kopie');
        }
    }
    if (isset($_SESSION['form_host'])) {
        $h = array_merge($h, $_SESSION['form_host']);
        unset($_SESSION['form_host']);
    }
    $title = $id > 0 ? t('Host bearbeiten') : t('Neuer Host');
    page_start($title, 'net:' . $net['slug'], [[t('Netzwerke'), url()], [$n['name'], url(['p' => 'net', 'n' => $net['slug']])], [$id > 0 ? (string)$h['hostname'] : t('Neuer Host'), $id > 0 ? url(['p' => 'host', 'n' => $net['slug'], 'id' => $id]) : null], [$id > 0 ? t('Bearbeiten') : t('Anlegen'), null]]);
    heading($title, h($n['name']) . ($n['cidr'] ? ' · <span class="mono">' . h($n['cidr']) . '</span>' : ''));
    $kinds = ['' => '–'];
    foreach (HOST_KINDS as $k) {
        $kinds[$k] = t($k);
    }
    $auto = setting('detect.auto');
    echo '<form class="form-layout" method="post" enctype="multipart/form-data" action="' . h(url(['a' => 'host_save'])) . '" autocomplete="off">' . csrf_field()
        . '<input type="hidden" name="n" value="' . h($net['slug']) . '"><input type="hidden" name="id" value="' . $id . '">';
    echo '<section class="card"><h2 class="section-label">' . th('Adresse') . '</h2><div class="form-grid cols-4">'
        . f_text('hostname', t('Name / Hostname') . ' *', $h['hostname'], ['required' => true, 'maxlength' => 100, 'autofocus' => $id === 0, 'class' => 'span2', 'placeholder' => t('z. B. pve01.firma.local')])
        . f_text('ip', t('IP-Adresse'), $h['ip'], ['maxlength' => 45, 'placeholder' => $n['cidr'] ? t('in {c}', ['c' => $n['cidr']]) : '', 'hint' => $id === 0 && $h['ip'] ? th('Vorschlag: nächste freie Adresse') : ''])
        . f_text('mac', t('MAC-Adresse'), $h['mac'], ['maxlength' => 20, 'placeholder' => $auto ? t('leer = automatisch ermitteln') : 'aa:bb:cc:dd:ee:ff',
            'hint' => $auto ? th('Wird beim Speichern automatisch ermittelt; geht das nicht, steht hier „not found“ zum manuellen Eintragen.') : ''])
        . f_text('hw_id', t('Hardware-Kennung'), $h['hw_id'], ['maxlength' => 120, 'class' => 'span2', 'placeholder' => $auto ? t('leer = automatisch ermitteln') : '',
            'hint' => th('z. B. DMI-UUID, machine-id, SNMP-Kennung oder Seriennummer')])
        . '<div class="field span2 check-field">' . f_check('redetect', t('MAC und Hardware-Kennung jetzt neu ermitteln'), false, th('überschreibt nur Felder mit „not found“ oder leere Felder')) . '</div>'
        . '</div></section>';
    echo '<section class="card"><h2 class="section-label">' . th('Gerät') . '</h2><div class="form-grid cols-4">'
        . f_select('kind', t('Typ'), $kinds, $h['kind'])
        . f_text('os', t('Betriebssystem / Firmware'), $h['os'], ['maxlength' => 100, 'list' => 'osList'])
        . f_text('vendor', t('Hersteller'), $h['vendor'], ['maxlength' => 80, 'hint' => th('leer = aus der MAC ermitteln')])
        . f_text('model', t('Modell'), $h['model'], ['maxlength' => 80])
        . f_text('location', t('Standort / Rack'), $h['location'], ['maxlength' => 120])
        . f_text('owner', t('Verantwortlich'), $h['owner'], ['maxlength' => 120])
        . f_text('tags', 'Tags', $h['tags'], ['maxlength' => 200, 'placeholder' => t('produktiv, backup, …'), 'class' => 'span2'])
        . f_textarea('description', t('Beschreibung'), $h['description'], 3, ['class' => 'span4'])
        . '</div><datalist id="osList"><option value="Debian 13"><option value="Ubuntu 24.04 LTS"><option value="Linux Mint 22"><option value="Proxmox VE 9"><option value="Windows 11"><option value="Windows 10"><option value="Windows 8.1"><option value="Windows 7"><option value="Windows Vista"><option value="Windows XP"><option value="Windows 2000"><option value="Windows Server 2025"><option value="Windows Server 2022"><option value="Windows Server 2019"><option value="Windows Server 2016"><option value="Windows Server 2012 R2"><option value="Windows Server 2012"><option value="Windows Server 2008 R2"><option value="Windows Server 2008"><option value="Windows Server 2003 R2"><option value="Windows Server 2003"><option value="RouterOS"><option value="OPNsense"><option value="pfSense"><option value="Cisco IOS"><option value="TrueNAS"><option value="Siemens S7-1500"></datalist></section>';
    echo '<section class="card"><h2 class="section-label">Icon</h2>' . icon_picker((string)($h['icon'] ?? '')) . '</section>';
    echo '<section class="card"><h2 class="section-label">' . th('Zugriff & Überwachung') . '</h2><div class="form-grid cols-4">'
        . f_text('ssh_user', t('SSH-Benutzer'), $h['ssh_user'], ['maxlength' => 64, 'placeholder' => (string)setting('ssh.default_user', 'root')])
        . f_text('ssh_port', t('SSH-Port'), $h['ssh_port'], ['inputmode' => 'numeric', 'maxlength' => 5, 'placeholder' => '22'])
        . f_text('iperf_port', t('iperf3-Port'), $h['iperf_port'], ['inputmode' => 'numeric', 'maxlength' => 5, 'placeholder' => (string)setting('tools.iperf_port', 5201), 'hint' => th('leer = Standard (automatisch)')])
        . '<div class="field check-field">' . f_check('monitor', t('Statusprüfung (Ping)'), (int)$h['monitor'] === 1, th('aus = nur dokumentieren (orange)')) . '</div>'
        . '</div></section>';
    echo '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Speichern') . '</button>'
        . btn(t('Abbrechen'), $id > 0 ? url(['p' => 'host', 'n' => $net['slug'], 'id' => $id]) : url(['p' => 'net', 'n' => $net['slug']]), 'secondary')
        . ($id === 0 ? '<label class="check inline"><input type="checkbox" name="then_new" value="1"><span>' . th('danach weiteren Host anlegen') . '</span></label>' : '') . '</div></form>';
    page_end();
}

function host_validate(array $net, int $id): array
{
    $err = [];
    $warn = [];
    $row = [
        'hostname' => trim(preg_replace('/[\x00-\x1F\x7F]/', '', in_str('hostname', 100)) ?? ''),
        'ip' => in_str('ip', 45),
        'mac' => in_str('mac', 20),
        'hw_id' => trim(preg_replace('/[\x00-\x1F\x7F]/', '', in_str('hw_id', 120)) ?? ''),
        'kind' => in_array(in_str('kind', 40), HOST_KINDS, true) ? in_str('kind', 40) : null,
        'icon' => in_str('icon', 60),
        'os' => in_str('os', 100), 'vendor' => in_str('vendor', 80), 'model' => in_str('model', 80),
        'location' => in_str('location', 120), 'owner' => in_str('owner', 120),
        'ssh_user' => in_str('ssh_user', 64), 'ssh_port' => in_str('ssh_port', 5), 'iperf_port' => in_str('iperf_port', 5),
        'monitor' => in_bool('monitor') ? 1 : 0,
        'tags' => implode(', ', array_filter(array_map('trim', explode(',', in_str('tags', 200))))),
        'description' => in_str('description', 4000),
    ];
    if ($row['hostname'] === '') {
        $err[] = t('Name fehlt.');
    }
    if ($row['ip'] !== '') {
        if (!ip_valid($row['ip'])) {
            $err[] = t('IP-Adresse ungültig.');
        } else {
            $row['ip'] = inet_ntop(inet_pton($row['ip']));
            foreach ($net['hosts'] as $o) {
                if ((int)$o['id'] !== $id && (string)$o['ip'] === $row['ip']) {
                    $warn[] = t('Die IP {ip} ist auch bei „{h}“ eingetragen.', ['ip' => $row['ip'], 'h' => $o['hostname']]);
                }
            }
            if ($net['network']['cidr'] && !ip_in_cidr($row['ip'], $net['network']['cidr'])) {
                $warn[] = t('Die IP liegt nicht im Netz {c}.', ['c' => $net['network']['cidr']]);
            }
        }
    }
    if ($row['mac'] !== '' && strcasecmp($row['mac'], MAC_NOT_FOUND) === 0) {
        $row['mac'] = MAC_NOT_FOUND;
    } elseif ($row['mac'] !== '') {
        $m = mac_normalize($row['mac']);
        if ($m === null) {
            $err[] = t('MAC-Adresse ungültig.');
        } else {
            $row['mac'] = $m;
            foreach ($net['hosts'] as $o) {
                if ((int)$o['id'] !== $id && (string)$o['mac'] === $m) {
                    $warn[] = t('Die MAC ist auch bei „{h}“ eingetragen.', ['h' => $o['hostname']]);
                }
            }
        }
    }
    if (strcasecmp($row['hw_id'], MAC_NOT_FOUND) === 0) {
        $row['hw_id'] = MAC_NOT_FOUND;
    }
    if (!icon_valid_value($row['icon'])) {
        $row['icon'] = '';
    }
    if ($row['ssh_user'] !== '' && !ssh_valid_user($row['ssh_user'])) {
        $err[] = t('SSH-Benutzer ungültig.');
    }
    foreach (['ssh_port' => 'SSH-Port', 'iperf_port' => 'iperf3-Port'] as $f => $label) {
        if ($row[$f] !== '' && (!ctype_digit($row[$f]) || (int)$row[$f] < 1 || (int)$row[$f] > 65535)) {
            $err[] = t('{f} muss zwischen 1 und 65535 liegen.', ['f' => t($label)]);
        }
    }
    if ($row['ip'] === '' && !hostname_valid($row['hostname'])) {
        $warn[] = t('Ohne IP und ohne gültigen DNS-Namen sind Ping und SSH nicht möglich.');
    }
    return [$row, $err, $warn];
}

function action_host_save(): void
{
    $slug = in_str('n', 60);
    $id = in_int('id');
    $net = net_or_404($slug);
    if ($id > 0) {
        host_or_404($net, $id);
    }
    [$row, $err, $warn] = host_validate($net, $id);
    if (!empty($_FILES['icon_upload']) && ($_FILES['icon_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $row['icon'] = icon_store_upload($_FILES['icon_upload']);
            audit('icon_upload', ['datei' => substr($row['icon'], 2), 'groesse' => (int)$_FILES['icon_upload']['size']], null, 'ok', ['net' => $slug, 'type' => 'host', 'id' => $id ?: null, 'label' => $row['hostname']]);
        } catch (RuntimeException $e) {
            $err[] = $e->getMessage();
        }
    }
    if ($err !== []) {
        foreach ($err as $e) {
            flash($e, 'error');
        }
        $_SESSION['form_host'] = $row;
        redirect(url(['p' => 'host_edit', 'n' => $slug, 'id' => $id ?: null]));
    }
    // MAC / Hardware-Kennung automatisch ermitteln (neu angelegt, leer, "not found" oder ausdrücklich gewünscht)
    $detect = null;
    $needs = static fn($v) => $v === '' || $v === MAC_NOT_FOUND;
    if (setting('detect.auto') && ($needs($row['mac']) || $needs($row['hw_id'])) && ($id === 0 || in_bool('redetect') || $needs($row['mac']))) {
        session_write_close();
        $detect = detect_host($row);
        session_start();
        if ($needs($row['mac'])) {
            $row['mac'] = $detect['mac'];
        }
        if ($needs($row['hw_id'])) {
            $row['hw_id'] = $detect['hw_id'];
        }
        foreach (['vendor', 'model', 'os'] as $k) {
            if ($row[$k] === '' && !empty($detect[$k])) {
                $row[$k] = mb_substr((string)$detect[$k], 0, $k === 'os' ? 100 : 80);
            }
        }
    }
    $user = current_actor();
    [$newId, $diff, $old] = net_write($slug, static function (array &$net) use ($row, $id, $user): array {
        $old = $id > 0 ? $net['hosts'][$id] : null;
        $data = $row + ['updated_at' => now_str(), 'updated_by' => $user];
        if ($old !== null) {
            $data = array_merge($old, $data);
        } else {
            $data['created_at'] = $data['updated_at'];
            $data['created_by'] = $user;
        }
        $newId = net_put($net, 'hosts', $data);
        return [$newId, $old ? audit_diff($old, $data) : null, $old];
    });
    $net = net_load($slug, true);
    $h = $net['hosts'][$newId];
    if ($detect !== null) {
        audit('detect_host', ['mac' => $detect['mac'], 'quelle_mac' => $detect['mac_source'], 'hw_id' => $detect['hw_id'], 'quelle_hw' => $detect['hw_source'],
            'hersteller' => $detect['vendor'], 'protokoll' => $detect['log']], null, $detect['mac'] === MAC_NOT_FOUND ? 'warn' : 'ok', host_ctx($net, $h));
    }
    if ($old === null) {
        audit('host_create', ['daten' => array_diff_key($h, ['row_hash' => 1]), 'row_hash' => $h['row_hash']], null, 'ok', host_ctx($net, $h));
        flash(t('Host „{h}“ angelegt.', ['h' => $h['hostname']]));
    } else {
        audit('host_update', ['aenderungen' => $diff, 'row_hash_alt' => $old['row_hash'], 'row_hash_neu' => $h['row_hash']], null, 'ok', host_ctx($net, $h));
        flash($diff === [] ? t('Keine Änderungen.') : t('Host gespeichert ({n} Feld(er) geändert).', ['n' => count($diff)]));
    }
    if ($detect !== null && $h['mac'] === MAC_NOT_FOUND) {
        flash(t('MAC-Adresse konnte nicht ermittelt werden – im Feld steht „not found“, bitte manuell eintragen.'), 'warn');
    }
    foreach ($warn as $w) {
        flash($w, 'warn');
    }
    if ($old === null && in_bool('then_new')) {
        redirect(url(['p' => 'host_edit', 'n' => $slug]));
    }
    redirect(url(['p' => 'host', 'n' => $slug, 'id' => $newId]));
}

function action_host_delete(): void
{
    $slug = in_str('n', 60);
    $id = in_int('id');
    $net = net_or_404($slug);
    $h = host_or_404($net, $id);
    $removed = net_write($slug, static function (array &$net) use ($id): array {
        $svcs = [];
        foreach ($net['services'] as $sid => $s) {
            if ((int)$s['host_id'] === $id) {
                $svcs[] = net_remove($net, 'services', (int)$sid);
            }
        }
        net_remove($net, 'hosts', $id);
        return $svcs;
    });
    try {
        $refs = infra_forget_host($slug, (int)$h['id']);
    } catch (StoreWriteBlocked $e) {
        $refs = ['fehler' => $e->getMessage()];
    }
    audit('host_delete', ['datensatz' => $h, 'dienste' => $removed, 'verweise_entfernt' => $refs], null, 'ok', host_ctx($net, $h));
    flash(t('Host „{h}“ gelöscht.', ['h' => $h['hostname']]) . ($removed ? ' ' . t('(inkl. {n} Dienste)', ['n' => count($removed)]) : ''));
    redirect(url(['p' => 'net', 'n' => $slug]));
}

/** MAC/Hardware erneut ermitteln (Host-Seite) und Ergebnis übernehmen. */
function action_detect_host(): void
{
    [$net, $h] = json_host();
    session_write_close();
    $d = detect_host($h);
    $changes = [];
    foreach (['mac', 'hw_id'] as $k) {
        $cur = (string)($h[$k] ?? '');
        if ($d[$k] !== MAC_NOT_FOUND || $cur === '') {
            if ($cur !== $d[$k]) {
                $changes[$k] = $d[$k];
            }
        }
    }
    if (empty($h['vendor']) && !empty($d['vendor'])) {
        $changes['vendor'] = mb_substr((string)$d['vendor'], 0, 80);
    }
    if ($changes !== []) {
        net_write($net['slug'], static function (array &$n) use ($h, $changes): void {
            net_put($n, 'hosts', array_merge($n['hosts'][(int)$h['id']], $changes, ['updated_at' => now_str(), 'updated_by' => current_actor()]));
        });
    }
    audit('detect_host', ['mac' => $d['mac'], 'quelle_mac' => $d['mac_source'], 'hw_id' => $d['hw_id'], 'quelle_hw' => $d['hw_source'], 'uebernommen' => $changes, 'protokoll' => $d['log']],
        null, $d['mac'] === MAC_NOT_FOUND ? 'warn' : 'ok', host_ctx($net, $h));
    json_out(['mac' => $d['mac'], 'hw_id' => $d['hw_id'], 'vendor' => $d['vendor'], 'log' => $d['log'], 'changed' => array_keys($changes)]);
}

// --- Dienste ---------------------------------------------------------------------------------

function action_service_save(): void
{
    $slug = in_str('n', 60);
    $hid = in_int('id');
    $sid = in_int('sid');
    $net = net_or_404($slug);
    $h = host_or_404($net, $hid);
    $row = [
        'host_id' => $hid,
        'name' => trim(preg_replace('/[\x00-\x1F\x7F]/', '', in_str('name', 80)) ?? ''),
        'proto' => in_str('proto', 3) === 'udp' ? 'udp' : 'tcp',
        'port' => in_str('port', 5),
        'url' => in_str('url', 300),
        'status_check' => in_bool('status_check') ? 1 : 0,
        'description' => in_str('description', 300),
    ];
    $err = [];
    if ($row['name'] === '') {
        $err[] = t('Name des Dienstes fehlt.');
    }
    if ($row['port'] !== '' && (!ctype_digit($row['port']) || (int)$row['port'] < 1 || (int)$row['port'] > 65535)) {
        $err[] = t('Port muss zwischen 1 und 65535 liegen.');
    }
    if ($row['url'] !== '' && !preg_match('#^(https?|ssh|rdp|vnc|smb|ftp|sftp)://\S+$#i', $row['url'])) {
        $err[] = t('Adresse muss mit http://, https://, ssh://, rdp:// … beginnen.');
    }
    if ($row['port'] === '' && $row['url'] === '') {
        $err[] = t('Port oder Adresse angeben.');
    }
    if ($sid > 0 && (!isset($net['services'][$sid]) || (int)$net['services'][$sid]['host_id'] !== $hid)) {
        $err[] = t('Dienst nicht gefunden.');
    }
    if ($err !== []) {
        foreach ($err as $e) {
            flash($e, 'error');
        }
        redirect(url(['p' => 'host', 'n' => $slug, 'id' => $hid]) . '#svcFormBox');
    }
    $user = current_actor();
    [$newId, $old, $data] = net_write($slug, static function (array &$net) use ($row, $sid, $user): array {
        $old = $sid > 0 ? $net['services'][$sid] : null;
        $data = $row + ['updated_at' => now_str(), 'updated_by' => $user];
        $data = $old ? array_merge($old, $data) : $data + ['created_at' => now_str(), 'created_by' => $user];
        return [net_put($net, 'services', $data), $old, $data];
    });
    if ($row['status_check'] === 0) {
        status_put($slug, ['services' => [$newId => null]]);
    }
    $ctx = ['net' => $slug, 'type' => 'service', 'id' => $newId, 'label' => $row['name'] . ' @' . $hid];
    if ($old === null) {
        audit('service_create', ['host' => $h['hostname'], 'daten' => $row], null, 'ok', $ctx);
        flash(t('Dienst „{s}“ hinzugefügt.', ['s' => $row['name']]));
    } else {
        audit('service_update', ['host' => $h['hostname'], 'aenderungen' => audit_diff($old, $data)], null, 'ok', $ctx);
        flash(t('Dienst gespeichert.'));
    }
    redirect(url(['p' => 'host', 'n' => $slug, 'id' => $hid]));
}

function action_service_delete(): void
{
    $slug = in_str('n', 60);
    $hid = in_int('id');
    $sid = in_int('sid');
    $net = net_or_404($slug);
    $h = host_or_404($net, $hid);
    $sv = $net['services'][$sid] ?? null;
    if ($sv === null || (int)$sv['host_id'] !== $hid) {
        flash(t('Dienst nicht gefunden.'), 'error');
        redirect(url(['p' => 'host', 'n' => $slug, 'id' => $hid]));
    }
    net_write($slug, static fn(array &$net) => net_remove($net, 'services', $sid));
    audit('service_delete', ['host' => $h['hostname'], 'datensatz' => $sv], null, 'ok', ['net' => $slug, 'type' => 'service', 'id' => $sid, 'label' => $sv['name'] . ' @' . $hid]);
    flash(t('Dienst „{s}“ entfernt.', ['s' => $sv['name']]));
    redirect(url(['p' => 'host', 'n' => $slug, 'id' => $hid]));
}

// --- JSON: Ping, Erkennung, Prüfung, iperf3, SSH ---------------------------------------------------

function json_host(): array
{
    $net = net_or_404(in_str('n', 60));
    $h = host_or_404($net, in_int('id'));
    return [$net, $h];
}

function action_ping_host(): void
{
    [$net, $h] = json_host();
    $t = host_target($h);
    if ($t === null) {
        json_out(['error' => t('Keine Adresse zum Pingen.')], 400);
    }
    session_write_close();
    $r = net_ping($t);
    status_put($net['slug'], ['hosts' => [$h['id'] => ['ok' => $r['ok'], 'avg' => $r['avg'], 'loss' => $r['loss'], 'ts' => time(), 'method' => $r['method']]]]);
    audit('ping_host', ['ziel' => $t, 'methode' => $r['method'], 'gesendet' => $r['sent'], 'empfangen' => $r['received'], 'verlust_pct' => $r['loss'],
        'rtt_ms' => ['min' => $r['min'], 'avg' => $r['avg'], 'max' => $r['max']], 'exit' => $r['exit'], 'dauer_ms' => $r['ms']], null, $r['ok'] ? 'ok' : 'warn', host_ctx($net, $h));
    json_out(['ok' => $r['ok'], 'avg' => $r['avg'], 'loss' => $r['loss'], 'method' => $r['method'], 'output' => $r['output'], 'target' => $t, 'ts' => time(),
        'nocheck' => (int)$h['monitor'] !== 1]);
}

function action_ping_target(): void
{
    $t = net_target(in_str('target', 253));
    if ($t === null) {
        json_out(['error' => t('Ungültiges Ziel – IP-Adresse oder Hostname angeben.')], 400);
    }
    session_write_close();
    $r = net_ping($t);
    $known = null;
    foreach (array_keys(net_list()) as $slug) {
        foreach (net_load($slug)['hosts'] as $h) {
            if ((string)$h['ip'] === $t || strcasecmp((string)$h['hostname'], $t) === 0) {
                $known = [$slug, $h];
                status_put($slug, ['hosts' => [$h['id'] => ['ok' => $r['ok'], 'avg' => $r['avg'], 'loss' => $r['loss'], 'ts' => time(), 'method' => $r['method']]]]);
                break 2;
            }
        }
    }
    audit('ping_target', ['ziel' => $t, 'methode' => $r['method'], 'empfangen' => $r['received'], 'gesendet' => $r['sent'], 'verlust_pct' => $r['loss'], 'avg_ms' => $r['avg'],
        'bekannter_host' => $known ? $known[0] . '#' . $known[1]['id'] : null], null, $r['ok'] ? 'ok' : 'warn',
        $known ? ['net' => $known[0], 'type' => 'host', 'id' => $known[1]['id'], 'label' => $known[1]['hostname'] . ' ' . $known[1]['ip']] : ['type' => 'target', 'label' => $t]);
    json_out(['ok' => $r['ok'], 'avg' => $r['avg'], 'loss' => $r['loss'], 'method' => $r['method'], 'output' => $r['output'], 'target' => $t,
        'host' => $known ? url(['p' => 'host', 'n' => $known[0], 'id' => $known[1]['id']]) : null]);
}

function action_ping_network(): void
{
    $net = net_or_404(in_str('n', 60));
    session_write_close();
    $targets = [];
    foreach ($net['hosts'] as $h) {
        if ((int)$h['monitor'] === 1 && ($t = host_target($h)) !== null) {
            $targets[(int)$h['id']] = $t;
        }
    }
    $t0 = microtime(true);
    $res = net_ping_many($targets);
    $upd = $out = [];
    foreach ($res as $id => $r) {
        $upd[$id] = ['ok' => $r['ok'], 'avg' => $r['avg'], 'loss' => $r['loss'] ?? null, 'ts' => time(), 'method' => $r['method']];
        $out[$id] = ['ok' => $r['ok'], 'avg' => $r['avg']];
    }
    status_put($net['slug'], ['hosts' => $upd]);
    $up = count(array_filter($res, static fn($r) => $r['ok']));
    audit('ping_network', ['hosts' => count($targets), 'online' => $up, 'offline' => count($targets) - $up,
        'offline_liste' => array_values(array_map(static fn($id) => $targets[$id], array_keys(array_filter($res, static fn($r) => !$r['ok'])))),
        'dauer_ms' => (int)round((microtime(true) - $t0) * 1000)], null, $up === count($targets) ? 'ok' : 'warn',
        ['net' => $net['slug'], 'type' => 'network', 'label' => $net['network']['name']]);
    json_out(['results' => $out, 'up' => $up, 'total' => count($targets)]);
}

function action_scan_host(): void
{
    [$net, $h] = json_host();
    $t = host_target($h);
    $ip = $t !== null ? net_resolve($t) : null;
    if ($ip === null) {
        json_out(['error' => t('Adresse nicht auflösbar.')], 400);
    }
    session_write_close();
    $ports = array_map('intval', array_filter(array_map('trim', explode(',', (string)setting('tools.scan_ports')))));
    foreach (array_filter(array_map('trim', explode(',', in_str('ports', 200)))) as $p) {
        if (ctype_digit($p)) {
            $ports[] = (int)$p;
        }
    }
    $ports = array_slice(array_values(array_unique($ports)), 0, 200);
    $t0 = microtime(true);
    $res = net_scan_ports($ip, $ports);
    $known = [];
    foreach ($net['services'] as $s) {
        if ((int)$s['host_id'] === (int)$h['id'] && $s['proto'] === 'tcp') {
            $known[(int)$s['port']] = true;
        }
    }
    $open = [];
    foreach ($res as $p => $r) {
        if ($r['state'] === 'open') {
            $open[] = ['port' => $p, 'name' => $r['name'] ?: 'TCP ' . $p, 'banner' => $r['banner'], 'ms' => $r['ms'], 'known' => isset($known[$p])];
        }
    }
    audit('scan_ports', ['ziel' => $ip, 'ports_geprueft' => count($ports), 'offen' => array_column($open, 'port'),
        'dauer_ms' => (int)round((microtime(true) - $t0) * 1000)], null, 'ok', host_ctx($net, $h));
    json_out(['ip' => $ip, 'checked' => count($ports), 'open' => $open, 'closed' => count(array_filter($res, static fn($r) => $r['state'] === 'closed')),
        'filtered' => count(array_filter($res, static fn($r) => $r['state'] === 'timeout'))]);
}

function action_scan_adopt(): void
{
    [$net, $h] = json_host();
    $ports = input()['ports'] ?? [];
    if (!is_array($ports) || $ports === []) {
        json_out(['error' => t('Keine Ports ausgewählt.')], 400);
    }
    $user = current_actor();
    $added = net_write($net['slug'], static function (array &$net) use ($ports, $h, $user): array {
        $have = [];
        foreach ($net['services'] as $s) {
            if ((int)$s['host_id'] === (int)$h['id']) {
                $have[$s['proto'] . (int)$s['port']] = true;
            }
        }
        $added = [];
        foreach (array_slice($ports, 0, 100) as $p) {
            $p = (int)$p;
            if ($p < 1 || $p > 65535 || isset($have['tcp' . $p])) {
                continue;
            }
            $name = WELL_KNOWN_PORTS[$p] ?? 'TCP ' . $p;
            $addr = (string)($h['ip'] ?: $h['hostname']);
            $addr = ip_is_v6($addr) ? '[' . $addr . ']' : $addr;
            $url = match ($p) {
                80, 8080, 3000, 9000 => 'http://' . $addr . ($p !== 80 ? ':' . $p : '') . '/',
                443, 8443, 8006, 9090, 10000, 5986 => 'https://' . $addr . ($p !== 443 ? ':' . $p : '') . '/',
                default => null,
            };
            $id = net_put($net, 'services', ['host_id' => $h['id'], 'name' => $name, 'proto' => 'tcp', 'port' => $p, 'url' => $url, 'status_check' => 1,
                'description' => t('per Dienst-Erkennung übernommen'), 'created_at' => now_str(), 'created_by' => $user, 'updated_at' => now_str(), 'updated_by' => $user]);
            $added[] = ['id' => $id, 'port' => $p, 'name' => $name];
        }
        return $added;
    });
    foreach ($added as $a) {
        audit('service_create', ['host' => $h['hostname'], 'quelle' => 'Dienst-Erkennung', 'port' => $a['port'], 'name' => $a['name']], null, 'ok',
            ['net' => $net['slug'], 'type' => 'service', 'id' => $a['id'], 'label' => $a['name'] . ' @' . $h['id']]);
    }
    json_out(['added' => count($added)]);
}

function action_svc_check(): void
{
    [$net, $h] = json_host();
    $t = host_target($h);
    $ip = $t !== null ? net_resolve($t) : null;
    session_write_close();
    $res = $upd = [];
    foreach ($net['services'] as $s) {
        if ((int)$s['host_id'] !== (int)$h['id'] || ($s['status_check'] !== null && (int)$s['status_check'] === 0)) {
            continue;
        }
        $r = $ip !== null ? net_check_service($ip, $s) : ['ok' => false, 'state' => t('keine Adresse'), 'ms' => null, 'info' => '', 'ts' => time()];
        $upd[(int)$s['id']] = $r;
        $res[(int)$s['id']] = $r + ['ago' => t('gerade eben'), 'cls' => $r['ok'] === null ? 'unknown' : ($r['ok'] ? 'up' : 'down')];
    }
    status_put($net['slug'], ['services' => $upd]);
    audit('svccheck', ['ziel' => $ip, 'ergebnisse' => array_map(static fn($r) => $r['state'] . ($r['ms'] !== null ? ' ' . $r['ms'] . 'ms' : ''), $res)], null,
        count(array_filter($res, static fn($r) => $r['ok'] === false)) ? 'warn' : 'ok', host_ctx($net, $h));
    json_out(['results' => $res]);
}

function action_iperf_run(): void
{
    [$net, $h] = json_host();
    $t = host_target($h);
    $ip = $t !== null ? net_resolve($t) : null;
    if ($ip === null) {
        json_out(['error' => t('Adresse nicht auflösbar.')], 400);
    }
    $proto = in_str('proto', 3) === 'udp' ? 'udp' : 'tcp';
    $portIn = in_str('port', 5);
    $port = $portIn === '' ? (int)($h['iperf_port'] ?: setting('tools.iperf_port', 5201)) : (int)$portIn;
    $maxDur = max(1, (int)setting('tools.iperf_max_duration', 60));
    $dur = max(1, min($maxDur, in_int('duration', (int)setting('tools.iperf_duration', 5))));
    $bw = strtoupper(in_str('bandwidth', 8) ?: (string)setting('tools.iperf_udp_bandwidth', '100M'));
    $streams = max(1, min(16, in_int('streams', 1)));
    $reverse = in_str('direction', 4) === 'down';
    if ($port < 1 || $port > 65535) {
        json_out(['error' => t('Port muss zwischen 1 und 65535 liegen.')], 400);
    }
    if (!preg_match('/^\d{1,5}(\.\d{1,2})?[KMG]?$/', $bw)) {
        json_out(['error' => t('UDP-Bandbreite ungültig (z. B. 100M).')], 400);
    }
    session_write_close();
    @set_time_limit($dur + 30);
    $r = iperf_run($ip, $port, $proto, $dur, $bw, $reverse, $streams);
    $r['ts'] = time();
    status_put($net['slug'], ['iperf' => [$h['id'] => $r + ['port' => $port, 'direction' => $reverse ? 'down' : 'up', 'bandwidth' => $bw, 'streams' => $streams, 'duration_req' => $dur]]]);
    audit('iperf_test', ['ziel' => $ip . ':' . $port, 'protokoll' => $proto, 'richtung' => $reverse ? 'Ziel→Server' : 'Server→Ziel', 'dauer_s' => $dur, 'streams' => $streams,
        'udp_bandbreite' => $proto === 'udp' ? $bw : null, 'ergebnis' => $r['ok'] ? array_intersect_key($r, array_flip(['sent_bps', 'recv_bps', 'retransmits', 'jitter_ms', 'lost_pct', 'version'])) : ($r['error'] ?? null)],
        null, $r['ok'] ? 'ok' : 'fail', host_ctx($net, $h));
    json_out($r + ['html' => iperf_result_html($r)]);
}

function action_ssh_probe(): void
{
    if (!setting('ssh.enabled')) {
        json_out(['error' => t('SSH-Funktionen sind deaktiviert.')], 403);
    }
    [$net, $h] = json_host();
    $t = host_target($h);
    $ip = $t !== null ? net_resolve($t) : null;
    if ($ip === null) {
        json_out(['error' => t('Adresse nicht auflösbar.')], 400);
    }
    session_write_close();
    $port = (int)($h['ssh_port'] ?: 22);
    $r = ssh_probe($ip, $port);
    $knownFp = ssh_known_fingerprints($ip, $port);
    audit('ssh_banner', ['ziel' => $ip . ':' . $port, 'zustand' => $r['state'], 'banner' => $r['banner'], 'fingerprints' => array_column($r['keys'], 'fp', 'type')], null,
        $r['state'] === 'open' ? 'ok' : 'warn', host_ctx($net, $h));
    json_out($r + ['ip' => $ip, 'port' => $port, 'known' => $knownFp]);
}

function ssh_known_fingerprints(string $ip, int $port): array
{
    $kh = app_path((string)setting('ssh.known_hosts'));
    if (!is_file($kh)) {
        return [];
    }
    $name = $port === 22 ? $ip : '[' . $ip . ']:' . $port;
    $out = [];
    foreach (file($kh, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 3) {
            continue;
        }
        $match = false;
        foreach (explode(',', $parts[0]) as $hn) {
            if ($hn === $name) {
                $match = true;
            } elseif (str_starts_with($hn, '|1|')) {
                [, , $salt, $hash] = array_pad(explode('|', $hn), 4, '');
                $match = $match || hash_equals((string)base64_decode($hash), hash_hmac('sha1', $name, (string)base64_decode($salt), true));
            }
        }
        if ($match && ($raw = base64_decode($parts[2], true)) !== false) {
            $out[$parts[1]] = 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, true)), '=');
        }
    }
    return $out;
}

function action_ssh_exec(): void
{
    if (!setting('ssh.exec_enabled')) {
        json_out(['error' => t('SSH-Befehle sind deaktiviert.')], 403);
    }
    [$net, $h] = json_host();
    $command = in_str('command', 1000);
    $user = in_str('ssh_user', 64) ?: (string)($h['ssh_user'] ?: setting('ssh.default_user', 'root'));
    [$allowed, $why] = ssh_command_allowed($command);
    if (!$allowed || !ssh_valid_user($user)) {
        audit('ssh_exec_denied', ['befehl' => $command, 'benutzer' => $user, 'grund' => $allowed ? 'Benutzername ungültig' : $why], null, 'denied', host_ctx($net, $h));
        json_out(['error' => $allowed ? t('SSH-Benutzer ungültig.') : $why], 403);
    }
    if (($p = ssh_ready()) !== []) {
        json_out(['error' => implode(' · ', $p)], 400);
    }
    $t = host_target($h);
    $ip = $t !== null ? net_resolve($t) : null;
    if ($ip === null) {
        json_out(['error' => t('Adresse nicht auflösbar.')], 400);
    }
    session_write_close();
    $port = (int)($h['ssh_port'] ?: 22);
    $r = ssh_exec($ip, $port, $user, $command);
    audit('ssh_exec', [
        'ziel' => $user . '@' . $ip . ':' . $port, 'befehl' => $command, 'freigabe' => $why, 'exit' => $r['exit'], 'dauer_ms' => $r['ms'],
        'stdout_bytes' => strlen($r['stdout']), 'stdout_sha256' => hash('sha256', $r['stdout']), 'stdout_anfang' => mb_substr($r['stdout'], 0, 1500),
        'stderr' => mb_substr($r['stderr'], 0, 500),
    ], null, $r['exit'] === 0 ? 'ok' : 'fail', host_ctx($net, $h));
    json_out(['exit' => $r['exit'], 'stdout' => $r['stdout'], 'stderr' => $r['stderr'], 'ms' => $r['ms'], 'target' => $user . '@' . $ip]);
}
