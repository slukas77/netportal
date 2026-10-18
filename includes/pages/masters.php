<?php
/**
 * Master: Vorlage anlegen, Netzwerke abhaken, Einträge automatisch auf der
 * ersten (bzw. gewählten) IP jedes Netzwerks anlegen und abgleichen.
 */

declare(strict_types=1);

function master_or_404(int $id): array
{
    $m = master_get($id);
    if ($m === null) {
        render_error_page(t('Nicht gefunden'), t('Master nicht gefunden.'), 404);
        exit;
    }
    return $m;
}

function master_summary(array $plan): string
{
    $count = [];
    foreach ($plan as $r) {
        if ($r['state'] !== 'none') {
            $count[$r['state']] = ($count[$r['state']] ?? 0) + 1;
        }
    }
    if ($count === []) {
        return '<span class="dim">' . th('noch keinem Netzwerk zugeordnet') . '</span>';
    }
    $o = '';
    foreach ($count as $st => $n) {
        $o .= '<span class="badge ' . master_state_class($st) . '">' . h($n . ' × ' . master_state_label($st)) . '</span> ';
    }
    return $o;
}

function page_masters(): void
{
    $list = masters_list();
    page_start(t('Master'), 'masters', [[t('Master'), null]]);
    heading(t('Master'), th('Ein Gerät, das in jedem ausgewählten Netzwerk automatisch auf der ersten IP eingetragen wird.'),
        can('edit') ? btn(t('Neuer Master'), url(['p' => 'master_edit']), 'primary', 'plus') : '');
    if ($list === []) {
        echo '<div class="empty-state">' . icon('crown', 40) . '<p>' . th('Noch kein Master angelegt.') . '</p>'
            . '<p class="hint">' . th('Beispiel: Gerät „OPNsense“ eingeben und die Netzwerke auswählen – es wird in jedem davon auf der ersten IP eingetragen (z. B. 10.0.20.1).') . '</p>'
            . (can('edit') ? btn(t('Ersten Master anlegen'), url(['p' => 'master_edit']), 'primary', 'plus') : '') . '</div>';
        page_end();
        return;
    }
    echo '<div class="master-list">';
    foreach ($list as $m) {
        $mid = (int)$m['id'];
        $svcs = master_services($mid);
        $plan = master_plan($m, $svcs, master_nets($mid));
        $ok = sqlfile_row_valid(MASTERS_NS, 'masters', $m);
        echo '<section class="card master-card"><div class="master-head"><div class="station-cover">' . host_icon_html($m, 26) . '</div><div class="master-title">'
            . '<a href="' . h(url(['p' => 'master_edit', 'id' => $mid])) . '"><strong>' . h($m['name']) . '</strong></a>'
            . '<div class="cell-sub">' . ((string)$m['hostname'] !== '' ? '<span class="mono">' . h($m['hostname']) . '</span> · ' : '') . h(master_rule_label($m)) . ($m['kind'] ? ' · ' . th((string)$m['kind']) : '')
            . ((int)$m['auto_new'] === 1 ? ' · <span class="badge state-unknown">' . th('auch für neue Netzwerke') . '</span>' : '')
            . ($ok ? '' : ' <span class="badge bad">' . th('Eintrag manipuliert') . '</span>') . '</div></div>'
            . '<div class="master-acts">'
            . (can('edit') ? btn(t('Bearbeiten'), url(['p' => 'master_edit', 'id' => $mid]), 'secondary', 'edit')
                . post_button(t('Jetzt abgleichen'), ['a' => 'master_sync'], 'secondary', '', 'check', ['id' => $mid]) : '')
            . '</div></div>'
            . '<div class="master-body"><div>' . implode('', array_map(static fn($s) => '<span class="svc-chip' . ((int)($s['status_check'] ?? 1) === 0 ? ' nocheck' : '') . '">' . h($s['name']) . '</span>', $svcs)) . '</div>'
            . '<div class="master-state">' . master_summary($plan) . '</div></div>';
        $rows = array_filter($plan, static fn($r) => $r['state'] !== 'none');
        if ($rows !== []) {
            echo '<div class="table-wrap"><table class="data-table compact"><thead><tr><th>' . th('Netzwerk') . '</th><th>' . th('Netz (CIDR)') . '</th><th>' . th('IP des Geräts') . '</th><th>' . th('Zustand') . '</th></tr></thead><tbody>';
            foreach ($rows as $slug => $r) {
                echo '<tr><td><a href="' . h(url(['p' => 'net', 'n' => $slug])) . '">' . h($r['name']) . '</a>' . ($r['vlan_id'] !== null ? ' <span class="vlan-tag">' . (int)$r['vlan_id'] . '</span>' : '') . '</td>'
                    . '<td class="mono">' . h($r['cidr']) . '</td><td class="mono">' . h((string)$r['ip']) . '</td>'
                    . '<td><span class="badge ' . master_state_class($r['state']) . '">' . h(master_state_label($r['state'])) . '</span>' . ($r['detail'] !== '' ? ' <span class="dim small">' . h($r['detail']) . '</span>' : '') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }
    echo '</div>';
    page_end();
}

function master_svc_row(string $i, array $s): string
{
    return '<div class="repeat-row svc-row" data-repeat-row><input type="hidden" name="svc[' . $i . '][id]" value="' . h((string)($s['id'] ?? '')) . '">'
        . f_text('svc[' . $i . '][name]', t('Dienst'), (string)($s['name'] ?? ''), ['maxlength' => 80, 'placeholder' => 'HTTPS'])
        . f_select('svc[' . $i . '][proto]', t('Protokoll'), ['tcp' => 'TCP', 'udp' => 'UDP'], (string)($s['proto'] ?? 'tcp'))
        . f_text('svc[' . $i . '][port]', 'Port', (string)($s['port'] ?? ''), ['inputmode' => 'numeric', 'maxlength' => 5])
        . f_text('svc[' . $i . '][url]', t('Adresse (URL)'), (string)($s['url'] ?? ''), ['maxlength' => 300, 'placeholder' => 'https://{ip}/'])
        . '<label class="check svc-check"><input type="checkbox" name="svc[' . $i . '][status_check]" value="1"' . ((int)($s['status_check'] ?? 1) === 1 ? ' checked' : '') . '><span>' . th('Statusprüfung') . '</span></label>'
        . '<button type="button" class="icon-btn" data-repeat-del title="' . th('Zeile entfernen') . '" aria-label="' . th('Zeile entfernen') . '">' . icon('x', 15) . '</button></div>';
}

function page_master_edit(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $m = $id > 0 ? master_or_404($id) : array_fill_keys(array_keys(MASTERS_SCHEMA['masters']), null);
    if ($id === 0) {
        $m = array_merge($m, ['id' => 0, 'name' => '', 'hostname' => '', 'kind' => 'Router', 'monitor' => 1, 'ip_rule' => 'first', 'ip_offset' => 1, 'auto_new' => 0]);
    }
    if (isset($_SESSION['form_master']) && is_array($_SESSION['form_master'])) {
        [$m, $svcsForm, $netsForm] = $_SESSION['form_master'];
        unset($_SESSION['form_master']);
    }
    $svcs = $svcsForm ?? ($id > 0 ? master_services($id) : []);
    $ticked = $netsForm ?? ($id > 0 ? master_nets($id) : []);
    $title = $id > 0 ? t('Master bearbeiten') : t('Neuer Master');
    page_start($title, 'masters', [[t('Master'), url(['p' => 'masters'])], [$id > 0 ? (string)$m['name'] : $title, null]]);
    heading($title, th('Gerät eingeben, Netzwerke auswählen – das Gerät wird in jedem ausgewählten Netzwerk auf der ersten IP eingetragen.'));

    $kinds = ['' => '–'];
    foreach (HOST_KINDS as $k) {
        $kinds[$k] = t($k);
    }
    echo '<form class="form-layout" method="post" enctype="multipart/form-data" action="' . h(url(['a' => 'master_save'])) . '" autocomplete="off" id="masterForm">' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int)$m['id'] . '">';

    // 1. Gerät
    echo '<section class="card"><h2 class="section-label"><span class="step-no">1</span>' . th('Gerät') . '</h2><div class="form-grid cols-4">'
        . f_text('name', t('Gerät') . ' *', (string)$m['name'], ['required' => true, 'maxlength' => 80, 'class' => 'span2', 'placeholder' => t('z. B. OPNsense, Gateway, DNS-Server'), 'id' => 'masterName',
            'hint' => th('So heißt der Eintrag in jedem ausgewählten Netzwerk.')])
        . f_select('kind', t('Typ'), $kinds, (string)$m['kind'])
        . f_text('os', t('Betriebssystem / Firmware'), (string)$m['os'], ['maxlength' => 100, 'list' => 'osList'])
        . '</div></section>';

    // 2. Netzwerke
    $plan = $id > 0 ? master_plan($m, master_services($id), master_nets($id)) : [];
    echo '<section class="card"><h2 class="section-label"><span class="step-no">2</span>' . th('Netzwerke auswählen') . '<span class="section-actions"><label class="check"><input type="checkbox" id="masterAll"><span>' . th('alle') . '</span></label></span></h2>'
        . '<p class="hint">' . th('In jedem ausgewählten Netzwerk wird das Gerät auf der ersten IP eingetragen. Abwählen entfernt den Eintrag wieder.') . '</p>';
    $nets = net_list();
    if ($nets === []) {
        echo '<p class="dim">' . th('Noch keine Netzwerke angelegt.') . '</p>';
    } else {
        echo '<div class="table-wrap"><table class="data-table compact master-nets"><thead><tr><th class="col-dot"></th><th>' . th('Netzwerk') . '</th><th class="mn-cidr">' . th('Netz (CIDR)') . '</th><th>' . th('IP des Geräts') . '</th><th>' . th('Zustand') . '</th></tr></thead><tbody>';
        foreach ($nets as $slug => $meta) {
            try {
                $idx = net_host_index($slug);
            } catch (Throwable $e) {
                continue;
            }
            $n = $idx['network'];
            $info = cidr_info($n['cidr']);
            // nur die möglichen Positionen (erste/letzte IP, Gateway, Versatz) mitgeben – nicht alle Einträge
            $cand = [];
            foreach ([$info['first'] ?? null, $info['last'] ?? null, $n['gateway'] !== '' ? $n['gateway'] : null,
                $info !== null && empty($info['v6']) ? ip_add((string)$info['network'], max(1, (int)($m['ip_offset'] ?? 1))) : null] as $c) {
                if ($c !== null && $c !== '') {
                    $cand[(string)$c] = '';
                }
            }
            $linked = false;
            foreach ($idx['hosts'] as $h) {
                if ($id > 0 && $h['master_id'] === $id) {
                    $linked = true;
                    continue;
                }
                if ($h['ip'] !== '' && isset($cand[$h['ip']])) {
                    $cand[$h['ip']] = $h['hostname'];
                }
            }
            $used = $cand;
            $p = $plan[$slug] ?? null;
            echo '<tr data-first="' . h((string)($info['first'] ?? '')) . '" data-last="' . h((string)($info['last'] ?? '')) . '" data-gw="' . h((string)$n['gateway']) . '"'
                . ' data-network="' . h((string)($info['network'] ?? '')) . '" data-broadcast="' . h((string)($info['broadcast'] ?? '')) . '" data-v6="' . (!empty($info['v6']) ? '1' : '0') . '"'
                . ' data-linked="' . ($linked ? '1' : '0') . '" data-used="' . h((string)json_encode($used, JSON_UNESCAPED_UNICODE)) . '">'
                . '<td class="col-dot"><input type="checkbox" name="nets[]" value="' . h($slug) . '"' . (in_array($slug, $ticked, true) ? ' checked' : '') . ' aria-label="' . h($n['name']) . '"></td>'
                . '<td>' . h($n['name']) . ($n['vlan_id'] !== null ? ' <span class="vlan-tag">' . (int)$n['vlan_id'] . '</span>' : '') . '<div class="cell-sub mono mn-cidr-sub">' . h((string)$n['cidr']) . '</div></td>'
                . '<td class="mono mn-cidr">' . h((string)$n['cidr']) . '</td>'
                . '<td class="mono master-ip">' . h((string)($p['ip'] ?? '')) . '</td>'
                . '<td class="master-st">' . ($p && $p['state'] !== 'none' ? '<span class="badge ' . master_state_class($p['state']) . '">' . h(master_state_label($p['state'])) . '</span>' . ($p['detail'] !== '' ? ' <span class="dim small">' . h($p['detail']) . '</span>' : '') : '<span class="dim">–</span>') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<div class="form-grid cols-4 spaced-top">'
        . '<div class="field check-field span2">' . f_check('adopt', t('Erste IP schon belegt: diesen Eintrag zum Gerät machen'), false, th('sonst wird das Netzwerk übersprungen und als „IP belegt“ gemeldet')) . '</div>'
        . '<div class="field check-field span2">' . f_check('auto_new', t('Auch in neuen Netzwerken automatisch eintragen'), (int)$m['auto_new'] === 1, th('neue Netzwerke werden beim Anlegen sofort ausgewählt')) . '</div>'
        . '</div></section>';

    echo '<div class="form-actions"><button class="pill-btn primary" type="submit">' . icon('check', 15) . th('Speichern und eintragen') . '</button>'
        . btn(t('Abbrechen'), url(['p' => 'masters']), 'ghost') . '</div>';

    // Optional: alles Weitere eingeklappt
    $advOpen = $id > 0 && ((string)$m['ip_rule'] !== 'first' || $svcs !== [] || (string)$m['hostname'] !== '');
    echo '<details class="card master-more"' . ($advOpen ? ' open' : '') . '><summary>' . icon('chevron', 15) . th('Weitere Angaben (optional)') . '</summary>';

    echo '<h3 class="section-label">' . th('Position im Netzwerk') . '</h3><div class="form-grid cols-4">'
        . f_select('ip_rule', t('IP-Adresse des Geräts'), array_map('t', MASTER_RULES), (string)$m['ip_rule'], ['id' => 'masterRule', 'class' => 'span2', 'hint' => th('Standard: erste nutzbare IP, z. B. 10.0.20.1 in 10.0.20.0/24')])
        . f_text('ip_offset', t('Versatz ab Netzadresse'), (string)($m['ip_offset'] ?? 1), ['type' => 'number', 'min' => 1, 'max' => 65535, 'id' => 'masterOffset', 'class' => 'master-offset',
            'hint' => th('z. B. 254 → 10.0.20.254 in einem /24')])
        . f_text('hostname', t('Abweichender Name im Netzwerk'), (string)$m['hostname'], ['maxlength' => 120, 'class' => 'mono-input', 'placeholder' => t('leer = Gerätename'),
            'hint' => th('Platzhalter: {net} Name, {slug} Kurzname, {vlan} VLAN-ID, {ip}, {octet} letzte Stelle der IP')])
        . '</div>';

    echo '<h3 class="section-label spaced-top">' . th('Dienste') . '</h3>'
        . '<p class="hint">' . th('Werden in jedem Netzwerk mit angelegt. In der URL steht {ip} für die IP des Geräts. Von Hand ergänzte Dienste bleiben erhalten.') . '</p>'
        . '<div class="repeat-list" id="svcRepeat" data-repeat>';
    $i = 0;
    foreach ($svcs as $s) {
        echo master_svc_row((string)$i++, $s);
    }
    echo '</div><template id="svcRowTpl">' . master_svc_row('__I__', []) . '</template>'
        . '<button type="button" class="pill-btn ghost small" data-repeat-add="svcRepeat" data-tpl="svcRowTpl">' . icon('plus', 14) . th('Dienst hinzufügen') . '</button>';

    echo '<h3 class="section-label spaced-top">' . th('Details') . '</h3><div class="form-grid cols-4">'
        . f_text('vendor', t('Hersteller'), (string)$m['vendor'], ['maxlength' => 80, 'hint' => th('leer = aus der MAC ermitteln')])
        . f_text('model', t('Modell'), (string)$m['model'], ['maxlength' => 80])
        . f_text('location', t('Standort / Rack'), (string)$m['location'], ['maxlength' => 120])
        . f_text('owner', t('Verantwortlich'), (string)$m['owner'], ['maxlength' => 120])
        . f_text('tags', 'Tags', (string)$m['tags'], ['maxlength' => 200, 'class' => 'span2', 'placeholder' => t('produktiv, backup, …')])
        . f_textarea('description', t('Beschreibung'), (string)$m['description'], 2)
        . '</div>';

    echo '<h3 class="section-label spaced-top">' . th('Zugriff & Überwachung') . '</h3><div class="form-grid cols-4">'
        . f_text('ssh_user', t('SSH-Benutzer'), (string)$m['ssh_user'], ['maxlength' => 64, 'placeholder' => (string)setting('ssh.default_user', 'root')])
        . f_text('ssh_port', t('SSH-Port'), (string)$m['ssh_port'], ['inputmode' => 'numeric', 'maxlength' => 5, 'placeholder' => '22'])
        . f_text('iperf_port', t('iperf3-Port'), (string)$m['iperf_port'], ['inputmode' => 'numeric', 'maxlength' => 5, 'placeholder' => (string)setting('tools.iperf_port', 5201)])
        . '<div class="field check-field">' . f_check('monitor', t('Statusprüfung (Ping)'), (int)($m['monitor'] ?? 1) === 1, th('aus = nur dokumentieren (orange)')) . '</div>'
        . '</div>';

    echo '<h3 class="section-label spaced-top">Icon</h3>' . icon_picker((string)$m['icon']) . '</details>';
    echo '</form>';
    if ($id > 0 && can('delete')) {
        echo '<section class="card danger-zone spaced-top"><h2 class="section-label">' . th('Master löschen') . '</h2><div class="action-row">'
            . post_button(t('Löschen, Einträge in den Netzwerken behalten'), ['a' => 'master_delete'], 'secondary', t('Master „{m}“ löschen? Die Einträge bleiben in den Netzwerken als normale Einträge erhalten.', ['m' => $m['name']]), 'trash', ['id' => $id, 'mode' => 'keep'])
            . post_button(t('Löschen, Einträge überall entfernen'), ['a' => 'master_delete'], 'danger', t('Master „{m}“ und seine Einträge in allen Netzwerken löschen?', ['m' => $m['name']]), 'trash', ['id' => $id, 'mode' => 'remove'])
            . '</div></section>';
    }
    echo '<datalist id="osList">' . implode('', array_map(static fn($o) => '<option value="' . h($o) . '">', ['OPNsense', 'pfSense', 'MikroTik RouterOS 7', 'Debian 13', 'Ubuntu 24.04 LTS', 'Cisco IOS-XE', 'Proxmox VE 9', 'Windows Server 2025'])) . '</datalist>';
    page_end();
}

function master_validate(): array
{
    $err = [];
    $row = [
        'name' => trim(preg_replace('/[\x00-\x1F\x7F]/', '', in_str('name', 80)) ?? ''),
        'hostname' => trim(preg_replace('/[\x00-\x1F\x7F]/', '', in_str('hostname', 120)) ?? ''),
        'kind' => in_array(in_str('kind', 40), HOST_KINDS, true) ? in_str('kind', 40) : null,
        'icon' => in_str('icon', 60),
        'os' => in_str('os', 100), 'vendor' => in_str('vendor', 80), 'model' => in_str('model', 80),
        'location' => in_str('location', 120), 'owner' => in_str('owner', 120),
        'tags' => implode(', ', array_filter(array_map('trim', explode(',', in_str('tags', 200))))),
        'description' => in_str('description', 2000),
        'ssh_user' => in_str('ssh_user', 64), 'ssh_port' => in_str('ssh_port', 5), 'iperf_port' => in_str('iperf_port', 5),
        'monitor' => in_bool('monitor') ? 1 : 0,
        'ip_rule' => isset(MASTER_RULES[in_str('ip_rule', 10)]) ? in_str('ip_rule', 10) : 'first',
        'ip_offset' => max(1, min(65535, in_int('ip_offset', 1))),
        'auto_new' => in_bool('auto_new') ? 1 : 0,
    ];
    if ($row['name'] === '') {
        $err[] = t('Gerät fehlt.');
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
    foreach (masters_list() as $o) {
        if ((int)$o['id'] !== in_int('id') && mb_strtolower((string)$o['name']) === mb_strtolower($row['name'])) {
            $err[] = t('Es gibt bereits einen Master „{m}“.', ['m' => $row['name']]);
        }
    }
    $svcs = [];
    foreach (array_slice((array)(input()['svc'] ?? []), 0, 40) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', mb_substr((string)($s['name'] ?? ''), 0, 80)) ?? '');
        $port = trim((string)($s['port'] ?? ''));
        $url = trim(mb_substr((string)($s['url'] ?? ''), 0, 300));
        if ($name === '' && $port === '' && $url === '') {
            continue;
        }
        if ($name === '') {
            $err[] = t('Name des Dienstes fehlt.');
        }
        if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) {
            $err[] = t('Port muss zwischen 1 und 65535 liegen.');
        }
        if ($url !== '' && !preg_match('#^(https?|ssh|rdp|vnc|smb|ftp|sftp)://\S+$#i', $url)) {
            $err[] = t('Adresse muss mit http://, https://, ssh://, rdp:// … beginnen.');
        }
        $svcs[] = ['id' => ctype_digit((string)($s['id'] ?? '')) ? (int)$s['id'] : null, 'name' => $name, 'proto' => ($s['proto'] ?? '') === 'udp' ? 'udp' : 'tcp',
            'port' => $port === '' ? null : (int)$port, 'url' => $url === '' ? null : $url, 'status_check' => !empty($s['status_check']) ? 1 : 0, 'description' => null];
    }
    $known = array_keys(net_list());
    $nets = array_values(array_intersect(array_map('strval', (array)(input()['nets'] ?? [])), $known));
    return [$row, $svcs, $nets, $err];
}

function action_master_save(): void
{
    $id = in_int('id');
    if ($id > 0) {
        master_or_404($id);
    }
    [$row, $svcs, $nets, $err] = master_validate();
    if (!empty($_FILES['icon_upload']) && ($_FILES['icon_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $row['icon'] = icon_store_upload($_FILES['icon_upload']);
            audit('icon_upload', ['datei' => substr($row['icon'], 2), 'groesse' => (int)$_FILES['icon_upload']['size']], null, 'ok', ['type' => 'master', 'id' => $id ?: null, 'label' => $row['name']]);
        } catch (RuntimeException $e) {
            $err[] = $e->getMessage();
        }
    }
    if ($err !== []) {
        foreach (array_unique($err) as $e) {
            flash($e, 'error');
        }
        $_SESSION['form_master'] = [array_merge(array_fill_keys(array_keys(MASTERS_SCHEMA['masters']), null), $row, ['id' => $id]), $svcs, $nets];
        redirect(url(['p' => 'master_edit', 'id' => $id ?: null]));
    }
    $user = current_actor();
    [$mid, $old, $oldNets] = masters_write(static function (array &$st) use ($id, $row, $svcs, $nets, $user): array {
        $old = $id > 0 ? $st['masters'][$id] : null;
        $data = $row + ['updated_at' => now_str(), 'updated_by' => $user];
        $data = $old ? array_merge($old, $data) : $data + ['created_at' => now_str(), 'created_by' => $user];
        $mid = masters_put($st, 'masters', $data);
        // Dienste
        $keep = [];
        foreach ($svcs as $i => $s) {
            $cur = $s['id'] !== null && isset($st['master_services'][$s['id']]) && (int)$st['master_services'][$s['id']]['master_id'] === $mid ? $st['master_services'][$s['id']] : null;
            $sid = masters_put($st, 'master_services', ['id' => $cur ? $cur['id'] : null, 'master_id' => $mid, 'sort' => $i] + $s);
            $keep[$sid] = true;
        }
        foreach ($st['master_services'] as $sid => $s) {
            if ((int)$s['master_id'] === $mid && !isset($keep[$sid])) {
                unset($st['master_services'][$sid]);
            }
        }
        // Netzwerke
        $oldNets = [];
        foreach ($st['master_nets'] as $nid => $r) {
            if ((int)$r['master_id'] === $mid) {
                $oldNets[] = $r['net'];
                if (!in_array($r['net'], $nets, true)) {
                    unset($st['master_nets'][$nid]);
                }
            }
        }
        foreach (array_diff($nets, $oldNets) as $slug) {
            masters_put($st, 'master_nets', ['master_id' => $mid, 'net' => $slug, 'created_at' => now_str(), 'created_by' => $user]);
        }
        return [$mid, $old, $oldNets];
    });
    $ctx = ['type' => 'master', 'id' => $mid, 'label' => $row['name']];
    if ($old === null) {
        audit('master_create', ['daten' => $row, 'dienste' => $svcs, 'netzwerke' => $nets], null, 'ok', $ctx);
    } else {
        audit('master_update', ['aenderungen' => audit_diff($old, array_merge($old, $row)), 'dienste' => $svcs,
            'netzwerke_neu' => array_values(array_diff($nets, $oldNets)), 'netzwerke_entfernt' => array_values(array_diff($oldNets, $nets))], null, 'ok', $ctx);
    }
    session_write_close();
    @set_time_limit(600); // sehr viele Netzwerke: Abgleich kann dauern
    $res = master_sync($mid, in_bool('adopt'));
    session_start();
    master_flash_results($res, $row['name']);
    redirect(url(['p' => 'masters']));
}

function master_flash_results(array $res, string $name): void
{
    $count = [];
    $problems = [];
    foreach ($res as $slug => $r) {
        $count[$r['state']] = ($count[$r['state']] ?? 0) + 1;
        if (in_array($r['state'], ['conflict', 'nocidr', 'blocked', 'error'], true)) {
            $problems[] = $slug . ': ' . master_state_label($r['state']) . ($r['detail'] !== '' ? ' (' . $r['detail'] . ')' : '');
        }
    }
    $done = [
        'new' => t('{n} angelegt'), 'adopted' => t('{n} übernommen'), 'updated' => t('{n} angepasst'), 'ok' => t('{n} aktuell'), 'removed' => t('{n} entfernt'),
    ];
    $parts = [];
    foreach ($done as $st => $txt) {
        if (!empty($count[$st])) {
            $parts[] = strtr($txt, ['{n}' => (string)$count[$st]]);
        }
    }
    flash(t('Master „{m}“ gespeichert und abgeglichen: {r}.', ['m' => $name, 'r' => $parts !== [] ? implode(' · ', $parts) : t('keine Änderungen')]));
    foreach (array_slice($problems, 0, 8) as $p) {
        flash($p, 'warn');
    }
}

function action_master_sync(): void
{
    $m = master_or_404(in_int('id'));
    session_write_close();
    @set_time_limit(600); // sehr viele Netzwerke: Abgleich kann dauern
    $res = master_sync((int)$m['id'], false);
    session_start();
    master_flash_results($res, (string)$m['name']);
    redirect(url(['p' => 'masters']));
}

function action_master_delete(): void
{
    $m = master_or_404(in_int('id'));
    $mid = (int)$m['id'];
    $remove = in_str('mode', 10) === 'remove';
    session_write_close();
    @set_time_limit(600);
    $detached = 0;
    if ($remove) {
        masters_write(static function (array &$st) use ($mid): void {
            foreach ($st['master_nets'] as $nid => $r) {
                if ((int)$r['master_id'] === $mid) {
                    unset($st['master_nets'][$nid]);
                }
            }
        });
        $res = master_sync($mid);
    } else {
        foreach (array_keys(net_list()) as $slug) {
            try {
                $net = net_load($slug, true);
                $has = array_filter($net['hosts'], static fn($h) => (int)($h['master_id'] ?? 0) === $mid);
                if ($has === []) {
                    continue;
                }
                net_write($slug, static function (array &$n) use ($mid, &$detached): void {
                    foreach ($n['hosts'] as $h) {
                        if ((int)($h['master_id'] ?? 0) === $mid) {
                            net_put($n, 'hosts', array_merge($h, ['master_id' => null]));
                            $detached++;
                            foreach ($n['services'] as $s) {
                                if ((int)$s['host_id'] === (int)$h['id'] && !empty($s['master_svc'])) {
                                    net_put($n, 'services', array_merge($s, ['master_svc' => null]));
                                }
                            }
                        }
                    }
                });
            } catch (Throwable $e) {
                flash($slug . ': ' . $e->getMessage(), 'warn');
            }
        }
        $res = [];
    }
    masters_write(static function (array &$st) use ($mid): void {
        unset($st['masters'][$mid]);
        foreach (['master_services', 'master_nets'] as $t) {
            foreach ($st[$t] as $k => $r) {
                if ((int)$r['master_id'] === $mid) {
                    unset($st[$t][$k]);
                }
            }
        }
    });
    session_start();
    audit('master_delete', ['datensatz' => $m, 'eintraege' => $remove ? 'entfernt' : 'behalten', 'entfernt' => count($res), 'geloest' => $detached], null, 'ok', ['type' => 'master', 'id' => $mid, 'label' => $m['name']]);
    flash(t('Master „{m}“ gelöscht.', ['m' => $m['name']]));
    redirect(url(['p' => 'masters']));
}
