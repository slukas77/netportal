<?php
/**
 * SSH-Terminal im Browser (reines PHP, ohne WebSocket).
 *
 *   Browser ──fetch──▶ term_open   startet bin/term-broker.php im Hintergrund
 *           ──fetch──▶ term_write  Tastatur → .cache/term/<id>/in
 *           ◀─fetch─── term_read   Ausgabe ab Position X (Long-Polling)
 *           ──fetch──▶ term_resize Fenstergröße → ssh (SIGWINCH)
 *           ──fetch──▶ term_close  Sitzung beenden
 *
 * Jede Sitzung gehört genau einem Portal-Benutzer UND seiner Anmeldesitzung.
 * Ziel ist immer ein Eintrag aus einem Netzwerk; freie Ziele nur für
 * Portal-Admins. Anmeldung am Ziel mit Passwort (im Terminal eingetippt,
 * wird nie gespeichert oder protokolliert) oder mit dem Portal-Schlüssel.
 */

declare(strict_types=1);

const TERM_DIR = CACHE_DIR . '/term';

function terminal_php_cli(): ?string
{
    $set = trim((string)setting('system.php_cli', ''));
    $cands = $set !== '' ? [$set] : [];
    if (PHP_SAPI === 'cli') {
        $cands[] = PHP_BINARY;
    }
    $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    array_push($cands, PHP_BINDIR . '/php' . $ver, PHP_BINDIR . '/php', '/usr/bin/php' . $ver, '/usr/bin/php', '/usr/local/bin/php');
    foreach ($cands as $c) {
        if ($c !== '' && is_file($c) && is_executable($c) && !str_contains(basename($c), 'fpm') && !str_contains(basename($c), 'cgi')) {
            return $c;
        }
    }
    return null;
}

/** Was fehlt für das Terminal? (leer = einsatzbereit) */
function terminal_problems(): array
{
    $p = [];
    if (!setting('ssh.terminal_enabled', true)) {
        $p[] = t('SSH-Terminal ist in den Einstellungen ausgeschaltet.');
    }
    $ssh = (string)setting('ssh.ssh_path', '/usr/bin/ssh');
    if (!is_file($ssh) || !is_executable($ssh)) {
        $p[] = t('ssh-Programm fehlt ({p})', ['p' => $ssh]);
    }
    $script = (string)setting('ssh.script_path', '/usr/bin/script');
    if (!is_file($script) || !is_executable($script)) {
        $p[] = t('„script“ fehlt ({p}, Paket bsdutils/util-linux)', ['p' => $script]);
    }
    if (terminal_php_cli() === null) {
        $p[] = t('PHP-Kommandozeile nicht gefunden (Paket php-cli, Einstellungen → System)');
    }
    if (!function_exists('proc_open')) {
        $p[] = t('proc_open ist in PHP gesperrt (disable_functions)');
    }
    return $p;
}

function terminal_available(): bool
{
    static $ok = null;
    return $ok ??= terminal_problems() === [];
}

/** Darf der angemeldete Benutzer im Terminal den Portal-Schlüssel benutzen? */
function terminal_key_allowed(): bool
{
    if (!is_file(app_path((string)setting('ssh.key_file')))) {
        return false;
    }
    return can('terminal_key') || (can('terminal') && setting('ssh.terminal_key_poweruser'));
}

function term_session_hash(): string
{
    return hash('sha256', 'term:' . session_id());
}

function term_dir(string $sid): string
{
    return TERM_DIR . '/' . $sid;
}

function term_status(string $sid): array
{
    $s = json_decode((string)@file_get_contents(term_dir($sid) . '/status.json'), true);
    return is_array($s) ? $s : ['state' => 'starting'];
}

/** Sitzung laden und Besitz prüfen (gleicher Benutzer UND gleiche Anmeldesitzung). */
function term_owned(string $sid): array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $sid) || !is_file(term_dir($sid) . '/meta.json')) {
        json_out(['error' => t('Sitzung nicht gefunden.'), 'closed' => true], 404);
    }
    $meta = json_decode((string)file_get_contents(term_dir($sid) . '/meta.json'), true);
    $u = auth_user();
    if (!is_array($meta) || $u === null || (int)$meta['uid'] !== (int)$u['id'] || !hash_equals((string)$meta['sess'], term_session_hash())) {
        audit('term_denied', ['sitzung' => $sid, 'grund' => 'fremde Sitzung'], null, 'denied');
        json_out(['error' => t('Keine Berechtigung')], 403);
    }
    return $meta;
}

