<?php
/**
 * Netzwerk-Werkzeuge: Ping, Port-/Dienst-Prüfung, SSH.
 *
 * Alle Programme werden OHNE Shell gestartet (Argumentliste), Ziele werden
 * vorher streng geprüft (IP-Adresse oder gültiger Hostname, nie mit "-").
 */

declare(strict_types=1);

const WELL_KNOWN_PORTS = [
    21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 67 => 'DHCP', 69 => 'TFTP', 80 => 'HTTP', 88 => 'Kerberos',
    110 => 'POP3', 123 => 'NTP', 135 => 'MS-RPC', 137 => 'NetBIOS', 139 => 'NetBIOS/SMB', 143 => 'IMAP', 161 => 'SNMP', 162 => 'SNMP-Trap',
    389 => 'LDAP', 443 => 'HTTPS', 445 => 'SMB', 464 => 'Kerberos-PW', 465 => 'SMTPS', 514 => 'Syslog', 515 => 'LPD', 587 => 'Submission',
    623 => 'IPMI', 631 => 'IPP', 636 => 'LDAPS', 873 => 'rsync', 993 => 'IMAPS', 995 => 'POP3S', 1194 => 'OpenVPN', 1433 => 'MS-SQL',
    1521 => 'Oracle', 1812 => 'RADIUS', 1883 => 'MQTT', 2049 => 'NFS', 2375 => 'Docker', 2376 => 'Docker-TLS', 3000 => 'Web (3000)',
    3128 => 'Proxy', 3268 => 'AD-GC', 3269 => 'AD-GC-SSL', 3306 => 'MySQL/MariaDB', 3389 => 'RDP', 5060 => 'SIP', 5061 => 'SIP-TLS',
    5432 => 'PostgreSQL', 5900 => 'VNC', 5985 => 'WinRM', 5986 => 'WinRM-HTTPS', 6379 => 'Redis', 8006 => 'Proxmox', 8080 => 'HTTP-Alt',
    8443 => 'HTTPS-Alt', 8883 => 'MQTT-TLS', 9000 => 'Web (9000)', 9090 => 'Cockpit/Prometheus', 9100 => 'Drucker/Node-Exporter',
    9200 => 'Elasticsearch', 10000 => 'Webmin', 51820 => 'WireGuard',
];
const BANNER_PORTS = [21, 22, 25, 110, 143, 587];

/** Ziel prüfen: IP oder Hostname. Liefert normalisierte Form oder null. */
function net_target(string $t): ?string
{
    $t = trim($t);
    if ($t === '' || $t[0] === '-' || strlen($t) > 253) {
        return null;
    }
    if (ip_valid($t)) {
        return $t;
    }
    return hostname_valid($t) ? rtrim($t, '.') : null;
}

/** Hostname -> IP (v4 bevorzugt). */
function net_resolve(string $target): ?string
{
    if (ip_valid($target)) {
        return $target;
    }
    $ip = gethostbyname($target);
    if ($ip !== $target && ip_valid($ip)) {
        return $ip;
    }
    $rec = @dns_get_record($target, DNS_AAAA);
    return is_array($rec) && isset($rec[0]['ipv6']) ? $rec[0]['ipv6'] : null;
}

function ping_bin(): ?string
{
    $cfg = trim((string)setting('tools.ping_path', ''));
    foreach ($cfg !== '' ? [$cfg] : ['/usr/bin/ping', '/bin/ping', '/usr/sbin/ping', '/sbin/ping'] as $c) {
        if (is_file($c) && is_executable($c)) {
            return $c;
        }
    }
    return null;
}

function ping_cmd(string $target, int $count, int $timeout): ?array
{
    $bin = ping_bin();
    if ($bin === null) {
        return null;
    }
    $cmd = [$bin, '-n', '-c', (string)$count, '-W', (string)$timeout];
    if ($count > 1) {
        $cmd[] = '-i';
        $cmd[] = '0.3';
    }
    if (ip_is_v6($target)) {
        $cmd[] = '-6';
    }
    $cmd[] = $target;
    return $cmd;
}

function ping_parse(string $out, int $code): array
{
    $r = ['sent' => 0, 'received' => 0, 'loss' => 100.0, 'min' => null, 'avg' => null, 'max' => null];
    if (preg_match('/(\d+) packets transmitted, (\d+) (?:packets )?received/', $out, $m)) {
        $r['sent'] = (int)$m[1];
        $r['received'] = (int)$m[2];
        $r['loss'] = $r['sent'] > 0 ? round(100 - $r['received'] / $r['sent'] * 100, 1) : 100.0;
    }
    if (preg_match('#= ([\d.]+)/([\d.]+)/([\d.]+)#', $out, $m)) {
        $r['min'] = (float)$m[1];
        $r['avg'] = (float)$m[2];
        $r['max'] = (float)$m[3];
    }
    $r['ok'] = $r['received'] > 0 && $code !== -2;
    return $r;
}

