<?php
/**
 * Integritätsprüfung: je Eintrag (Zeilen-HMAC), je Datei (HMAC + Siegel) für alle
 * Netzwerk-Dateien und users.sql, Bestand, Audit-Kette, Programmdateien.
 * Neu versiegeln nur durch Portal-Admins, mit Begründung und Audit-Eintrag.
 */

declare(strict_types=1);

function integrity_file_card(array $n, string $target, string $title, string $sub, ?string $href): string
{
    $o = '<section class="card integ-card' . ($n['ok'] ? '' : ' bad-card') . '" id="net-' . h(ltrim($target, '@')) . '"><div class="integ-head">'
        . icon($n['ok'] ? 'shield' : 'shield-x', 20, $n['ok'] ? 'txt-ok' : 'txt-bad')
        . '<div><div class="cell-title">' . ($href ? '<a href="' . h($href) . '">' . h($title) . '</a>' : h($title)) . '</div><div class="cell-sub mono">' . h($sub) . (isset($n['bytes']) ? ' · ' . h(fmt_bytes((int)$n['bytes'])) : '') . '</div></div>'
        . '<span class="badge ' . ($n['ok'] ? 'ok-soft' : 'bad') . '">' . ($n['ok'] ? 'OK' : th('verletzt')) . '</span></div><ul class="checks">';
    foreach ($n['checks'] as $c) {
        $o .= '<li class="' . ($c['ok'] ? 'ok' : 'bad') . '">' . icon($c['ok'] ? 'check' : 'x', 13) . '<span>' . h($c['label']) . '</span><em>' . h($c['detail']) . '</em></li>';
    }
    $o .= '</ul>';
    if ($n['bad_rows'] !== []) {
        $o .= '<table class="mini-table"><thead><tr><th>' . th('Tabelle') . '</th><th>ID</th><th>' . th('Eintrag') . '</th><th>' . th('Hash gespeichert') . '</th><th>' . th('Hash erwartet') . '</th></tr></thead><tbody>';
        foreach (array_slice($n['bad_rows'], 0, 200) as $b) {
            $link = $b['table'] === 'hosts' && $target[0] !== '@' ? '<a href="' . h(url(['p' => 'host', 'n' => $target, 'id' => $b['id']])) . '">' . h($b['label']) . '</a>' : h($b['label']);
            $o .= '<tr><td class="mono">' . h($b['table']) . '</td><td class="mono">' . (int)$b['id'] . '</td><td>' . $link . '</td><td class="mono small old">' . h(substr($b['found'], 0, 16)) . '…</td><td class="mono small new">' . h(substr($b['expected'], 0, 16)) . '…</td></tr>';
        }
        $o .= '</tbody></table>';
    }
    if (!$n['ok'] && can('reseal')) {
        $o .= '<details class="sub"><summary>' . th('Abweichung geprüft – neu versiegeln …') . '</summary>'
            . '<form method="post" action="' . h(url(['a' => 'seal_renew'])) . '" class="form-grid" autocomplete="off">' . csrf_field() . '<input type="hidden" name="n" value="' . h($target) . '">'
            . f_text('reason', t('Begründung (Pflicht, steht im Audit-Log)'), '', ['required' => true, 'maxlength' => 500, 'class' => 'span2', 'placeholder' => t('z. B. Datei aus Sicherung vom … zurückgespielt')])
            . '<div class="field span2">' . f_check('rows', t('Auch ungültige Zeilen-Hashes neu berechnen'), false, th('übernimmt die geänderten Inhalte als gültig – nur wenn sie geprüft und korrekt sind')) . '</div>'
            . '<div class="span2"><button class="pill-btn danger" type="submit" data-confirm="' . th('Datei wirklich neu versiegeln? Der aktuelle Inhalt gilt danach als echt.') . '">' . icon('shield', 15) . th('Neu versiegeln') . '</button></div></form></details>';
    }
    return $o . '</section>';
}