/** Alte Sitzungsordner aufräumen. */
function term_gc(): void
{
    $max = (int)setting('ssh.terminal_max_minutes', 480) * 60 + 3600;
    foreach (glob(TERM_DIR . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $sid = basename($d);
        $st = term_status($sid);
        $age = time() - (int)@filemtime($d . '/meta.json');
        $closedFor = ($st['state'] ?? '') === 'closed' ? time() - (int)($st['ended'] ?? 0) : 0;
        if (($st['state'] ?? '') === 'closed' && !is_file($d . '/logged')) {
            $meta = json_decode((string)@file_get_contents($d . '/meta.json'), true);
            if (is_array($meta)) {
                term_finish($sid, $meta, $st);
            }
        }
        if ($closedFor > 600 || $age > $max) {
            foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($d);
        }
    }
}

/** Laufende Sitzungen eines Benutzers. */
function term_running(int $uid): array
{
    $out = [];
    foreach (glob(TERM_DIR . '/*/meta.json') ?: [] as $f) {
        $m = json_decode((string)file_get_contents($f), true);
        $sid = basename(dirname($f));
        if (is_array($m) && (int)$m['uid'] === $uid && (term_status($sid)['state'] ?? '') !== 'closed') {
            $out[$sid] = $m;
        }
    }
    return $out;
}

/** Alle Einträge aller Netzwerke für die Auswahl im Terminal. */
function terminal_targets(): array
{
    $out = [];
    foreach (net_list() as $slug => $meta) {
        try {
            $net = net_load($slug);
        } catch (Throwable $e) {
            continue;
        }
        $hosts = array_values(array_filter($net['hosts'], static fn($h) => host_target($h) !== null));
        usort($hosts, 'host_sort_cmp');
        foreach ($hosts as $h) {
            $out[$slug]['name'] = $meta['name'];
            $out[$slug]['hosts'][] = [
                'id' => (int)$h['id'], 'name' => (string)$h['hostname'], 'ip' => (string)$h['ip'], 'user' => (string)($h['ssh_user'] ?? ''),
                'port' => (int)($h['ssh_port'] ?: 22), 'kind' => (string)$h['kind'],
            ];
        }
    }
    return $out;
}

function action_term_open(): void
{
    if (($pr = terminal_problems()) !== []) {
        json_out(['error' => implode(' · ', $pr)], 503);
    }
    $u = auth_user();
    $user = in_str('user', 64);
    $port = in_int('port', 22);
    $auth = in_str('auth', 10) === 'key' ? 'key' : 'password';
    $slug = in_str('n', 60);
    $hid = in_int('id');
    $free = in_str('target', 255);
    $ctx = [];
    if ($slug !== '' && $hid > 0) {
        $net = net_or_404($slug);
        $h = $net['hosts'][$hid] ?? null;
        if ($h === null) {
            json_out(['error' => t('Eintrag nicht gefunden.')], 404);
        }
        $target = host_target($h);
        $label = (string)$h['hostname'];
        $ctx = host_ctx($net, $h);
    } elseif ($free !== '' && can('ssh_free')) {
        $target = net_target($free);
        $label = $free;
        $ctx = ['type' => 'host', 'label' => $free];
    } else {
        json_out(['error' => t('Bitte einen Eintrag auswählen.')], 400);
    }
    $ip = $target !== null ? net_resolve($target) : null;
    if ($ip === null) {
        json_out(['error' => t('Adresse nicht auflösbar.')], 400);
    }
    if (!ssh_valid_user($user)) {
        json_out(['error' => t('SSH-Benutzer ungültig.')], 400);
    }
    if ($port < 1 || $port > 65535) {
        json_out(['error' => t('Port muss zwischen 1 und 65535 liegen.')], 400);
    }
    if ($auth === 'key' && !terminal_key_allowed()) {
        audit('term_denied', ['ziel' => $ip . ':' . $port, 'benutzer' => $user, 'grund' => 'Portal-Schlüssel nicht erlaubt'], null, 'denied', $ctx);
        json_out(['error' => t('Der Portal-Schlüssel ist für deine Rolle im Terminal nicht freigegeben.')], 403);
    }
    $running = term_running((int)$u['id']);
    $max = max(1, (int)setting('ssh.terminal_max_sessions', 4));
    if (count($running) >= $max) {
        json_out(['error' => t('Höchstens {n} gleichzeitige Terminal-Sitzungen.', ['n' => $max])], 429);
    }
    if (!is_dir(TERM_DIR)) {
        @mkdir(TERM_DIR, 0770, true);
    }
    term_gc();
    $sid = bin2hex(random_bytes(16));
    $dir = term_dir($sid);
    if (!@mkdir($dir, 0770)) {
        json_out(['error' => t('Sitzungsordner konnte nicht angelegt werden.')], 500);
    }
    $mode = setting('ssh.strict_host_key') === 'yes' ? 'yes' : 'accept-new';
    $kh = app_path((string)setting('ssh.known_hosts'));
    if (!is_dir(dirname($kh))) {
        @mkdir(dirname($kh), 0700, true);
    }
    $cmd = [(string)setting('ssh.ssh_path', '/usr/bin/ssh'), '-F', '/dev/null', '-tt', '-e', 'none',
        '-o', 'StrictHostKeyChecking=' . $mode, '-o', 'UserKnownHostsFile=' . $kh,
        '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4',
        '-o', 'NumberOfPasswordPrompts=3', '-o', 'ForwardAgent=no', '-o', 'ForwardX11=no', '-o', 'ClearAllForwardings=yes',
        '-o', 'PermitLocalCommand=no', '-o', 'LogLevel=ERROR'];
    if ($auth === 'key') {
        array_push($cmd, '-i', app_path((string)setting('ssh.key_file')), '-o', 'IdentitiesOnly=yes', '-o', 'PreferredAuthentications=publickey,keyboard-interactive,password');
    } else {
        array_push($cmd, '-o', 'PubkeyAuthentication=no', '-o', 'IdentitiesOnly=yes', '-o', 'PreferredAuthentications=keyboard-interactive,password');
    }
    array_push($cmd, '-p', (string)$port, '-l', $user, '--', $ip);
    $meta = [
        'sid' => $sid, 'uid' => (int)$u['id'], 'username' => (string)$u['username'], 'sess' => term_session_hash(),
        'target' => $ip, 'label' => $label, 'port' => $port, 'user' => $user, 'auth' => $auth, 'net' => $ctx['net'] ?? null, 'host_id' => $ctx['id'] ?? null,
        'cols' => max(20, min(400, in_int('cols', 100))), 'rows' => max(5, min(200, in_int('rows', 30))),
        'idle' => max(15, (int)setting('ssh.terminal_idle', 120)), 'max_seconds' => max(1, (int)setting('ssh.terminal_max_minutes', 480)) * 60,
        'script' => (string)setting('ssh.script_path', '/usr/bin/script'), 'cmd' => $cmd, 'created' => time(),
    ];
    file_put_atomic($dir . '/meta.json', (string)json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0660);
    touch($dir . '/out');
    touch($dir . '/in');
    touch($dir . '/ctl');
    touch($dir . '/alive');
    $php = (string)terminal_php_cli();
    $setsid = is_file('/usr/bin/setsid') ? ['/usr/bin/setsid', '-f'] : [];
    $p = @proc_open([...$setsid, $php, APP_ROOT . '/bin/term-broker.php', $sid], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/broker.log', 'a']],
        $pipes, APP_ROOT, ['PATH' => '/usr/local/bin:/usr/bin:/bin'], ['bypass_shell' => true]);
    if (!is_resource($p)) {
        json_out(['error' => t('Terminal konnte nicht gestartet werden.')], 500);
    }
    if ($setsid !== []) {
        proc_close($p); // setsid -f kehrt sofort zurück, der Vermittler läuft weiter
    }
    audit('term_open', ['ziel' => $ip . ':' . $port, 'name' => $label, 'benutzer' => $user, 'anmeldung' => $auth === 'key' ? 'Portal-Schlüssel' : 'Passwort/Tastatur', 'sitzung' => $sid,
        'groesse' => $meta['cols'] . 'x' . $meta['rows'], 'aufzeichnung' => (bool)setting('ssh.terminal_record')], null, 'ok', $ctx);
    json_out(['sid' => $sid, 'title' => $user . '@' . $label, 'target' => $ip, 'port' => $port]);
}

function action_term_read(): void
{
    $sid = in_str('sid', 32);
    $meta = term_owned($sid);
    $off = max(0, in_int('off'));
    session_write_close();
    $dir = term_dir($sid);
    @touch($dir . '/alive');
    $deadline = microtime(true) + 15;
    while (true) {
        clearstatcache(false, $dir . '/out');
        $size = (int)@filesize($dir . '/out');
        $st = term_status($sid);
        if ($size > $off) {
            $fh = fopen($dir . '/out', 'rb');
            fseek($fh, $off);
            $data = (string)fread($fh, 262144);
            fclose($fh);
            json_out(['data' => base64_encode($data), 'off' => $off + strlen($data), 'state' => $st['state'] ?? 'running']);
        }
        if (($st['state'] ?? '') === 'closed') {
            term_finish($sid, $meta, $st);
            json_out(['closed' => true, 'off' => $off, 'reason' => $st['reason'] ?? '', 'rc' => $st['rc'] ?? null]);
        }
        if ($size < $off) {
            json_out(['off' => $size, 'state' => $st['state'] ?? 'running']);
        }
        if (microtime(true) > $deadline || connection_aborted()) {
            json_out(['off' => $off, 'state' => $st['state'] ?? 'running']);
        }
        usleep(40000);
        @touch($dir . '/alive');
    }
}

/** Abschluss einer Sitzung: Audit (einmal), optional Aufzeichnung sichern. */
function term_finish(string $sid, array $meta, array $st): void
{
    $dir = term_dir($sid);
    $flag = $dir . '/logged';
    $fh = @fopen($flag, 'x');
    if ($fh === false) {
        return;
    }
    fclose($fh);
    $out = $dir . '/out';
    $sha = is_file($out) ? hash_file('sha256', $out) : null;
    $rec = null;
    if (setting('ssh.terminal_record') && is_file($out) && filesize($out) > 0) {
        $rdir = DB_DIR . '/terminal';
        if (!is_dir($rdir)) {
            @mkdir($rdir, 0770, true);
        }
        $rec = 'terminal/' . date('Ymd-His', (int)($st['started'] ?? time())) . '-' . preg_replace('/[^a-z0-9._-]/i', '_', (string)$meta['username']) . '-' . preg_replace('/[^a-z0-9._-]/i', '_', (string)$meta['target']) . '-' . substr($sid, 0, 8) . '.log';
        @copy($out, DB_DIR . '/' . $rec);
        @chmod(DB_DIR . '/' . $rec, 0640);
    }
    $ctx = $meta['net'] ? ['net' => $meta['net'], 'type' => 'host', 'id' => $meta['host_id'], 'label' => $meta['label']] : ['type' => 'host', 'label' => $meta['label']];
    audit('term_close', ['ziel' => $meta['target'] . ':' . $meta['port'], 'benutzer' => $meta['user'], 'sitzung' => $sid,
        'dauer_s' => max(0, (int)($st['ended'] ?? time()) - (int)($st['started'] ?? $meta['created'])), 'grund' => $st['reason'] ?? null, 'exit' => $st['rc'] ?? null,
        'ausgabe_bytes' => (int)($st['bytes'] ?? 0), 'ausgabe_sha256' => $sha, 'aufzeichnung' => $rec], (string)$meta['username'], 'ok', $ctx);
}

function action_term_write(): void
{
    $sid = in_str('sid', 32);
    term_owned($sid);
    $data = input()['data'] ?? '';
    if (!is_string($data) || strlen($data) > 65536) {
        json_out(['error' => t('Eingabe zu lang.')], 400);
    }
    session_write_close();
    if ((term_status($sid)['state'] ?? '') === 'closed') {
        json_out(['closed' => true]);
    }
    $fh = fopen(term_dir($sid) . '/in', 'ab');
    if ($fh && flock($fh, LOCK_EX)) {
        fwrite($fh, $data);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    @touch(term_dir($sid) . '/alive');
    json_out(['ok' => true]);
}

function term_ctl(string $sid, array $cmd): void
{
    $fh = fopen(term_dir($sid) . '/ctl', 'ab');
    if ($fh && flock($fh, LOCK_EX)) {
        fwrite($fh, json_encode($cmd) . "\n");
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function action_term_resize(): void
{
    $sid = in_str('sid', 32);
    term_owned($sid);
    session_write_close();
    term_ctl($sid, ['resize' => [max(20, min(400, in_int('cols', 100))), max(5, min(200, in_int('rows', 30)))]]);
    json_out(['ok' => true]);
}

function action_term_close(): void
{
    $sid = in_str('sid', 32);
    $meta = term_owned($sid);
    session_write_close();
    term_ctl($sid, ['close' => 1]);
    // kurz auf das Ende warten, dann Sitzung im Audit-Log abschließen
    for ($i = 0; $i < 30 && (term_status($sid)['state'] ?? '') !== 'closed'; $i++) {
        usleep(50000);
    }
    $st = term_status($sid);
    if (($st['state'] ?? '') === 'closed') {
        term_finish($sid, $meta, $st);
    }
    json_out(['ok' => true]);
}