/** Ping mit Ausgabe. Fällt auf TCP-Ping zurück, wenn ICMP nicht möglich ist. */
function net_ping(string $target, ?int $count = null): array
{
    $count = max(1, min(20, $count ?? (int)setting('tools.ping_count', 4)));
    $timeout = max(1, min(5, (int)setting('tools.ping_timeout', 1)));
    $cmd = ping_cmd($target, $count, $timeout);
    if ($cmd !== null) {
        [$code, $out, $err, $ms] = proc_run($cmd, '', $count * ($timeout + 0.4) + 3);
        $text = trim($out . ($err !== '' ? "\n" . $err : ''));
        if ($code >= 0 && !preg_match('/operation not permitted|permission denied|socket:/i', $err)) {
            return ['method' => 'icmp', 'output' => $text, 'exit' => $code, 'ms' => $ms] + ping_parse($out, $code);
        }
        if ($code === -2) {
            return ['method' => 'icmp', 'output' => $text . "\n[" . t('Zeitüberschreitung') . ']', 'exit' => $code, 'ms' => $ms] + ping_parse($out, $code);
        }
    }
    $t = net_tcp_ping($target);
    $t['output'] = ($cmd === null ? t('Kein ping-Programm gefunden – TCP-Ping:') : t('ICMP nicht erlaubt – TCP-Ping:')) . "\n" . $t['output'];
    return $t;
}

/** "Lebt der Host?" über TCP: offener ODER abgewiesener Port heißt erreichbar. */
function net_tcp_ping(string $target): array
{
    $ip = net_resolve($target);
    if ($ip === null) {
        return ['method' => 'tcp', 'ok' => false, 'output' => t('Name nicht auflösbar: {t}', ['t' => $target]), 'sent' => 1, 'received' => 0, 'loss' => 100.0, 'avg' => null, 'min' => null, 'max' => null, 'exit' => 2, 'ms' => 0];
    }
    $res = net_scan_ports($ip, [22, 443, 80, 3389, 445, 53, 8080], min(2.0, (float)setting('tools.tcp_timeout', 1.5)), false);
    $alive = array_filter($res, static fn($r) => in_array($r['state'], ['open', 'closed'], true));
    $first = $alive !== [] ? reset($alive) : null;
    $lines = [];
    foreach ($res as $p => $r) {
        $lines[] = sprintf('tcp/%-5d %-8s %s', $p, $r['state'], $r['ms'] !== null ? $r['ms'] . ' ms' : '');
    }
    return ['method' => 'tcp', 'ok' => $first !== null, 'output' => implode("\n", $lines), 'sent' => 1, 'received' => $first ? 1 : 0,
        'loss' => $first ? 0.0 : 100.0, 'avg' => $first['ms'] ?? null, 'min' => $first['ms'] ?? null, 'max' => $first['ms'] ?? null, 'exit' => $first ? 0 : 1, 'ms' => 0];
}

/**
 * Viele Ziele parallel pingen (je 1 Paket). $targets = [schlüssel => ip].
 * Liefert [schlüssel => ['ok', 'avg', 'method']].
 */