function page_integrity(): void
{
    $t0 = microtime(true);
    $r = integrity_run_all();
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $only = get_str('n', 60);
    $badNets = array_filter($r['nets'], static fn($n) => !$n['ok']);
    audit('integrity_check', ['ok' => $r['ok'], 'netze' => count($r['nets']), 'netze_fehlerhaft' => array_keys($badNets), 'users_sql' => $r['users']['ok'],
        'weitere' => array_map(static fn($x) => $x['ok'], $r['stores']),
        'ohne_siegel' => $r['inventory']['unsealed'], 'fehlende_dateien' => $r['inventory']['missing'], 'audit_kette' => $r['audit']['message'],
        'programm' => $r['code']['ok'], 'dauer_ms' => $ms], null, $r['ok'] ? 'ok' : 'warn');
    if (!$r['ok']) {
        audit('integrity_violation', ['netze' => array_map(static fn($n) => ['pruefungen' => array_values(array_filter($n['checks'], static fn($c) => !$c['ok'])), 'zeilen' => $n['bad_rows']], $badNets),
            'users_sql' => $r['users']['ok'] ? null : ['pruefungen' => array_values(array_filter($r['users']['checks'], static fn($c) => !$c['ok'])), 'zeilen' => $r['users']['bad_rows']],
            'bestand' => $r['inventory'], 'audit' => $r['audit']['ok'] ? null : $r['audit']['message'],
            'programm' => $r['code']['ok'] === false ? ['geaendert' => $r['code']['changed'], 'fehlt' => $r['code']['missing'], 'neu' => $r['code']['extra']] : null], null, 'warn');
    }

    page_start(t('Integrität'), 'integrity', [[t('Integrität'), null]]);
    heading(t('Integritätsprüfung'), th('Hash je Eintrag · Hash je Datei · Siegel · Audit-Kette · Programmdateien · {ms} ms', ['ms' => $ms]), btn(t('Erneut prüfen'), url(['p' => 'integrity']), 'secondary', 'shield'));
    $m = $r['masters'];
    $stores = $r['stores'];
    $rowsTotal = array_sum(array_column($r['nets'], 'rows_total')) + $r['users']['rows_total'] + ($m['rows_total'] ?? 0) + array_sum(array_column($stores, 'rows_total'));
    $rowsOk = array_sum(array_column($r['nets'], 'rows_ok')) + $r['users']['rows_ok'] + ($m['rows_ok'] ?? 0) + array_sum(array_column($stores, 'rows_ok'));
    $files = count($r['nets']) + 1 + ($m !== null ? 1 : 0) + count($stores);
    $filesOk = $files - count($badNets) - ($r['users']['ok'] ? 0 : 1) - ($m !== null && !$m['ok'] ? 1 : 0) - count(array_filter($stores, static fn($x) => !$x['ok']));
    echo '<div class="stat-grid">'
        . stat_tile(t('Gesamt'), $r['ok'] ? 'OK' : t('VERLETZT'), $r['ok'] ? t('keine Abweichung gefunden') : t('Details unten'), $r['ok'] ? 'shield' : 'shield-x', $r['ok'] ? 'ok' : 'bad')
        . stat_tile(t('Einträge'), $rowsOk . ' / ' . $rowsTotal, t('gültige Zeilen-Hashes'), 'list', $rowsOk === $rowsTotal ? 'ok' : 'bad')
        . stat_tile(t('Dateien'), $filesOk . ' / ' . $files, t('SQL-Dateien in Ordnung (inkl. users.sql)'), 'db', $filesOk === $files ? 'ok' : 'bad')
        . stat_tile(t('Audit-Kette'), $r['audit']['ok'] ? t('lückenlos') : t('gebrochen'), t('{n} Einträge', ['n' => fmt_num($r['audit']['count'])]), 'list', $r['audit']['ok'] ? 'ok' : 'bad')
        . '</div>';

    echo '<section class="card"><h2 class="section-label">' . th('Schlüssel') . '</h2>' . kv_list([
        t('Fingerprint') => '<span class="mono">' . h(integrity_key_fingerprint()) . '</span>',
        t('Erzeugt') => h(fmt_datetime(meta_get('key_created'))),
        t('Aufbewahrung') => th('Teil 1 in database/_system.sqlite, Teil 2 in config/integrity.key – getrennt sichern'),
        t('Verfahren') => th('HMAC-SHA256 (Zeilen, Dateien, Audit-Kette) · SHA-256 (Siegel, Programmdateien)'),
    ]) . '</section>';

    if ($r['inventory']['unsealed'] !== [] || $r['inventory']['missing'] !== []) {
        echo '<section class="card bad-card"><h2 class="section-label">' . th('Bestand') . '</h2><ul class="checks">';
        foreach ($r['inventory']['unsealed'] as $f) {
            echo '<li class="bad">' . icon('x', 13) . '<span class="mono">database/' . h($f) . '</span><em>' . th('liegt im Ordner, wurde aber nie vom Portal versiegelt (fremd eingelegt oder Siegel gelöscht)') . '</em></li>';
        }
        foreach ($r['inventory']['missing'] as $f) {
            echo '<li class="bad">' . icon('x', 13) . '<span class="mono">database/' . h($f) . '</span><em>' . th('versiegelt, aber die Datei fehlt (am Portal vorbei gelöscht/umbenannt)') . '</em></li>';
        }
        echo '</ul></section>';
    }

    echo '<h2 class="section-label spaced">' . th('Benutzer, Master, Switches und WAN') . '</h2>';
    echo integrity_file_card($r['users'], '@users', t('Benutzer des Portals'), 'database/users.sql', can('users') ? url(['p' => 'users']) : null);
    if ($m !== null) {
        echo integrity_file_card($m, '@masters', t('Master-Vorlagen'), 'database/masters.sql', url(['p' => 'masters']));
    }
    foreach ($stores as $key => $sr) {
        echo integrity_file_card($sr, '@' . $key, $sr['name'], 'database/' . $sr['file'], url(['p' => $key === 'wan' ? 'wans' : $key]));
    }

    echo '<h2 class="section-label spaced">' . th('Netzwerk-Dateien') . '</h2>';
    if ($r['nets'] === []) {
        echo '<p class="dim">' . th('Keine Netzwerke vorhanden.') . '</p>';
    }
    foreach ($r['nets'] as $slug => $n) {
        if ($only === '' || $only === $slug) {
            echo integrity_file_card($n, $slug, $n['name'], 'database/net-' . $slug . '.sql', url(['p' => 'net', 'n' => $slug]));
        }
    }

    echo '<h2 class="section-label spaced">' . th('Audit-Log') . '</h2><section class="card' . ($r['audit']['ok'] ? '' : ' bad-card') . '"><ul class="checks"><li class="' . ($r['audit']['ok'] ? 'ok' : 'bad') . '">'
        . icon($r['audit']['ok'] ? 'check' : 'x', 13) . '<span>' . th('Hash-Kette') . '</span><em>' . h($r['audit']['message']) . '</em></li></ul></section>';

    $c = $r['code'];
    echo '<h2 class="section-label spaced">' . th('Programmdateien') . '</h2><section class="card' . ($c['ok'] === false ? ' bad-card' : '') . '"><ul class="checks"><li class="' . ($c['ok'] === null ? 'warn' : ($c['ok'] ? 'ok' : 'bad')) . '">'
        . icon($c['ok'] ? 'check' : 'x', 13) . '<span>manifest.sha256</span><em>' . h($c['detail']) . '</em></li>';
    foreach (['changed' => t('verändert'), 'missing' => t('fehlt'), 'extra' => t('neu (nicht im Manifest)')] as $k => $label) {
        foreach ($c[$k] as $f) {
            echo '<li class="bad">' . icon('x', 13) . '<span class="mono">' . h($f) . '</span><em>' . h($label) . '</em></li>';
        }
    }
    echo '</ul>';
    if (can('reseal')) {
        echo '<div class="card-foot">' . post_button(t('Manifest neu schreiben'), ['a' => 'manifest_write'], 'secondary', t('Den aktuellen Stand aller Programmdateien als gültig übernehmen (z. B. nach einem Update)?'), 'file') . '</div>';
    }
    echo '</section>';
    page_end();
}

