<?php
/**
 * Seite „Diagnose & Debug“ (nur Portal-Admins): Selbsttest aller Funktionen,
 * Debug-Protokoll, Bericht zum Herunterladen.
 */

declare(strict_types=1);

function page_diagnose(): void
{
    $t0 = microtime(true);
    $S = diag_run(true);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $c = diag_summary($S);
    audit('sys_diagnose', ['ergebnis' => $c, 'dauer_ms' => $ms], null, $c['fail'] ? 'warn' : 'ok');
    page_start(t('Diagnose & Debug'), 'diagnose', [[t('Diagnose & Debug'), null]]);
    heading(t('Diagnose & Debug'), th('Selbsttest aller Funktionen des Portals · {ms} ms', ['ms' => $ms]),
        btn(t('Erneut prüfen'), url(['p' => 'diagnose']), 'secondary', 'check') . btn(t('Bericht herunterladen'), url(['a' => 'diag_report']), 'secondary', 'download'));
    echo '<div class="stat-grid">'
        . stat_tile(t('In Ordnung'), (string)$c['ok'], t('Prüfungen'), 'check', 'ok')
        . stat_tile(t('Warnungen'), (string)$c['warn'], t('sollten angesehen werden'), 'clock', $c['warn'] ? 'warn' : 'ok')
        . stat_tile(t('Fehler'), (string)$c['fail'], t('Funktion eingeschränkt'), 'x', $c['fail'] ? 'bad' : 'ok')
        . stat_tile(t('Hinweise'), (string)$c['info'], t('ausgeschaltet oder optional'), 'list')
        . '</div>';
    echo '<div class="filter-bar diag-filter"><label class="check"><input type="checkbox" id="diagOnlyProblems"><span>' . th('nur Warnungen und Fehler zeigen') . '</span></label></div>';
    echo '<div class="diag-grid">';
    foreach ($S as $sec => $checks) {
        $worst = in_array('fail', array_column($checks, 'status'), true) ? 'fail' : (in_array('warn', array_column($checks, 'status'), true) ? 'warn' : 'ok');
        echo '<section class="card diag-card ' . $worst . '"><h2 class="section-label">' . h($sec) . '</h2><ul class="diag-list">';
        foreach ($checks as $ch) {
            $ic = ['ok' => 'check', 'warn' => 'clock', 'fail' => 'x', 'info' => 'list'][$ch['status']] ?? 'list';
            echo '<li class="diag-item ' . h($ch['status']) . '">' . icon($ic, 14) . '<div><div class="diag-label">' . h($ch['label']) . '<span class="diag-ms">' . (int)$ch['ms'] . ' ms</span></div>'
                . '<div class="diag-detail">' . h($ch['detail']) . '</div>'
                . ($ch['status'] !== 'ok' && $ch['hint'] !== '' ? '<div class="diag-hint">→ ' . h($ch['hint']) . '</div>' : '') . '</div></li>';
        }
        echo '</ul></section>';
    }
    echo '</div>';

    // Debug-Protokoll
    $on = debug_enabled();
    $log = debug_log_tail(300);
    echo '<h2 class="section-label spaced">' . th('Debug-Protokoll') . '<span class="section-actions">'
        . post_button($on ? t('Debug-Modus ausschalten') : t('Debug-Modus einschalten'), ['a' => 'diag_debug'], $on ? 'danger small' : 'primary small', '', 'pulse', ['on' => $on ? '0' : '1'])
        . ($log !== [] ? post_button(t('Protokoll leeren'), ['a' => 'diag_clearlog'], 'ghost small', t('Debug-Protokoll leeren?'), 'trash') : '')
        . '</span></h2>';
    echo '<section class="card"><p class="hint">' . ($on
        ? th('Debug-Modus ist AN: jede Anfrage (Route, Status, Laufzeit, Speicher, Benutzer), PHP-Warnungen und JavaScript-Fehler aus dem Browser werden in .cache/debug.log geschrieben. Passwörter und Schlüssel werden nie protokolliert.')
        : th('Debug-Modus ist aus. Einschalten, den Fehler nachstellen und hier ansehen – danach wieder ausschalten.')) . '</p>';
    if ($log === []) {
        echo '<p class="dim">' . th('Keine Einträge.') . '</p>';
    } else {
        echo '<div class="filter-bar"><select id="debugType" aria-label="' . th('Typ') . '"><option value="">' . th('Alle Typen') . '</option><option value="request">' . th('Anfragen') . '</option><option value="php">' . th('PHP-Meldungen') . '</option><option value="js">' . th('Browser-Fehler') . '</option><option value="slow">' . th('langsam (> 1 s)') . '</option><option value="err">' . th('Status ≥ 400') . '</option></select></div>';
        echo '<div class="table-wrap"><table class="data-table compact debug-table" id="debugTable"><thead><tr><th>' . th('Zeitpunkt') . '</th><th>' . th('Typ') . '</th><th>' . th('Meldung / Route') . '</th><th>Status</th><th>ms</th><th>' . th('Benutzer') . '</th></tr></thead><tbody>';
        foreach ($log as $e) {
            $type = (string)($e['type'] ?? '');
            $status = (int)($e['status'] ?? 0);
            $msms = (int)($e['ms'] ?? 0);
            $msg = match ($type) {
                'request' => ($e['method'] ?? '') . ' ' . ($e['route'] ?? '') . (isset($e['fatal']) ? ' · FATAL ' . $e['fatal'] : '') . ' · ' . ($e['mem'] ?? ''),
                'php' => strtoupper((string)($e['level'] ?? '')) . ': ' . ($e['msg'] ?? '') . ' @ ' . ($e['file'] ?? ''),
                'js' => ($e['msg'] ?? '') . ' @ ' . ($e['src'] ?? '') . ' · ' . ($e['page'] ?? ''),
                default => json_encode($e, JSON_UNESCAPED_UNICODE),
            };
            $cls = $type === 'request' && $status < 400 && !isset($e['fatal']) ? '' : 'bad-row';
            echo '<tr class="' . $cls . '" data-type="' . h($type) . '" data-slow="' . ($msms > 1000 ? '1' : '0') . '" data-err="' . ($status >= 400 || $type !== 'request' || isset($e['fatal']) ? '1' : '0') . '">'
                . '<td class="mono small">' . h((string)($e['ts'] ?? '')) . '</td><td><span class="badge dim">' . h($type) . '</span></td>'
                . '<td class="mono small break">' . h($msg) . '</td><td class="mono">' . ($status ?: '') . '</td><td class="mono">' . ($type === 'request' ? $msms : '') . '</td>'
                . '<td class="small">' . h((string)($e['user'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    page_end();
}

function action_diag_report(): void
{
    $txt = diag_report(diag_run(true));
    audit('sys_diagnose', ['bericht' => 'heruntergeladen', 'sha256' => hash('sha256', $txt)]);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="netportal-diagnose-' . date('Ymd-His') . '.txt"');
    header('Cache-Control: no-store');
    echo $txt;
}

function action_diag_debug(): void
{
    $on = in_bool('on');
    $all = settings_all();
    $old = (bool)($all['app']['debug'] ?? false);
    $all['app']['debug'] = $on;
    settings_save($all);
    audit('settings_update', ['aenderungen' => ['app.debug' => [$old, $on]]]);
    flash($on ? t('Debug-Modus eingeschaltet.') : t('Debug-Modus ausgeschaltet.'));
    redirect(url(['p' => 'diagnose']) . '#debugTable');
}

function action_diag_clearlog(): void
{
    @unlink(DEBUG_LOG);
    @unlink(DEBUG_LOG . '.1');
    audit('sys_diagnose', ['debug_protokoll' => 'geleert']);
    flash(t('Debug-Protokoll geleert.'));
    redirect(url(['p' => 'diagnose']));
}

/** JavaScript-Fehler aus dem Browser (nur im Debug-Modus). */
function action_diag_clientlog(): void
{
    if (!debug_enabled()) {
        json_out(['ok' => false]);
    }
    $n = (int)($_SESSION['diag_js'] ?? 0);
    if ($n > 50) {
        json_out(['ok' => false]);
    }
    $_SESSION['diag_js'] = $n + 1;
    debug_log(['type' => 'js', 'msg' => mb_substr(in_str('msg', 500), 0, 500), 'src' => mb_substr(in_str('src', 300), 0, 300) . ':' . in_int('line') . ':' . in_int('col'),
        'page' => mb_substr(in_str('page', 300), 0, 300), 'user' => auth_user_soft()['username'] ?? null, 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)]);
    json_out(['ok' => true]);
}
