<?php
/**
 * Audit-Log: Filter, Detailansicht (inkl. Feld-Änderungen alt → neu),
 * Prüfung der Hash-Kette, CSV-Export.
 */

declare(strict_types=1);

function audit_filters(): array
{
    return [
        'q' => get_str('q', 100), 'user' => get_str('user', 128), 'cat' => get_str('cat', 40), 'result' => get_str('result', 10),
        'net' => get_str('net', 60), 'oid' => get_str('oid', 20), 'from' => get_str('from', 10), 'to' => get_str('to', 10), 'rid' => get_str('rid', 20),
    ];
}

function audit_where(array $f): array
{
    $w = ['1=1'];
    $p = [];
    if ($f['q'] !== '') {
        $w[] = '(action LIKE ? OR detail LIKE ? OR object_label LIKE ? OR ip LIKE ? OR uri LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like, $like);
    }
    foreach (['user' => 'username', 'cat' => 'category', 'result' => 'result', 'net' => 'net', 'oid' => 'object_id', 'rid' => 'request_id'] as $k => $col) {
        if ($f[$k] !== '') {
            $w[] = $col . ' = ?';
            $p[] = $f[$k];
        }
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
        $w[] = 'ts >= ?';
        $p[] = $f['from'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
        $w[] = 'ts < ?';
        $p[] = date('Y-m-d', strtotime($f['to'] . ' +1 day'));
    }
    return [implode(' AND ', $w), $p];
}

function page_audit(): void
{
    $f = audit_filters();
    [$where, $params] = audit_where($f);
    $total = (int)sys_val("SELECT COUNT(*) FROM audit WHERE $where", $params);
    $per = 100;
    $pages = max(1, (int)ceil($total / $per));
    $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $rows = sys_all("SELECT * FROM audit WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    $full = ($_GET['full'] ?? '') === '1';
    $chain = audit_verify($full);
    $keep = audit_retention_days();

    page_start(t('Audit-Log'), 'audit', [[t('Audit-Log'), null]]);
    heading(t('Audit-Log'), th('{n} Einträge', ['n' => fmt_num($total)]) . ($total !== $chain['count'] ? ' ' . th('(gefiltert, gesamt {n})', ['n' => fmt_num($chain['count'])]) : '')
        . ' · ' . ($keep > 0 ? th('Aufbewahrung {d} Tage, ältere Einträge werden täglich automatisch gelöscht', ['d' => $keep]) : th('Aufbewahrung unbegrenzt'))
        . (can('settings') ? ' · <a href="' . h(url(['p' => 'settings']) . '#auditSection') . '">' . th('ändern') . '</a>' : ''),
        btn(t('CSV-Export'), url(['a' => 'audit_csv'] + $f), 'secondary', 'csv'));
    echo '<div class="flash ' . ($chain['ok'] ? 'flash-ok' : 'flash-error') . '">' . icon($chain['ok'] ? 'shield' : 'shield-x', 15) . ' ' . th('Hash-Kette:') . ' ' . h($chain['message'])
        . ($chain['broken_at'] ? ' · <a href="' . h(url(['p' => 'audit', 'q' => '', 'page' => 1]) . '#a' . $chain['broken_at']) . '">' . th('zur Stelle') . '</a>' : '')
        . (!$full && $chain['ok'] ? ' · <a href="' . h(url(['p' => 'audit', 'full' => '1'])) . '">' . th('komplette Kette prüfen') . '</a>' : '')
        . ($full && $chain['ok'] ? ' · ' . th('komplett geprüft') : '') . '</div>';

    $users = array_column(sys_all('SELECT DISTINCT username FROM audit WHERE username IS NOT NULL ORDER BY username LIMIT 500'), 'username');
    $cats = array_unique(array_values(AUDIT_CATEGORIES));
    sort($cats);
    echo '<form class="filter-bar" method="get" action="./"><input type="hidden" name="p" value="audit">'
        . '<input type="search" name="q" value="' . h($f['q']) . '" placeholder="' . th('Text, IP, Objekt …') . '" class="grow">'
        . '<select name="user"><option value="">' . th('Alle Benutzer') . '</option>' . implode('', array_map(static fn($u) => '<option' . ($f['user'] === $u ? ' selected' : '') . '>' . h($u) . '</option>', $users)) . '</select>'
        . '<select name="cat"><option value="">' . th('Alle Kategorien') . '</option>' . implode('', array_map(static fn($c) => '<option value="' . h($c) . '"' . ($f['cat'] === $c ? ' selected' : '') . '>' . th($c) . '</option>', $cats)) . '</select>'
        . '<select name="result"><option value="">' . th('Alle Ergebnisse') . '</option>' . implode('', array_map(static fn($r) => '<option value="' . $r . '"' . ($f['result'] === $r ? ' selected' : '') . '>' . $r . '</option>', ['ok', 'warn', 'fail', 'denied'])) . '</select>'
        . '<select name="net"><option value="">' . th('Alle Netzwerke') . '</option>' . implode('', array_map(static fn($n) => '<option value="' . h($n['slug']) . '"' . ($f['net'] === $n['slug'] ? ' selected' : '') . '>' . h($n['name']) . '</option>', net_list())) . '</select>'
        . '<input type="date" name="from" value="' . h($f['from']) . '" aria-label="' . th('von') . '"><input type="date" name="to" value="' . h($f['to']) . '" aria-label="' . th('bis') . '">'
        . ($f['oid'] !== '' ? '<input type="hidden" name="oid" value="' . h($f['oid']) . '">' : '') . ($f['rid'] !== '' ? '<input type="hidden" name="rid" value="' . h($f['rid']) . '">' : '')
        . '<button class="pill-btn secondary" type="submit">' . th('Filtern') . '</button>' . (array_filter($f) ? btn(t('Zurücksetzen'), url(['p' => 'audit']), 'ghost') : '') . '</form>';

    if ($rows === []) {
        echo '<div class="empty-state small">' . icon('list', 36) . '<p>' . th('Keine Einträge.') . '</p></div>';
    }
    foreach ($rows as $r) {
        echo audit_row_full($r, $chain['broken_at'] !== null && (int)$r['id'] >= (int)$chain['broken_at']);
    }
    $params = ['p' => 'audit'] + $f;
    if ($pages > 1) {
        echo '<nav class="pager">' . ($page > 1 ? btn(t('Neuere'), url($params + ['page' => $page - 1]), 'secondary') : '') . '<span>' . th('Seite {a} von {b}', ['a' => $page, 'b' => $pages]) . '</span>'
            . ($page < $pages ? btn(t('Ältere'), url($params + ['page' => $page + 1]), 'secondary') : '') . '</nav>';
    }
    page_end();
}

function audit_row_full(array $r, bool $suspect): string
{
    $resCls = match ($r['result']) {
        'ok' => 'ok-soft', 'warn' => 'warn', default => 'bad',
    };
    $detail = $r['detail'] ? json_decode((string)$r['detail'], true) : null;
    $obj = $r['object_label'] ? h($r['object_label']) : '';
    if ($r['net'] && $r['object_type'] === 'host' && $r['object_id']) {
        $obj = '<a href="' . h(url(['p' => 'host', 'n' => $r['net'], 'id' => $r['object_id']])) . '">' . $obj . '</a>';
    } elseif ($r['net'] && $r['object_type'] === 'network') {
        $obj = '<a href="' . h(url(['p' => 'net', 'n' => $r['net']])) . '">' . $obj . '</a>';
    }
    $o = '<details class="audit-row' . ($suspect ? ' suspect' : '') . '" id="a' . (int)$r['id'] . '"><summary class="audit-main">'
        . '<span class="audit-id mono">#' . (int)$r['id'] . '</span>'
        . '<span class="badge ' . $resCls . '">' . h($r['result']) . '</span>'
        . '<strong>' . h(audit_label((string)$r['action'])) . '</strong>'
        . ($obj !== '' ? '<span class="audit-obj">' . $obj . '</span>' : '')
        . '<span class="audit-user">' . icon('users', 12) . h((string)($r['username'] ?? '–')) . '</span>'
        . '<span class="audit-ip mono">' . h((string)$r['ip']) . '</span>'
        . '<span class="audit-ts">' . h(fmt_datetime(substr((string)$r['ts'], 0, 19), true)) . '</span></summary>';
    $meta = [
        t('Zeitpunkt') => '<span class="mono">' . h($r['ts']) . '</span>',
        t('Aktion') => '<span class="mono">' . h($r['action']) . '</span> · ' . h($r['category']),
        t('Benutzer') => h(($r['username'] ?? '–') . ($r['role'] ? ' · ' . t('Rolle') . ' ' . role_label($r['role'] === 'super' ? 'admin' : (string)$r['role']) : '') . ($r['auth_via'] ? ' · via ' . source_label((string)$r['auth_via']) : '')),
        t('Herkunft') => '<span class="mono">' . h($r['ip']) . '</span>' . ($r['session'] ? ' · ' . th('Sitzung') . ' <span class="mono">' . h($r['session']) . '</span>' : ''),
        t('Browser') => h($r['user_agent']),
        t('Anfrage') => '<span class="mono small break">' . h($r['method'] . ' ' . $r['uri']) . '</span> · ID <a class="mono" href="' . h(url(['p' => 'audit', 'rid' => $r['request_id']])) . '">' . h($r['request_id']) . '</a>',
        t('Objekt') => $r['object_type'] ? h($r['object_type'] . ($r['object_id'] !== null ? ' #' . $r['object_id'] : '') . ($r['net'] ? ' · ' . $r['net'] : '')) : null,
        'Hash' => '<span class="mono small break">' . h(substr((string)$r['hash'], 0, 32)) . '… ← ' . h(substr((string)$r['prev_hash'], 0, 16)) . '…</span>',
    ];
    $o .= '<div class="audit-body">' . kv_list($meta);
    if (is_array($detail)) {
        $o .= audit_detail_html($detail);
    }
    return $o . '</div></details>';
}

function audit_detail_html(array $d): string
{
    $o = '';
    if (isset($d['aenderungen']) && is_array($d['aenderungen']) && $d['aenderungen'] !== []) {
        $o .= '<table class="mini-table diff"><thead><tr><th>' . th('Feld') . '</th><th>' . th('vorher') . '</th><th>' . th('nachher') . '</th></tr></thead><tbody>';
        foreach ($d['aenderungen'] as $field => $pair) {
            [$a, $b] = is_array($pair) ? array_pad(array_values($pair), 2, null) : [null, $pair];
            $o .= '<tr><td class="mono">' . h($field) . '</td><td class="old">' . h(is_scalar($a) || $a === null ? (string)($a ?? '∅') : json_encode($a, JSON_UNESCAPED_UNICODE)) . '</td><td class="new">' . h(is_scalar($b) || $b === null ? (string)($b ?? '∅') : json_encode($b, JSON_UNESCAPED_UNICODE)) . '</td></tr>';
        }
        $o .= '</tbody></table>';
        unset($d['aenderungen']);
    }
    if ($d !== []) {
        $o .= '<pre class="console small">' . h((string)json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
    }
    return $o;
}

function action_audit_csv(): void
{
    $f = audit_filters();
    [$where, $params] = audit_where($f);
    audit('audit_export', ['filter' => array_filter($f), 'anzahl' => (int)sys_val("SELECT COUNT(*) FROM audit WHERE $where", $params)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['id', 'ts', 'request_id', 'username', 'auth_via', 'role', 'ip', 'user_agent', 'session', 'method', 'uri', 'action', 'category', 'result', 'net', 'object_type', 'object_id', 'object_label', 'detail', 'prev_hash', 'hash'];
    fputcsv($out, $cols, ';', '"', '');
    $st = sys()->prepare("SELECT * FROM audit WHERE $where ORDER BY id");
    $st->execute($params);
    while ($r = $st->fetch()) {
        $line = [];
        foreach ($cols as $c) {
            $v = (string)($r[$c] ?? '');
            $line[] = preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        }
        fputcsv($out, $line, ';', '"', '');
    }
    fclose($out);
}