function net_ping_many(array $targets): array
{
    $timeout = max(1, min(5, (int)setting('tools.ping_timeout', 1)));
    $parallel = max(1, min(128, (int)setting('tools.ping_parallel', 32)));
    $results = [];
    $queue = [];
    foreach ($targets as $k => $ip) {
        $t = net_target((string)$ip);
        if ($t === null) {
            $results[$k] = ['ok' => false, 'avg' => null, 'method' => 'none', 'error' => 'ungültiges Ziel'];
            continue;
        }
        $queue[$k] = $t;
    }
    if (ping_bin() === null) {
        foreach ($queue as $k => $t) {
            $r = net_tcp_ping($t);
            $results[$k] = ['ok' => $r['ok'], 'avg' => $r['avg'], 'method' => 'tcp'];
        }
        return $results;
    }
    $running = [];
    $env = ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C'];
    while ($queue !== [] || $running !== []) {
        while ($queue !== [] && count($running) < $parallel) {
            $k = array_key_first($queue);
            $t = $queue[$k];
            unset($queue[$k]);
            $p = @proc_open(ping_cmd($t, 2, $timeout), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
            if (!is_resource($p)) {
                $results[$k] = ['ok' => false, 'avg' => null, 'method' => 'icmp', 'error' => 'Start fehlgeschlagen'];
                continue;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$k] = ['proc' => $p, 'pipes' => $pipes, 'out' => '', 'err' => '', 'start' => microtime(true), 'target' => $t];
        }
        foreach ($running as $k => &$r) {
            $r['out'] .= (string)stream_get_contents($r['pipes'][1]);
            $r['err'] .= (string)stream_get_contents($r['pipes'][2]);
            $st = proc_get_status($r['proc']);
            $late = microtime(true) - $r['start'] > 2 * $timeout + 3;
            if ($st['running'] && !$late) {
                continue;
            }
            if ($late) {
                proc_terminate($r['proc'], 9);
            }
            $r['out'] .= (string)stream_get_contents($r['pipes'][1]);
            fclose($r['pipes'][1]);
            fclose($r['pipes'][2]);
            proc_close($r['proc']);
            if (preg_match('/operation not permitted|permission denied/i', $r['err'])) {
                $t = net_tcp_ping($r['target']);
                $results[$k] = ['ok' => $t['ok'], 'avg' => $t['avg'], 'method' => 'tcp'];
            } else {
                $p = ping_parse($r['out'], $st['running'] ? -2 : (int)$st['exitcode']);
                $results[$k] = ['ok' => $p['ok'], 'avg' => $p['avg'], 'method' => 'icmp', 'loss' => $p['loss']];
            }
            unset($running[$k]);
        }
        unset($r);
        if ($running !== []) {
            usleep(20000);
        }
    }
    return $results;
}

/**
 * TCP-Ports parallel prüfen (nicht blockierend). Liefert
 * [port => ['state' => open|closed|timeout|error, 'ms', 'name', 'banner']]
 */
function net_scan_ports(string $ip, array $ports, ?float $timeout = null, bool $banners = true): array
{
    $timeout ??= (float)setting('tools.tcp_timeout', 1.5);
    $ports = array_values(array_unique(array_filter(array_map('intval', $ports), static fn($p) => $p > 0 && $p < 65536)));
    $host = ip_is_v6($ip) ? '[' . $ip . ']' : $ip;
    $socks = [];
    $res = [];
    $t0 = microtime(true);
    foreach ($ports as $p) {
        $s = @stream_socket_client('tcp://' . $host . ':' . $p, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($s === false) {
            $res[$p] = ['state' => $errno === 111 ? 'closed' : 'error', 'ms' => null, 'name' => WELL_KNOWN_PORTS[$p] ?? '', 'banner' => '', 'error' => $errstr];
            continue;
        }
        stream_set_blocking($s, false);
        $socks[$p] = $s;
    }
    $pending = $socks;
    while ($pending !== []) {
        $left = $timeout - (microtime(true) - $t0);
        if ($left <= 0) {
            break;
        }
        $w = array_values($pending);
        $r = $e = null;
        $n = @stream_select($r, $w, $e, 0, (int)min(200000, $left * 1000000));
        if ($n === false) {
            break;
        }
        foreach ($w as $sock) {
            $p = array_search($sock, $pending, true);
            if ($p === false) {
                continue;
            }
            $ms = (int)round((microtime(true) - $t0) * 1000);
            $open = @stream_socket_get_name($sock, true) !== false;
            $res[$p] = ['state' => $open ? 'open' : 'closed', 'ms' => $ms, 'name' => WELL_KNOWN_PORTS[$p] ?? '', 'banner' => ''];
            unset($pending[$p]);
        }
    }
    foreach ($pending as $p => $s) {
        $res[$p] = ['state' => 'timeout', 'ms' => null, 'name' => WELL_KNOWN_PORTS[$p] ?? '', 'banner' => ''];
    }
    if ($banners) {
        foreach ($socks as $p => $s) {
            if (($res[$p]['state'] ?? '') === 'open' && in_array($p, BANNER_PORTS, true)) {
                $res[$p]['banner'] = net_read_banner($s, 0.8);
            }
        }
    }
    foreach ($socks as $s) {
        @fclose($s);
    }
    ksort($res);
    return $res;
}

function net_read_banner($sock, float $wait): string
{
    $buf = '';
    $end = microtime(true) + $wait;
    while (microtime(true) < $end && strlen($buf) < 256 && !str_contains($buf, "\n")) {
        $r = [$sock];
        $w = $e = null;
        if (@stream_select($r, $w, $e, 0, 100000) > 0) {
            $chunk = @fread($sock, 256);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }
    }
    $line = trim(strtok($buf, "\n") ?: '');
    return mb_substr(preg_replace('/[^\x20-\x7E]/', '', $line) ?? '', 0, 160);
}

/**
 * HTTPS-Prüfung mit Ersatzweg (Verfügbarkeitsprüfung in der Netzwerk-Sicht): Enthält eine https-URL
 * eine IP und schlägt der Aufruf fehl (z. B. TLS ohne passenden Servernamen), wird dieselbe URL mit
 * dem Hostnamen des Eintrags versucht. Erst wenn auch das fehlschlägt, gilt der Dienst als Fehler.
 * http://-Adressen und URLs mit Namen werden unverändert geprüft.
 */
function net_http_check_fallback(string $url, string $hostname = ''): array
{
    $r = net_http_check($url);
    $name = rtrim(trim($hostname), '.');
    if ($r['ok'] || $name === '' || ip_valid($name) || !hostname_valid($name)
        || !preg_match('#^(https://)(\[[0-9a-fA-F:.]+\]|[0-9.]+)(?=[:/?\#]|$)#i', $url, $m) || !ip_valid(trim($m[2], '[]'))) {
        return $r;
    }
    $alt = $m[1] . $name . substr($url, strlen($m[0]));
    $r2 = net_http_check($alt);
    if ($r2['ok']) {
        $r2['info'] = trim($r2['info'] . ' · ' . t('über Hostname {h} (über IP: {e})', ['h' => $name, 'e' => $r['info'] !== '' ? $r['info'] : $r['state']]));
        return $r2;
    }
    $r['info'] = trim($r['info'] . ' · ' . t('auch über Hostname {h} fehlgeschlagen: {e}', ['h' => $name, 'e' => $r2['info'] !== '' ? $r2['info'] : $r2['state']]));
    return $r;
}

/** Einzelner Dienst: TCP-Verbindung, optional HTTP-Status. */
function net_check_service(string $ip, array $svc): array
{
    $proto = (string)$svc['proto'];
    $port = (int)$svc['port'];
    $out = ['ok' => null, 'state' => 'unbekannt', 'ms' => null, 'info' => '', 'ts' => time()];
    if ($proto === 'udp' || $port <= 0) {
        $out['info'] = $proto === 'udp' ? t('UDP – nicht aktiv prüfbar') : t('kein Port');
        if (!empty($svc['url']) && setting('tools.http_check')) {
            return array_merge($out, net_http_check((string)$svc['url']));
        }
        return $out;
    }
    $r = net_scan_ports($ip, [$port], null, true)[$port];
    $out['ok'] = $r['state'] === 'open';
    $out['state'] = $r['state'];
    $out['ms'] = $r['ms'];
    $out['info'] = $r['banner'];
    if ($out['ok'] && !empty($svc['url']) && setting('tools.http_check')) {
        $h = net_http_check((string)$svc['url']);
        $out['info'] = $h['info'];
        $out['ok'] = $h['ok'];
        $out['state'] = $h['state'];
    }
    return $out;
}

/** HTTP(S)-Abfrage: Statuszeile + Serverkennung, Zertifikat wird bewusst nicht erzwungen (interne CAs). */
function net_http_check(string $url): array
{
    if (!preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url)) {
        return ['ok' => false, 'state' => 'error', 'info' => t('URL ungültig'), 'ms' => null];
    }
    $t0 = microtime(true);
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: NetworkPortal-Check\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true],
    ]);
    $fh = @fopen($url, 'rb', false, $ctx);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($fh === false) {
        $e = error_get_last()['message'] ?? t('keine Verbindung');
        return ['ok' => false, 'state' => 'error', 'info' => mb_substr(preg_replace('/^.*?: /', '', $e) ?? $e, 0, 140), 'ms' => $ms];
    }
    $meta = stream_get_meta_data($fh);
    $headers = $meta['wrapper_data'] ?? [];
    $status = is_array($headers) && isset($headers[0]) ? (string)$headers[0] : '';
    $server = '';
    foreach ((array)$headers as $hd) {
        if (stripos((string)$hd, 'Server:') === 0) {
            $server = trim(substr((string)$hd, 7));
        }
    }
    $certInfo = '';
    $params = stream_context_get_params($fh);
    if (isset($params['options']['ssl']['peer_certificate'])) {
        $c = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if (is_array($c)) {
            $days = (int)floor(((int)$c['validTo_time_t'] - time()) / 86400);
            $certInfo = ' · ' . ($days < 0 ? t('Zertifikat ABGELAUFEN') : t('Zertifikat gültig noch {d} Tage', ['d' => $days]));
        }
    }
    fclose($fh);
    $code = preg_match('#HTTP/\S+ (\d{3})#', $status, $m) ? (int)$m[1] : 0;
    return ['ok' => $code > 0 && $code < 500, 'state' => $code > 0 ? 'http ' . $code : 'error', 'info' => trim($status . ($server !== '' ? ' · ' . $server : '') . $certInfo), 'ms' => $ms];
}

