<?php
/**
 * Network Portal – Sitzungs-Vermittler für das SSH-Terminal im Browser.
 *
 * Wird vom Portal im Hintergrund gestartet (nicht von Hand):
 *   php bin/term-broker.php <sitzungs-id>
 *
 * Ablauf: startet ssh in einem Pseudo-Terminal (über „script“ aus util-linux),
 * schreibt alles, was ssh ausgibt, nach .cache/term/<id>/out und gibt die
 * Tastatureingaben aus .cache/term/<id>/in an ssh weiter. Größenänderungen und
 * „Trennen“ kommen über .cache/term/<id>/ctl. Ohne Abruf durch den Browser
 * (Datei „alive“) endet die Sitzung nach der eingestellten Zeit.
 *
 * Die Eingaben (z. B. Passwörter) werden nur durchgereicht und nie gespeichert:
 * die Datei „in“ wird nach dem Lesen fortlaufend geleert.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$sid = $argv[1] ?? '';
if (!preg_match('/^[0-9a-f]{32}$/', $sid)) {
    fwrite(STDERR, "usage: term-broker.php <sid>\n");
    exit(2);
}
$dir = dirname(__DIR__) . '/.cache/term/' . $sid;
$meta = is_file($dir . '/meta.json') ? json_decode((string)file_get_contents($dir . '/meta.json'), true) : null;
if (!is_array($meta) || !isset($meta['cmd']) || !is_array($meta['cmd'])) {
    exit(2);
}
umask(0007);

function status_write(string $dir, array $s): void
{
    $tmp = $dir . '/status.json.' . getmypid();
    file_put_contents($tmp, (string)json_encode($s));
    rename($tmp, $dir . '/status.json');
}

$cols = max(20, min(400, (int)($meta['cols'] ?? 100)));
$rows = max(5, min(200, (int)($meta['rows'] ?? 30)));
$sshCmd = implode(' ', array_map('escapeshellarg', array_map('strval', $meta['cmd'])));
$inner = 'stty rows ' . $rows . ' cols ' . $cols . ' 2>/dev/null; tty > ' . escapeshellarg($dir . '/pty') . '; exec ' . $sshCmd;
$env = [
    'PATH' => '/usr/local/bin:/usr/bin:/bin',
    'TERM' => 'xterm-256color',
    'LANG' => 'C.UTF-8',
    'LC_ALL' => 'C.UTF-8',
    'HOME' => $dir,
    'SHELL' => '/bin/sh',
];
$script = (string)($meta['script'] ?? '/usr/bin/script');
$proc = proc_open([$script, '-qfec', $inner, '/dev/null'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
if (!is_resource($proc)) {
    status_write($dir, ['state' => 'closed', 'rc' => -1, 'error' => 'start', 'ended' => time()]);
    exit(1);
}
$pid = (int)(proc_get_status($proc)['pid'] ?? 0);
status_write($dir, ['state' => 'running', 'broker' => getmypid(), 'pid' => $pid, 'started' => time()]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$out = fopen($dir . '/out', 'ab');
$started = time();
$idle = max(15, (int)($meta['idle'] ?? 120));
$maxSec = max(60, (int)($meta['max_seconds'] ?? 28800));
$maxBytes = 64 * 1024 * 1024;
$ctlOff = 0;
$lastCheck = 0.0;
$reason = 'exit';
$written = 0;

while (true) {
    $r = [$pipes[1], $pipes[2]];
    $w = $e = null;
    $n = @stream_select($r, $w, $e, 0, 30000);
    if ($n) {
        foreach ($r as $p) {
            $chunk = fread($p, 65536);
            if ($chunk !== false && $chunk !== '') {
                fwrite($out, $chunk);
                $written += strlen($chunk);
            }
        }
        fflush($out);
    }
    // Eingabe weiterreichen und Datei leeren (nichts bleibt liegen)
    clearstatcache(false, $dir . '/in');
    if (@filesize($dir . '/in') > 0) {
        $fh = fopen($dir . '/in', 'r+b');
        if ($fh && flock($fh, LOCK_EX)) {
            $data = (string)stream_get_contents($fh);
            ftruncate($fh, 0);
            flock($fh, LOCK_UN);
            fclose($fh);
            if ($data !== '') {
                @fwrite($pipes[0], $data);
                @fflush($pipes[0]);
            }
        }
    }
    // Steuerbefehle
    clearstatcache(false, $dir . '/ctl');
    if (@filesize($dir . '/ctl') > $ctlOff) {
        $fh = fopen($dir . '/ctl', 'rb');
        fseek($fh, $ctlOff);
        $buf = (string)stream_get_contents($fh);
        fclose($fh);
        $ctlOff += strlen($buf);
        foreach (explode("\n", trim($buf)) as $line) {
            $c = json_decode($line, true);
            if (!is_array($c)) {
                continue;
            }
            if (isset($c['resize']) && is_array($c['resize'])) {
                $pty = trim((string)@file_get_contents($dir . '/pty'));
                if (preg_match('#^/dev/pts/\d+$#', $pty)) {
                    $cc = max(20, min(400, (int)$c['resize'][0]));
                    $rr = max(5, min(200, (int)$c['resize'][1]));
                    $sp = proc_open([is_file('/usr/bin/stty') ? '/usr/bin/stty' : '/bin/stty', '-F', $pty, 'rows', (string)$rr, 'cols', (string)$cc], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sx);
                    if (is_resource($sp)) {
                        proc_close($sp);
                    }
                }
            }
            if (!empty($c['close'])) {
                $reason = 'closed';
                break 2;
            }
        }
    }
    $now = microtime(true);
    if ($now - $lastCheck >= 1.0) {
        $lastCheck = $now;
        $st = proc_get_status($proc);
        if (!$st['running']) {
            // Rest der Ausgabe abholen
            foreach ([$pipes[1], $pipes[2]] as $p) {
                while (($chunk = fread($p, 65536)) !== false && $chunk !== '') {
                    fwrite($out, $chunk);
                }
            }
            $reason = 'exit';
            break;
        }
        clearstatcache(false, $dir . '/alive');
        $alive = (int)@filemtime($dir . '/alive');
        if ($alive > 0 && time() - $alive > $idle) {
            $reason = 'idle';
            break;
        }
        if (time() - $started > $maxSec) {
            $reason = 'max';
            break;
        }
        if ($written > $maxBytes) {
            $reason = 'size';
            break;
        }
    }
}

$rc = null;
$st = proc_get_status($proc);
if ($st['running']) {
    proc_terminate($proc, 15);
    usleep(300000);
    $st = proc_get_status($proc);
    if ($st['running']) {
        proc_terminate($proc, 9);
    }
} else {
    $rc = $st['exitcode'];
}
foreach ($pipes as $p) {
    @fclose($p);
}
@proc_close($proc);
fclose($out);
@unlink($dir . '/in');
status_write($dir, ['state' => 'closed', 'reason' => $reason, 'rc' => $rc, 'started' => $started, 'ended' => time(), 'bytes' => $written]);
