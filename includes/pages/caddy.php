<?php
/**
 * Seite „Caddyfile-Generator“: Optionen anhaken → saubere Caddy-Konfiguration,
 * Vorschau (auch ohne JavaScript, serverseitig in PHP), Download als .conf,
 * Vorlagen (nur Portal-Admins speichern/löschen).
 */

declare(strict_types=1);

/** Portal-Dienste als Vorschläge für Upstreams (ip:port – Name). */
function caddy_upstream_suggestions(): array
{
    $out = [];
    foreach (array_keys(net_list()) as $slug) {
        try {
            $net = net_load($slug);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($net['services'] as $s) {
            $h = $net['hosts'][(int)$s['host_id']] ?? null;
            if ($h === null || $s['proto'] !== 'tcp' || !$s['port'] || !$h['ip']) {
                continue;
            }
            $ip = ip_is_v6((string)$h['ip']) ? '[' . $h['ip'] . ']' : (string)$h['ip'];
            $scheme = in_array((int)$s['port'], [443, 8006, 8443, 9443], true) ? 'https://' : '';
            $out[$scheme . $ip . ':' . $s['port']] = $h['hostname'] . ' · ' . $s['name'];
        }
    }
    ksort($out, SORT_NATURAL);
    return array_slice($out, 0, 500, true);
}

function caddy_site_form(string $i, array $s): string
{
    $n = static fn(string $k): string => 's[' . $i . '][' . $k . ']';
    $chk = static fn(string $k, string $label, string $hint = ''): string => '<div class="field check-field">' . f_check($n($k), $label, ($s[$k] ?? '0') === '1', $hint) . '</div>';
    $mode = (string)($s['mode'] ?? 'proxy');
    $o = '<details class="card caddy-site" data-site open><summary><span class="caddy-site-title">' . icon('globe', 15) . ' <span data-site-label>' . h(trim((string)$s['addresses']) !== '' ? (string)$s['addresses'] : t('Neue Site')) . '</span></span>'
        . '<button type="submit" class="icon-btn" name="del_site" value="' . h($i) . '" data-site-del formnovalidate title="' . th('Site entfernen') . '" aria-label="' . th('Site entfernen') . '">' . icon('trash', 15) . '</button></summary>';
    $o .= '<div class="caddy-sec"><h3>' . th('Adresse & Art') . '</h3><div class="form-grid cols-4">'
        . f_textarea($n('addresses'), t('Adressen (Domain, mehrere mit Leerzeichen)'), (string)$s['addresses'], 1, ['mono' => true, 'class' => 'span2', 'placeholder' => 'app.example.com www.app.example.com'])
        . f_select($n('mode'), t('Was soll die Site tun?'), array_map('t', CADDY_MODES), $mode, ['class' => 'caddy-mode'])
        . f_select($n('www'), t('www-Umleitung'), ['' => t('keine'), 'to_apex' => t('www → ohne www'), 'to_www' => t('ohne www → www')], (string)$s['www'])
        . '</div></div>';
    // Reverse-Proxy
    $o .= '<div class="caddy-sec" data-mode="proxy"><h3>' . th('Reverse-Proxy') . '</h3><div class="form-grid cols-4">'
        . f_textarea($n('upstreams'), t('Ziele (Upstreams)'), (string)$s['upstreams'], 2, ['mono' => true, 'class' => 'span2', 'placeholder' => '10.0.20.30:8080 10.0.20.31:8080',
            'hint' => th('Aus dem Portal übernehmen: Feld „Ziel aus dem Portal“ rechts.')])
        . '<label class="field span2"><span>' . th('Ziel aus dem Portal') . '</span><input type="text" list="caddyUps" class="mono caddy-up-pick" data-target="' . h($n('upstreams')) . '" placeholder="' . th('Eintrag suchen …') . '"></label>'
        . f_text($n('proxy_path'), t('Nur für Pfad (optional)'), (string)$s['proxy_path'], ['placeholder' => '/api/*', 'hint' => th('leer = ganze Site; sonst handle_path')])
        . f_select($n('lb'), t('Lastverteilung'), CADDY_LB, (string)$s['lb'])
        . f_text($n('health_uri'), t('Health-Check-Pfad'), (string)$s['health_uri'], ['placeholder' => '/health'])
        . f_text($n('health_interval'), t('Health-Check-Intervall'), (string)$s['health_interval'], ['placeholder' => '10s'])
        . f_text($n('dial_timeout'), t('Verbindungs-Timeout'), (string)$s['dial_timeout'], ['placeholder' => '5s'])
        . f_text($n('tls_server_name'), t('TLS-Servername zum Ziel'), (string)$s['tls_server_name'], ['placeholder' => 'intern.example.com'])
        . $chk('host_header', t('Host-Header des Ziels setzen'), th('header_up Host {upstream_hostport}'))
        . $chk('real_ip', t('X-Real-IP mitsenden'))
        . $chk('flush', t('Sofort weiterreichen (Streaming/SSE)'), th('flush_interval -1'))
        . $chk('tls_skip_verify', t('Zertifikat des Ziels nicht prüfen'), th('nur für interne Ziele mit selbst signiertem Zertifikat (z. B. Proxmox)'))
        . '</div></div>';
    // PHP / statisch
    $o .= '<div class="caddy-sec" data-mode="php static proxy-spa"><h3>' . th('Dateien & PHP') . '</h3><div class="form-grid cols-4">'
        . f_text($n('root'), t('Wurzelverzeichnis'), (string)$s['root'], ['class' => 'span2 mono-input', 'placeholder' => '/var/www/html'])
        . f_text($n('php_socket'), t('PHP-FPM-Socket'), (string)$s['php_socket'], ['class' => 'span2 mono-input', 'placeholder' => 'unix//run/php/php8.3-fpm.sock', 'hint' => th('nur bei PHP-Anwendung')])
        . f_text($n('hide'), t('Verstecken'), (string)$s['hide'], ['class' => 'span2', 'placeholder' => '.git .env'])
        . $chk('spa', t('Single-Page-App (Fallback auf /index.html)'))
        . $chk('browse', t('Verzeichnisliste anzeigen'))
        . $chk('precompressed', t('Vorkomprimierte Dateien ausliefern'), th('.zst .br .gz neben den Originalen'))
        . $chk('cache_static', t('Lange Cache-Zeit für CSS/JS/Bilder'))
        . '</div></div>';
    // Weiterleitung / Antwort
    $o .= '<div class="caddy-sec" data-mode="redirect"><h3>' . th('Weiterleitung') . '</h3><div class="form-grid cols-4">'
        . f_text($n('redirect_to'), t('Ziel'), (string)$s['redirect_to'], ['class' => 'span3 mono-input', 'placeholder' => 'https://neu.example.com{uri}'])
        . f_select($n('redirect_code'), t('Art'), ['permanent' => t('dauerhaft (301)'), 'temporary' => t('vorübergehend (302)'), '307' => '307', '308' => '308', 'html' => 'HTML'], (string)$s['redirect_code'])
        . '</div></div>';
    $o .= '<div class="caddy-sec" data-mode="respond"><h3>' . th('Feste Antwort') . '</h3><div class="form-grid cols-4">'
        . f_text($n('respond_body'), t('Text'), (string)$s['respond_body'], ['class' => 'span3', 'maxlength' => 300])
        . f_text($n('respond_code'), t('Statuscode'), (string)$s['respond_code'], ['inputmode' => 'numeric', 'maxlength' => 3])
        . '</div></div>';
    // TLS
    $o .= '<details class="caddy-sec caddy-more"><summary><h3>' . th('TLS / HTTPS') . '</h3></summary><div class="form-grid cols-4">'
        . f_select($n('tls'), t('Zertifikat'), array_map('t', CADDY_TLS), (string)$s['tls'], ['class' => 'span2 caddy-tls'])
        . f_text($n('tls_email'), t('E-Mail für das Zertifikat'), (string)$s['tls_email'], ['placeholder' => 'admin@example.com'])
        . f_select($n('tls_min'), t('Mindest-TLS-Version'), ['' => t('Standard (1.2)'), 'tls1.2' => 'TLS 1.2', 'tls1.3' => t('nur TLS 1.3')], (string)$s['tls_min'])
        . f_text($n('tls_cert'), t('Zertifikat-Datei'), (string)$s['tls_cert'], ['class' => 'span2 mono-input', 'placeholder' => '/etc/ssl/certs/site.pem', 'hint' => th('bei „Eigenes Zertifikat“')])
        . f_text($n('tls_key'), t('Schlüssel-Datei'), (string)$s['tls_key'], ['class' => 'span2 mono-input', 'placeholder' => '/etc/ssl/private/site.key'])
        . f_text($n('dns_provider'), t('DNS-Anbieter'), (string)$s['dns_provider'], ['placeholder' => 'cloudflare', 'hint' => th('bei DNS-Challenge')])
        . f_text($n('dns_token_env'), t('Umgebungsvariable mit API-Token'), (string)$s['dns_token_env'], ['placeholder' => 'CF_API_TOKEN'])
        . f_text($n('mtls_ca'), t('Client-Zertifikate verlangen (CA-Datei)'), (string)$s['mtls_ca'], ['class' => 'span2 mono-input', 'placeholder' => '/etc/caddy/client-ca.pem', 'hint' => th('leer = aus; sonst mTLS (require_and_verify)')])
        . '</div></details>';
    // Sicherheit
    $o .= '<details class="caddy-sec caddy-more"><summary><h3>' . th('Kompression & Sicherheits-Header') . '</h3></summary><div class="form-grid cols-4">'
        . $chk('encode_zstd', 'zstd') . $chk('encode_gzip', 'gzip')
        . $chk('hsts', 'HSTS', th('Strict-Transport-Security, 1 Jahr')) . $chk('hsts_sub', t('HSTS inkl. Subdomains'))
        . $chk('hsts_preload', t('HSTS preload')) . $chk('nosniff', 'X-Content-Type-Options: nosniff')
        . $chk('remove_server', t('Server-Header entfernen'), th('-Server, -X-Powered-By')) . $chk('noindex', t('Suchmaschinen ausschließen'), th('X-Robots-Tag: noindex'))
        . f_select($n('frame'), 'X-Frame-Options', ['' => t('nicht setzen'), 'SAMEORIGIN' => 'SAMEORIGIN', 'DENY' => 'DENY'], (string)$s['frame'])
        . f_select($n('referrer'), 'Referrer-Policy', ['' => t('nicht setzen'), 'strict-origin-when-cross-origin' => 'strict-origin-when-cross-origin', 'no-referrer' => 'no-referrer', 'same-origin' => 'same-origin', 'strict-origin' => 'strict-origin', 'no-referrer-when-downgrade' => 'no-referrer-when-downgrade'], (string)$s['referrer'], ['class' => 'span2'])
        . f_text($n('csp'), 'Content-Security-Policy', (string)$s['csp'], ['class' => 'span2 mono-input', 'placeholder' => "default-src 'self'"])
        . f_text($n('permissions'), 'Permissions-Policy', (string)$s['permissions'], ['class' => 'span2 mono-input', 'placeholder' => 'camera=(), microphone=(), geolocation=()'])
        . f_textarea($n('headers_custom'), t('Weitere Header (je Zeile „Name Wert“, „-Name“ entfernt)'), (string)$s['headers_custom'], 2, ['mono' => true, 'class' => 'span4', 'placeholder' => "X-Custom wert\n-X-Unwanted"])
        . '</div></details>';
    // Zugriff
    $o .= '<details class="caddy-sec caddy-more"><summary><h3>' . th('Zugriff') . '</h3></summary><div class="form-grid cols-4">'
        . f_textarea($n('allow_ips'), t('Nur diese IPs/Netze zulassen'), (string)$s['allow_ips'], 2, ['mono' => true, 'class' => 'span2', 'placeholder' => "10.0.0.0/8 192.168.0.0/16\nprivate_ranges", 'hint' => th('alle anderen erhalten 403')])
        . f_textarea($n('auth_users'), t('Basic-Auth (je Zeile „benutzer passwort“)'), (string)$s['auth_users'], 2, ['mono' => true, 'class' => 'span2', 'placeholder' => 'admin geheimesPasswort',
            'hint' => th('Passwörter werden beim Download als bcrypt-Hash eingesetzt – im Klartext steht nichts in der Datei.')])
        . f_text($n('auth_path'), t('Basic-Auth nur für Pfad'), (string)$s['auth_path'], ['placeholder' => '/admin/*'])
        . f_text($n('cors_origin'), t('CORS erlauben für'), (string)$s['cors_origin'], ['placeholder' => 'https://app.example.com'])
        . f_text($n('max_body'), t('Maximale Upload-Größe'), (string)$s['max_body'], ['placeholder' => '10MB'])
        . $chk('client_ip', t('Echte Client-IP verwenden'), th('client_ip statt remote_ip – braucht „Vertrauenswürdige Proxys“'))
        . '</div></details>';
    // Logging & Sonstiges
    $o .= '<details class="caddy-sec caddy-more"><summary><h3>' . th('Zugriffslog & Sonstiges') . '</h3></summary><div class="form-grid cols-4">'
        . $chk('log', t('Zugriffslog schreiben'))
        . f_text($n('log_file'), t('Log-Datei'), (string)$s['log_file'], ['class' => 'mono-input', 'placeholder' => '/var/log/caddy/access.log', 'hint' => th('leer = Standardausgabe')])
        . f_text($n('log_roll_size'), t('Rotation ab Größe'), (string)$s['log_roll_size'], ['placeholder' => '50MiB'])
        . f_text($n('log_roll_keep'), t('Anzahl alter Logs'), (string)$s['log_roll_keep'], ['inputmode' => 'numeric', 'placeholder' => '10'])
        . f_select($n('log_format'), t('Log-Format'), ['json' => 'JSON', 'console' => 'console'], (string)$s['log_format'])
        . $chk('errors', t('Einfache Fehlerseiten'), th('handle_errors'))
        . f_textarea($n('extra'), t('Eigene Direktiven (werden unverändert angehängt)'), (string)$s['extra'], 2, ['mono' => true, 'class' => 'span4', 'placeholder' => 'rewrite /alt /neu'])
        . '</div></details></details>';
    return $o;
}

function page_caddy(?array $state = null, array $errors = [], array $warn = []): void
{
    $presets = caddy_presets();
    if ($state === null) {
        if (is_post()) {
            require_post();
            $in = input();
            if (isset($in['add_site'])) {
                $in['s'] = array_values((array)($in['s'] ?? []));
                $in['s'][] = caddy_default_site();
            }
            if (isset($in['del_site']) && is_array($in['s'] ?? null)) {
                unset($in['s'][(string)$in['del_site']]);
                $in['s'] = array_values($in['s']);
            }
            [$state, $errors, $warn] = caddy_normalize($in);
        } elseif (($pn = get_str('preset', 60)) !== '' && isset($presets[$pn])) {
            [$state, $errors, $warn] = caddy_normalize((array)$presets[$pn]['config']);
        } else {
            [$state, $errors, $warn] = caddy_normalize(caddy_defaults());
        }
    }
    $text = $errors === [] ? caddy_render($state, false) : '';
    $c = caddy_strip($state);
    $g = $c['g'];
    page_start(t('Caddyfile-Generator'), 'caddy', [[t('Caddyfile-Generator'), null]]);
    heading(t('Caddyfile-Generator'), th('Optionen anhaken – das Portal erzeugt eine saubere Caddy-Konfiguration (Caddy 2.8 oder neuer) zum Download als .conf.'));

    // Vorlagen
    if ($presets !== []) {
        echo '<form class="filter-bar" method="get" action="./"><input type="hidden" name="p" value="caddy"><select name="preset" aria-label="' . th('Vorlage') . '">';
        foreach ($presets as $pn => $p) {
            echo '<option value="' . h((string)$pn) . '"' . (get_str('preset', 60) === (string)$pn ? ' selected' : '') . '>' . h((string)$pn) . ' · ' . h(fmt_datetime((string)($p['saved_at'] ?? ''))) . '</option>';
        }
        echo '</select><button class="pill-btn secondary" type="submit">' . icon('download', 14) . th('Vorlage laden') . '</button></form>';
    }
    foreach ($errors as $e) {
        echo '<div class="flash flash-error">' . h($e) . '</div>';
    }
    foreach ($warn as $w) {
        echo '<div class="flash flash-warn">' . h($w) . '</div>';
    }

    echo '<form method="post" action="' . h(url(['p' => 'caddy'])) . '" id="caddyForm" class="caddy-layout" autocomplete="off" data-preview="' . h(url(['a' => 'caddy_preview'])) . '">' . csrf_field();
    echo '<div class="caddy-form">';
    // Global
    $gn = static fn(string $k): string => 'g[' . $k . ']';
    $gchk = static fn(string $k, string $label, string $hint = ''): string => '<div class="field check-field">' . f_check($gn($k), $label, ($g[$k] ?? '0') === '1', $hint) . '</div>';
    echo '<details class="card caddy-global"><summary>' . icon('gear', 15) . ' ' . th('Globale Optionen') . '</summary>'
        . '<div class="caddy-sec"><div class="form-grid cols-4">'
        . $gchk('enabled', t('Globalen Block ausgeben'))
        . f_text($gn('email'), t('E-Mail (ACME-Konto)'), $g['email'], ['class' => 'span2', 'placeholder' => 'admin@example.com'])
        . $gchk('debug', t('Debug-Modus'))
        . f_select($gn('admin'), t('Admin-API'), ['default' => t('Standard (localhost:2019)'), 'off' => t('ausgeschaltet'), 'custom' => t('eigene Adresse')], $g['admin'])
        . f_text($gn('admin_addr'), t('Admin-Adresse'), $g['admin_addr'], ['class' => 'mono-input'])
        . f_text($gn('http_port'), 'http_port', $g['http_port'], ['inputmode' => 'numeric', 'placeholder' => '80'])
        . f_text($gn('https_port'), 'https_port', $g['https_port'], ['inputmode' => 'numeric', 'placeholder' => '443'])
        . f_select($gn('auto_https'), 'auto_https', ['on' => t('an (Standard)'), 'disable_redirects' => 'disable_redirects', 'disable_certs' => 'disable_certs', 'ignore_loaded_certs' => 'ignore_loaded_certs', 'off' => 'off'], $g['auto_https'])
        . f_select($gn('acme_ca'), t('Zertifizierungsstelle'), ['default' => t('Standard (Let’s Encrypt + ZeroSSL)'), 'le' => 'Let’s Encrypt', 'le_staging' => t('Let’s Encrypt Staging (Test)'), 'zerossl' => 'ZeroSSL', 'custom' => t('eigener ACME-Server')], $g['acme_ca'])
        . f_text($gn('acme_ca_url'), t('Eigener ACME-Server'), $g['acme_ca_url'], ['class' => 'span2 mono-input', 'placeholder' => 'https://ca.intern/acme/directory'])
        . $gchk('local_certs', t('Nur interne Zertifikate'), th('local_certs')) . $gchk('skip_install_trust', t('Interne CA nicht installieren'), th('skip_install_trust'))
        . f_text($gn('grace_period'), t('Wartezeit beim Neuladen'), $g['grace_period'], ['placeholder' => '10s'])
        . f_text($gn('storage'), t('Speicherort Zertifikate'), $g['storage'], ['class' => 'mono-input', 'placeholder' => '/var/lib/caddy'])
        . f_text($gn('default_sni'), 'default_sni', $g['default_sni'], ['placeholder' => 'example.com'])
        . $gchk('ocsp_off', t('OCSP-Stapling aus'))
        . f_text($gn('on_demand_ask'), t('On-Demand-TLS: Prüf-URL'), $g['on_demand_ask'], ['class' => 'span2 mono-input', 'placeholder' => 'http://localhost:5555/check'])
        . '</div><h3>' . th('Server') . '</h3><div class="form-grid cols-4">'
        . $gchk('h1', 'HTTP/1.1') . $gchk('h2', 'HTTP/2') . $gchk('h3', 'HTTP/3 (QUIC)')
        . f_text($gn('trusted_proxies'), t('Vertrauenswürdige Proxys'), $g['trusted_proxies'], ['class' => 'span2 mono-input', 'placeholder' => 'private_ranges 203.0.113.0/24'])
        . f_text($gn('timeout_read_body'), 'timeout read_body', $g['timeout_read_body'], ['placeholder' => '10s'])
        . f_text($gn('timeout_read_header'), 'timeout read_header', $g['timeout_read_header'], ['placeholder' => '10s'])
        . f_text($gn('timeout_write'), 'timeout write', $g['timeout_write'], ['placeholder' => '30s'])
        . f_text($gn('timeout_idle'), 'timeout idle', $g['timeout_idle'], ['placeholder' => '2m'])
        . '</div><h3>' . th('Protokoll (Caddy selbst)') . '</h3><div class="form-grid cols-4">'
        . $gchk('log', t('Eigenes Log einstellen'))
        . f_select($gn('log_output'), t('Ausgabe'), ['stderr' => 'stderr', 'stdout' => 'stdout', 'file' => t('Datei')], $g['log_output'])
        . f_text($gn('log_file'), t('Log-Datei'), $g['log_file'], ['class' => 'mono-input'])
        . f_select($gn('log_format'), t('Format'), ['console' => 'console', 'json' => 'JSON'], $g['log_format'])
        . f_select($gn('log_level'), 'Level', ['DEBUG' => 'DEBUG', 'INFO' => 'INFO', 'WARN' => 'WARN', 'ERROR' => 'ERROR'], $g['log_level'])
        . '</div></div></details>';

    echo '<div id="caddySites">';
    foreach ($c['s'] as $i => $s) {
        echo caddy_site_form((string)$i, $s);
    }
    echo '</div><template id="caddySiteTpl">' . caddy_site_form('__I__', caddy_default_site()) . '</template>';
    echo '<button type="submit" class="pill-btn ghost" name="add_site" value="1" id="caddyAddSite" formnovalidate>' . icon('plus', 14) . th('Weitere Site') . '</button>';
    echo '<datalist id="caddyUps">';
    foreach (caddy_upstream_suggestions() as $v => $label) {
        echo '<option value="' . h($v) . '">' . h($label) . '</option>';
    }
    echo '</datalist></div>';

    // Vorschau + Download
    echo '<aside class="caddy-preview"><div class="card"><div class="caddy-preview-head"><h2 class="section-label">' . th('Vorschau') . '</h2>'
        . '<span class="dim small" id="caddyState"></span></div>'
        . '<pre class="console caddy-out" id="caddyOut">' . h($text !== '' ? $text : t('Bitte die Fehler oben korrigieren.')) . '</pre>'
        . '<div class="form-grid cols-4 caddy-dl">'
        . f_text('name', t('Dateiname'), (string)$c['name'], ['class' => 'span2', 'maxlength' => 60, 'pattern' => '[A-Za-z0-9._\-]+'])
        . '<div class="field span2"><span>&nbsp;</span><div class="action-row tight">'
        . '<button type="submit" class="pill-btn secondary" formnovalidate>' . icon('check', 14) . th('Vorschau aktualisieren') . '</button>'
        . '<button type="submit" class="pill-btn primary" formaction="' . h(url(['a' => 'caddy_download'])) . '">' . icon('download', 14) . th('Download .conf') . '</button>'
        . copy_btn($text, t('Kopieren')) . '</div></div>';
    if (can('caddy_presets')) {
        echo f_text('preset_name', t('Als Vorlage speichern unter'), get_str('preset', 60), ['class' => 'span2', 'maxlength' => 60, 'placeholder' => t('z. B. Proxmox hinter Caddy')])
            . '<div class="field span2"><span>&nbsp;</span><div class="action-row tight"><button type="submit" class="pill-btn secondary" formaction="' . h(url(['a' => 'caddy_preset_save'])) . '" formnovalidate>' . icon('check', 14) . th('Vorlage speichern') . '</button>'
            . (get_str('preset', 60) !== '' && isset($presets[get_str('preset', 60)]) ? '<button type="submit" class="pill-btn danger" formaction="' . h(url(['a' => 'caddy_preset_delete'])) . '" formnovalidate data-confirm="' . th('Vorlage löschen?') . '">' . icon('trash', 14) . th('Vorlage löschen') . '</button>' : '')
            . '</div></div>';
    }
    echo '</div><p class="hint">' . th('Basic-Auth-Passwörter stehen in der Vorschau als Platzhalter; die heruntergeladene Datei enthält bcrypt-Hashes. Danach auf dem Server: caddy validate und systemctl reload caddy.') . '</p></div></aside>';
    echo '</form>';
    page_end();
}

function action_caddy_preview(): void
{
    [$c, $err, $warn] = caddy_normalize(input());
    json_out(['ok' => $err === [], 'text' => $err === [] ? caddy_render($c, false) : '', 'errors' => $err, 'warnings' => $warn]);
}

function action_caddy_download(): void
{
    [$c, $err, $warn] = caddy_normalize(input());
    if ($err !== []) {
        page_caddy($c, $err, $warn);
        exit;
    }
    $text = caddy_render($c, true);
    audit('caddy_download', ['datei' => $c['name'] . '.conf', 'sites' => array_map(static fn($s) => ['adressen' => $s['_addrs'] ?? [], 'art' => $s['mode'], 'tls' => $s['tls'],
        'basic_auth_benutzer' => array_map(static fn($u) => $u[0], $s['_users'] ?? [])], $c['s']), 'sha256' => hash('sha256', $text)]);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $c['name'] . '.conf"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $text;
}

function action_caddy_preset_save(): void
{
    $name = trim(in_str('preset_name', 60));
    if (!preg_match('/^[\p{L}\p{N} ._-]{1,60}$/u', $name)) {
        flash(t('Name der Vorlage ungültig (Buchstaben, Ziffern, Leerzeichen, . _ -).'), 'error');
        page_caddy(null, [], []);
        exit;
    }
    [$c, $err, $warn] = caddy_normalize(input());
    if ($err !== []) {
        page_caddy($c, $err, $warn);
        exit;
    }
    // Passwörter nie im Klartext ablegen
    foreach ($c['s'] as &$s) {
        $lines = [];
        foreach ($s['_users'] ?? [] as [$u, $pw]) {
            $lines[] = $u . ' ' . caddy_bcrypt($pw);
        }
        $s['auth_users'] = implode("\n", $lines);
    }
    unset($s);
    $p = caddy_presets();
    $p[$name] = ['config' => caddy_strip($c), 'saved_at' => now_str(), 'saved_by' => current_actor()];
    caddy_presets_save($p);
    audit('caddy_preset', ['vorlage' => $name, 'sites' => count($c['s'])]);
    flash(t('Vorlage „{n}“ gespeichert.', ['n' => $name]));
    redirect(url(['p' => 'caddy', 'preset' => $name]));
}

function action_caddy_preset_delete(): void
{
    $name = trim(in_str('preset_name', 60));
    $p = caddy_presets();
    if (!isset($p[$name])) {
        flash(t('Vorlage nicht gefunden.'), 'error');
        redirect(url(['p' => 'caddy']));
    }
    unset($p[$name]);
    caddy_presets_save($p);
    audit('caddy_preset_delete', ['vorlage' => $name]);
    flash(t('Vorlage „{n}“ gelöscht.', ['n' => $name]));
    redirect(url(['p' => 'caddy']));
}