// --- SSH ---------------------------------------------------------------------------------------

function ssh_valid_user(string $u): bool
{
    return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9._@-]{0,63}$/', $u);
}

function ssh_link(array $host): string
{
    $user = (string)($host['ssh_user'] ?: setting('ssh.default_user', 'root'));
    $port = (int)($host['ssh_port'] ?: 22);
    $addr = (string)($host['ip'] ?: $host['hostname']);
    $addr = ip_is_v6($addr) ? '[' . $addr . ']' : $addr;
    return 'ssh://' . rawurlencode($user) . '@' . $addr . ($port !== 22 ? ':' . $port : '');
}

function ssh_command_line(array $host): string
{
    $user = (string)($host['ssh_user'] ?: setting('ssh.default_user', 'root'));
    $port = (int)($host['ssh_port'] ?: 22);
    return 'ssh ' . ($port !== 22 ? '-p ' . $port . ' ' : '') . $user . '@' . ($host['ip'] ?: $host['hostname']);
}

/** Banner + Host-Key-Fingerprints (SHA256 wie bei ssh-keygen -l). */
function ssh_probe(string $ip, int $port): array
{
    $res = ['banner' => '', 'state' => '', 'ms' => null, 'keys' => [], 'keyscan' => null];
    $scan = net_scan_ports($ip, [$port], 3.0, false)[$port];
    $res['state'] = $scan['state'];
    $res['ms'] = $scan['ms'];
    if ($scan['state'] !== 'open') {
        return $res;
    }
    $host = ip_is_v6($ip) ? '[' . $ip . ']' : $ip;
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $en, $es, 3);
    if ($s) {
        $res['banner'] = net_read_banner($s, 2.5);
        fclose($s);
    }
    $ks = (string)setting('ssh.keyscan_path', '/usr/bin/ssh-keyscan');
    if (is_file($ks) && is_executable($ks)) {
        [$code, $out] = proc_run([$ks, '-T', '5', '-p', (string)$port, '-t', 'ed25519,ecdsa,rsa', $ip], '', 12);
        $res['keyscan'] = $code;
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            if (preg_match('/^\S+\s+(ssh-[a-z0-9-]+|ecdsa-[a-z0-9-]+)\s+([A-Za-z0-9+\/=]+)/', $line, $m)) {
                $raw = base64_decode($m[2], true);
                if ($raw !== false) {
                    $res['keys'][] = ['type' => $m[1], 'fp' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, true)), '=')];
                }
            }
        }
    }
    return $res;
}