function action_seal_renew(): void
{
    $target = in_str('n', 60);
    $reason = in_str('reason', 500);
    $valid = match ($target) {
        '@users' => is_file(users_file()),
        '@masters' => is_file(masters_file()),
        '@switches', '@wan' => is_file(store_file(substr($target, 1))),
        default => net_valid_slug($target) && is_file(net_file($target)),
    };
    if (!$valid) {
        flash(t('Datei nicht gefunden.'), 'error');
        redirect(url(['p' => 'integrity']));
    }
    if (mb_strlen($reason) < 5) {
        flash(t('Bitte eine Begründung angeben.'), 'error');
        redirect(url(['p' => 'integrity']));
    }
    $after = integrity_reseal($target, in_bool('rows'), $reason);
    flash($after['ok'] ? t('Datei neu versiegelt – Integrität wieder hergestellt.') : t('Datei versiegelt, es bleiben Abweichungen (z. B. Zeilen-Hashes).'), $after['ok'] ? 'ok' : 'warn');
    redirect(url(['p' => 'integrity']) . '#net-' . ltrim($target, '@'));
}

function action_manifest_write(): void
{
    $before = integrity_check_code();
    $n = integrity_write_manifest();
    audit('seal_renew', ['manifest' => $n . ' Dateien', 'vorher' => ['geaendert' => $before['changed'], 'fehlt' => $before['missing'], 'neu' => $before['extra']]]);
    flash(t('Manifest mit {n} Programmdateien geschrieben.', ['n' => $n]));
    redirect(url(['p' => 'integrity']));
}

function action_integrity_run(): void
{
    json_out(['ok' => integrity_run_all()['ok']]);
}