/** Ist der Befehl erlaubt? [erlaubt, grund] */
function ssh_command_allowed(string $cmd): array
{
    $cmd = trim($cmd);
    if ($cmd === '' || strlen($cmd) > 1000 || str_contains($cmd, "\n") || str_contains($cmd, "\0")) {
        return [false, t('Befehl leer, zu lang oder mehrzeilig')];
    }
    $list = array_values(array_filter(array_map('trim', explode("\n", (string)setting('ssh.allowlist', '')))));
    if (in_array($cmd, $list, true)) {
        return [true, t('Freigabeliste')];
    }
    if (setting('ssh.allow_free') && can('ssh_free')) {
        return [true, t('freier Befehl (Portal-Admin)')];
    }
    return [false, t('Befehl steht nicht auf der Freigabeliste')];
}

function ssh_ready(): array
{
    $problems = [];
    $ssh = (string)setting('ssh.ssh_path', '/usr/bin/ssh');
    if (!is_file($ssh) || !is_executable($ssh)) {
        $problems[] = t('ssh-Programm fehlt ({p})', ['p' => $ssh]);
    }
    $key = app_path((string)setting('ssh.key_file'));
    if (!is_file($key) || !is_readable($key)) {
        $problems[] = t('Privater Schlüssel fehlt ({p}) – unter Einstellungen erzeugen', ['p' => setting('ssh.key_file')]);
    }
    $kh = app_path((string)setting('ssh.known_hosts'));
    if (!is_dir(dirname($kh)) || !is_writable(dirname($kh))) {
        $problems[] = t('Ordner für known_hosts nicht beschreibbar');
    }
    return $problems;
}

/** Befehl per SSH ausführen (Schlüssel-Anmeldung, kein Passwort, keine Shell lokal). */
function ssh_exec(string $ip, int $port, string $user, string $command): array
{
    $mode = setting('ssh.strict_host_key') === 'yes' ? 'yes' : 'accept-new';
    $cmd = [
        (string)setting('ssh.ssh_path', '/usr/bin/ssh'),
        '-F', '/dev/null',
        '-i', app_path((string)setting('ssh.key_file')),
        '-o', 'BatchMode=yes',
        '-o', 'IdentitiesOnly=yes',
        '-o', 'PasswordAuthentication=no',
        '-o', 'KbdInteractiveAuthentication=no',
        '-o', 'StrictHostKeyChecking=' . $mode,
        '-o', 'UserKnownHostsFile=' . app_path((string)setting('ssh.known_hosts')),
        '-o', 'ConnectTimeout=6',
        '-o', 'ServerAliveInterval=5',
        '-o', 'LogLevel=ERROR',
        '-T',
        '-p', (string)$port,
        '-l', $user,
        '--', $ip, $command,
    ];
    [$code, $out, $err, $ms] = proc_run($cmd, '', max(5, (int)setting('ssh.timeout', 30)), 131072);
    return ['exit' => $code, 'stdout' => $out, 'stderr' => trim($err), 'ms' => $ms];
}

function ssh_public_key(): ?string
{
    $f = app_path((string)setting('ssh.key_file')) . '.pub';
    return is_file($f) ? trim((string)file_get_contents($f)) : null;
}

/** Neues Ed25519-Schlüsselpaar für das Portal erzeugen. */
function ssh_generate_key(): array
{
    $keygen = (string)setting('ssh.keygen_path', '/usr/bin/ssh-keygen');
    $key = app_path((string)setting('ssh.key_file'));
    if (!is_dir(dirname($key))) {
        @mkdir(dirname($key), 0700, true);
    }
    foreach ([$key, $key . '.pub'] as $f) {
        if (is_file($f)) {
            @rename($f, $f . '.alt-' . date('Ymd-His'));
        }
    }
    [$code, $out, $err] = proc_run([$keygen, '-q', '-t', 'ed25519', '-N', '', '-C', 'netportal@' . php_uname('n'), '-f', $key], '', 20);
    @chmod($key, 0600);
    return ['ok' => $code === 0 && is_file($key), 'output' => trim($out . "\n" . $err)];
}

// --- iperf3 --------------------------------------------------------------------------------------

function iperf_bin(): ?string
{
    $p = (string)setting('tools.iperf_path', '/usr/bin/iperf3');
    foreach ([$p, '/usr/bin/iperf3', '/usr/local/bin/iperf3'] as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }
    return null;
}

/**
 * iperf3-Test vom Portal-Server zum Ziel (auf dem Ziel muss "iperf3 -s" laufen).
 * $reverse = true: Ziel sendet zum Server (Download-Richtung, -R).
 */
function iperf_run(string $ip, int $port, string $proto, int $duration, string $bandwidth, bool $reverse, int $streams): array
{
    $bin = iperf_bin();
    if ($bin === null) {
        return ['ok' => false, 'error' => t('iperf3 ist auf dem Portal-Server nicht installiert (Paket iperf3, siehe System).')];
    }
    $cmd = [$bin, '-c', $ip, '-p', (string)$port, '-t', (string)$duration, '-J', '-P', (string)$streams, '--connect-timeout', '3000'];
    if ($proto === 'udp') {
        array_push($cmd, '-u', '-b', $bandwidth);
    }
    if ($reverse) {
        $cmd[] = '-R';
    }
    $timeout = $duration + 15;
    [$code, $out, $err, $ms] = proc_run($cmd, '', $timeout, 4194304);
    if ($code !== 0 && str_contains($out . $err, 'connect-timeout')) {
        // ältere iperf3-Versionen kennen --connect-timeout nicht
        $cmd = array_values(array_filter($cmd, static fn($a) => $a !== '--connect-timeout' && $a !== '3000'));
        [$code, $out, $err, $ms] = proc_run($cmd, '', $timeout, 4194304);
    }
    $j = json_decode($out, true);
    $base = ['cmd' => implode(' ', array_slice($cmd, 1)), 'ms' => $ms, 'exit' => $code];
    if (!is_array($j)) {
        return $base + ['ok' => false, 'error' => trim($err) !== '' ? trim($err) : ($code === -2 ? t('Zeitüberschreitung') : t('Keine gültige Antwort von iperf3'))];
    }
    if (!empty($j['error'])) {
        $e = (string)$j['error'];
        if (stripos($e, 'unable to connect') !== false || stripos($e, 'connection refused') !== false) {
            $e .= ' – ' . t('läuft auf dem Ziel „iperf3 -s“ (Port {p})?', ['p' => $port]);
        }
        return $base + ['ok' => false, 'error' => $e];
    }
    $end = $j['end'] ?? [];
    $res = $base + [
        'ok' => true, 'proto' => $proto, 'reverse' => $reverse, 'version' => $j['start']['version'] ?? '',
        'remote' => ($j['start']['connected'][0]['remote_host'] ?? $ip) . ':' . ($j['start']['connected'][0]['remote_port'] ?? $port),
        'duration' => (float)($end['sum_sent']['seconds'] ?? $end['sum']['seconds'] ?? $duration),
        'intervals' => array_map(static fn($i) => round((float)($i['sum']['bits_per_second'] ?? 0)), $j['intervals'] ?? []),
    ];
    if ($proto === 'udp') {
        $sum = $end['sum'] ?? [];
        $res += [
            'sent_bps' => (float)($sum['bits_per_second'] ?? 0),
            'recv_bps' => (float)($end['sum_received']['bits_per_second'] ?? $sum['bits_per_second'] ?? 0),
            'jitter_ms' => round((float)($sum['jitter_ms'] ?? 0), 3),
            'lost' => (int)($sum['lost_packets'] ?? 0),
            'packets' => (int)($sum['packets'] ?? 0),
            'lost_pct' => round((float)($sum['lost_percent'] ?? 0), 2),
        ];
    } else {
        $res += [
            'sent_bps' => (float)($end['sum_sent']['bits_per_second'] ?? 0),
            'recv_bps' => (float)($end['sum_received']['bits_per_second'] ?? 0),
            'retransmits' => (int)($end['sum_sent']['retransmits'] ?? 0),
        ];
    }
    return $res;
}

function fmt_bps(float $bps): string
{
    foreach ([[1e9, 'Gbit/s'], [1e6, 'Mbit/s'], [1e3, 'kbit/s']] as [$f, $u]) {
        if ($bps >= $f) {
            return fmt_num($bps / $f, 2) . ' ' . $u;
        }
    }
    return fmt_num($bps, 0) . ' bit/s';
}

// --- MAC- und Hardware-Erkennung ------------------------------------------------------------------

/** Eigene Adressen des Portal-Servers: [ip => mac] */
function detect_local_addrs(): array
{
    $out = [];
    if (!function_exists('net_get_interfaces')) {
        return $out;
    }
    foreach ((array)@net_get_interfaces() as $name => $if) {
        $mac = null;
        foreach ($if['unicast'] ?? [] as $u) {
            if (($u['family'] ?? 0) === 17 && !empty($u['address']) && $u['address'] !== '00:00:00:00:00:00') {
                $mac = strtolower((string)$u['address']);
            }
        }
        foreach ($if['unicast'] ?? [] as $u) {
            if (in_array($u['family'] ?? 0, [2, 10], true) && !empty($u['address'])) {
                $out[(string)$u['address']] = $mac;
            }
        }
    }
    return $out;
}

/** MAC aus der Nachbartabelle des Servers (nur im selben Netzsegment möglich). */
function detect_neighbor_mac(string $ip): ?string
{
    if (!ip_is_v6($ip) && is_readable('/proc/net/arp')) {
        foreach (file('/proc/net/arp', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (($p[0] ?? '') === $ip && isset($p[3]) && $p[3] !== '00:00:00:00:00:00' && mac_normalize($p[3]) !== null) {
                return mac_normalize($p[3]);
            }
        }
    }
    foreach (['/usr/sbin/ip', '/sbin/ip', '/usr/bin/ip', '/bin/ip'] as $bin) {
        if (is_file($bin) && is_executable($bin)) {
            [$code, $out] = proc_run([$bin, 'neigh', 'show', $ip], '', 4);
            if ($code === 0 && preg_match('/lladdr ([0-9a-f:]{17})/i', $out, $m)) {
                return mac_normalize($m[1]);
            }
            break;
        }
    }
    return null;
}

/** NetBIOS-Abfrage (Windows/Samba): Name und MAC – funktioniert auch über Router hinweg. */
function detect_netbios(string $ip): ?array
{
    $bin = (string)setting('detect.nmblookup_path', '/usr/bin/nmblookup');
    if (ip_is_v6($ip) || !is_file($bin) || !is_executable($bin)) {
        return null;
    }
    [$code, $out] = proc_run([$bin, '-A', $ip], '', 6);
    $r = [];
    if (preg_match('/MAC Address = ([0-9A-F-]{17})/i', $out, $m) && $m[1] !== '00-00-00-00-00-00') {
        $r['mac'] = mac_normalize($m[1]);
    }
    if (preg_match('/^\s*(\S+)\s+<00>\s+-\s+(?!<GROUP>)/m', $out, $m)) {
        $r['name'] = $m[1];
    }
    return $r === [] ? null : $r;
}

/** Hersteller anhand der MAC (OUI-Datenbank: ieee-data, arp-scan, nmap oder wireshark). */
function oui_vendor(?string $mac): ?string
{
    $mac = $mac !== null ? mac_normalize($mac) : null;
    if ($mac === null) {
        return null;
    }
    $prefix = strtoupper(substr(str_replace(':', '', $mac), 0, 6));
    if (hexdec($prefix[1]) & 2) {
        return t('lokal verwaltete Adresse (VM/zufällig)');
    }
    $files = [
        '/usr/share/ieee-data/oui.txt' => 'ieee', '/usr/share/arp-scan/ieee-oui.txt' => 'tab', '/usr/share/nmap/nmap-mac-prefixes' => 'space',
        '/usr/share/wireshark/manuf' => 'manuf', '/usr/share/misc/oui.txt' => 'ieee',
    ];
    foreach ($files as $f => $fmt) {
        if (!is_readable($f)) {
            continue;
        }
        $fh = fopen($f, 'rb');
        $dashed = implode('-', str_split($prefix, 2));
        $colon = implode(':', str_split($prefix, 2));
        while (($line = fgets($fh)) !== false) {
            $hit = match ($fmt) {
                'ieee' => str_starts_with($line, $dashed) && preg_match('/\(hex\)\s+(.+)$/', $line, $m) ? $m[1] : null,
                'tab' => str_starts_with(strtoupper($line), $prefix) && preg_match('/^\S+\t(.+)$/', $line, $m) ? $m[1] : null,
                'space' => str_starts_with(strtoupper($line), $prefix) && preg_match('/^\S+\s+(.+)$/', $line, $m) ? $m[1] : null,
                'manuf' => str_starts_with(strtoupper($line), $colon) && preg_match('/^\S+\s+\S+\s+(.+)$/', $line, $m) ? $m[1] : null,
            };
            if ($hit !== null) {
                fclose($fh);
                return trim($hit);
            }
        }
        fclose($fh);
    }
    return null;
}

function detect_snmp(string $ip): ?array
{
    $bin = (string)setting('detect.snmpget_path', '/usr/bin/snmpget');
    if (!setting('detect.snmp_enabled') || !is_file($bin) || !is_executable($bin)) {
        return null;
    }
    $comm = (string)setting('detect.snmp_community', 'public');
    [$code, $out] = proc_run([$bin, '-v2c', '-c', $comm, '-t', '2', '-r', '0', '-Ovq', $ip, '1.3.6.1.2.1.1.1.0', '1.3.6.1.2.1.1.2.0', '1.3.6.1.2.1.1.5.0'], '', 8);
    if ($code !== 0) {
        return null;
    }
    $l = array_values(array_filter(array_map(static fn($x) => trim($x, " \t\""), explode("\n", $out))));
    return count($l) >= 2 ? ['descr' => mb_substr($l[0], 0, 200), 'oid' => $l[1], 'name' => $l[2] ?? null] : null;
}

/** Hardware-Daten per SSH (nur wenn SSH-Befehle eingerichtet und erlaubt sind). */
function detect_ssh_hw(string $ip, array $h): ?array
{
    if (!setting('detect.ssh_hw') || !setting('ssh.exec_enabled') || ssh_ready() !== []) {
        return null;
    }
    $user = (string)($h['ssh_user'] ?: setting('ssh.default_user', 'root'));
    $cmd = 'for f in /sys/class/dmi/id/product_uuid /etc/machine-id /sys/class/dmi/id/sys_vendor /sys/class/dmi/id/product_name; do printf "%s=" "$f"; cat "$f" 2>/dev/null | head -c 120; echo; done';
    $r = ssh_exec($ip, (int)($h['ssh_port'] ?: 22), $user, $cmd);
    if ($r['exit'] !== 0) {
        return null;
    }
    $v = [];
    foreach (explode("\n", $r['stdout']) as $line) {
        if (preg_match('#^/\S+/([\w-]+)=(.+)$#', trim($line), $m)) {
            $v[$m[1]] = trim($m[2]);
        }
    }
    return $v === [] ? null : $v;
}

/**
 * Ermittelt MAC, Hersteller und Hardware-Kennung eines Eintrags.
 * Liefert ['mac' => …|MAC_NOT_FOUND, 'hw_id' => …|MAC_NOT_FOUND, 'vendor', 'model', 'os', 'log' => [...]].
 */
function detect_host(array $h): array
{
    $log = [];
    $res = ['mac' => null, 'mac_source' => null, 'hw_id' => null, 'hw_source' => null, 'vendor' => null, 'model' => null, 'os' => null, 'rdns' => null];
    $target = host_target($h);
    $ip = $target !== null ? net_resolve($target) : null;
    if ($ip === null) {
        $log[] = '✗ ' . t('keine auflösbare Adresse');
        $res['mac'] = $res['hw_id'] = MAC_NOT_FOUND;
        return $res + ['log' => $log];
    }
    $local = detect_local_addrs();
    if (isset($local[$ip]) || in_array($ip, ['127.0.0.1', '::1'], true)) {
        $log[] = '• ' . t('Adresse gehört zum Portal-Server selbst');
        $mac = $local[$ip] ?? null;
        if ($mac === null) {
            foreach ($local as $m) {
                if ($m !== null) {
                    $mac = $m;
                    break;
                }
            }
        }
        if ($mac !== null) {
            $res['mac'] = $mac;
            $res['mac_source'] = 'local';
        }
        foreach (['/sys/class/dmi/id/product_uuid' => 'uuid', '/etc/machine-id' => 'machine-id'] as $f => $label) {
            if ($res['hw_id'] === null && is_readable($f) && ($v = trim((string)@file_get_contents($f))) !== '') {
                $res['hw_id'] = $label . ':' . $v;
                $res['hw_source'] = $f;
            }
        }
        foreach (['sys_vendor' => 'vendor', 'product_name' => 'model'] as $f => $k) {
            if (is_readable('/sys/class/dmi/id/' . $f)) {
                $res[$k] = trim((string)@file_get_contents('/sys/class/dmi/id/' . $f)) ?: null;
            }
        }
    } else {
        $p = net_ping($ip, 1);
        $log[] = ($p['ok'] ? '✓ ' : '• ') . t('Ping {r}', ['r' => $p['ok'] ? t('erreichbar') : t('keine Antwort')]) . ' (' . $p['method'] . ')';
        $mac = detect_neighbor_mac($ip);
        if ($mac !== null) {
            $res['mac'] = $mac;
            $res['mac_source'] = 'arp';
            $log[] = '✓ ' . t('MAC aus der Nachbartabelle (ARP/NDP): {m}', ['m' => $mac]);
        } else {
            $log[] = '• ' . t('keine MAC in der Nachbartabelle (anderes Netzsegment oder keine Antwort)');
        }
        $nb = detect_netbios($ip);
        if ($nb !== null) {
            if ($res['mac'] === null && !empty($nb['mac'])) {
                $res['mac'] = $nb['mac'];
                $res['mac_source'] = 'netbios';
                $log[] = '✓ ' . t('MAC per NetBIOS: {m}', ['m' => $nb['mac']]);
            }
            if (!empty($nb['name'])) {
                $log[] = '• NetBIOS: ' . $nb['name'];
            }
        }
        $snmp = detect_snmp($ip);
        if ($snmp !== null) {
            $res['os'] = $snmp['descr'];
            $res['hw_id'] = 'snmp:' . $snmp['oid'];
            $res['hw_source'] = 'SNMP sysObjectID';
            $log[] = '✓ SNMP: ' . $snmp['descr'];
        }
        $ssh = detect_ssh_hw($ip, $h);
        if ($ssh !== null) {
            if (!empty($ssh['product_uuid'])) {
                $res['hw_id'] = 'uuid:' . $ssh['product_uuid'];
                $res['hw_source'] = 'SSH DMI';
            } elseif (!empty($ssh['machine-id'])) {
                $res['hw_id'] = 'machine-id:' . $ssh['machine-id'];
                $res['hw_source'] = 'SSH /etc/machine-id';
            }
            $res['vendor'] = $ssh['sys_vendor'] ?? $res['vendor'];
            $res['model'] = $ssh['product_name'] ?? $res['model'];
            $log[] = '✓ ' . t('Hardware-Daten per SSH gelesen');
        }
    }
    if ($res['mac'] !== null) {
        $v = oui_vendor($res['mac']);
        if ($v !== null) {
            $res['vendor'] ??= $v;
            $res['oui'] = $v;
            $log[] = '✓ ' . t('Hersteller laut MAC: {v}', ['v' => $v]);
        }
        if ($res['hw_id'] === null) {
            // Ohne bessere Kennung dient die MAC-Adresse als Hardware-Kennung
            $res['hw_id'] = 'mac:' . $res['mac'];
            $res['hw_source'] = 'MAC';
        }
    }
    $rdns = @gethostbyaddr($ip);
    if (is_string($rdns) && $rdns !== $ip) {
        $res['rdns'] = $rdns;
        $log[] = '• DNS: ' . $rdns;
    }
    $res['mac'] ??= MAC_NOT_FOUND;
    $res['hw_id'] ??= MAC_NOT_FOUND;
    if ($res['mac'] === MAC_NOT_FOUND) {
        $log[] = '✗ ' . t('MAC-Adresse nicht gefunden – bitte manuell eintragen');
    }
    $res['log'] = $log;
    return $res;
}
