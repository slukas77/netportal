<?php
/**
 * Seitenrahmen: Kopfleiste mit Menügruppen (Netzwerke, Infrastruktur, Werkzeuge,
 * Portal-Admin), Suche und Konto-Menü; darunter Pfad, Schnell-Ping und
 * Integritätsstatus; Inhalt mittig; Fußzeile mit Version und Sprache. DE/EN.
 */

declare(strict_types=1);

function icon(string $name, int $size = 16, string $class = ''): string
{
    $p = ICONS[$name] ?? ICONS['list'];
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '"' . ($class !== '' ? ' class="' . $class . '"' : '') . ' aria-hidden="true">' . $p . '</svg>';
}

/** URL einer Datei in assets/ mit Inhalts-Kennung – Browser dürfen sie dauerhaft zwischenspeichern. */
function asset_url(string $file): string
{
    static $v = [];
    if (!isset($v[$file])) {
        $p = APP_ROOT . '/assets/' . $file;
        $v[$file] = is_file($p) ? substr(hash('xxh128', filemtime($p) . ':' . filesize($p) . ':' . APP_VERSION), 0, 10) : APP_VERSION;
    }
    return 'assets/' . $file . '?v=' . rawurlencode($v[$file]);
}

function net_icon(string $kind): string
{
    return match ($kind) {
        'VLAN' => 'vlan', 'WLAN' => 'wifi', 'DMZ' => 'firewall', 'WAN', 'VPN' => 'globe', 'OT' => 'plc', default => 'lan',
    };
}

function host_icon(?string $kind): string
{
    return match ((string)$kind) {
        'Switch' => 'switch', 'Router' => 'router', 'Access Point' => 'wifi', 'Firewall' => 'firewall', 'Drucker' => 'printer',
        'Client' => 'desktop', 'Telefon / VoIP' => 'phone', 'Kamera' => 'camera', 'IoT' => 'chip', 'Steuerung / SPS' => 'plc',
        'USV' => 'ups', 'BMC / iLO / IPMI' => 'bmc', 'VM' => 'vm', 'Container' => 'container', 'Hypervisor' => 'hypervisor',
        'NAS / Storage' => 'nas', default => 'server',
    };
}

function app_title(): string
{
    return (string)setting('app.title', 'Network Portal');
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}

function html_head(string $title, string $bodyClass = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Language: ' . (lang() === 'en' ? 'en-US' : 'de-DE'));
    send_security_headers();
    $t = h($title === '' ? app_title() : $title . ' – ' . app_title());
    $csrf = h(session_status() === PHP_SESSION_ACTIVE ? csrf_masked() : '');
    $css = h(asset_url('style.css'));
    $lang = lang() === 'en' ? 'en-US' : 'de';
    $i18n = js_strings_json();
    $dbg = debug_enabled() && session_status() === PHP_SESSION_ACTIVE ? "\n<meta name=\"np-debug\" content=\"" . h(url(['a' => 'diag_clientlog'])) . "\">" : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{$t}</title>
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f8fb" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0e1621" media="(prefers-color-scheme: dark)">
<meta name="csrf" content="{$csrf}">{$dbg}
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="{$css}">
<script type="application/json" id="i18n">{$i18n}</script>
</head>
<body class="{$bodyClass}">

HTML;
}

/** Sprachumschalter (Links setzen das Cookie über ?a=lang). */
function lang_switch(): string
{
    $o = '<span class="lang-switch" aria-label="Language">';
    foreach (array_keys(LANGS) as $l) {
        $o .= $l === lang() ? '<b aria-current="true">' . strtoupper($l) . '</b>'
            : '<a href="' . h(url(['a' => 'lang', 'l' => $l, 'back' => (string)($_SERVER['QUERY_STRING'] ?? '')])) . '" hreflang="' . $l . '" title="' . h(LANGS[$l]) . '">' . strtoupper($l) . '</a>';
    }
    return $o . '</span>';
}

/** Status-Punkt eines Netzwerks für die Navigation. */
function net_health(array $n): array
{
    $st = status_get($n['slug']);
    $ids = $n['host_ids'] ?? [];
    $up = $down = $unknown = 0;
    foreach ($ids as $id) {
        $s = $st['hosts'][(string)$id] ?? null;
        if ($s === null || time() - (int)($s['ts'] ?? 0) > 86400) {
            $unknown++;
        } elseif (!empty($s['ok'])) {
            $up++;
        } else {
            $down++;
        }
    }
    $cls = $ids === [] || $up + $down === 0 ? 'unknown' : ($down === 0 ? 'up' : ($up === 0 ? 'down' : 'partial'));
    return ['up' => $up, 'down' => $down, 'unknown' => $unknown, 'class' => $cls];
}

/** Legende der Statusfarben (je Netzwerk und auf der Host-Seite). */
function status_legend(): string
{
    $items = [
        ['up', t('verfügbar / online')],
        ['down', t('nicht verfügbar / offline')],
        ['unknown', t('unbekannt (noch nicht geprüft)')],
        ['nocheck', t('ohne Statusprüfung angelegt')],
    ];
    $o = '<div class="legend" role="note" aria-label="' . th('Legende') . '"><span class="legend-title">' . th('Legende') . '</span>';
    foreach ($items as [$cls, $label]) {
        $o .= '<span class="legend-item"><i class="dot ' . $cls . '"></i>' . h($label) . '</span>';
    }
    return $o . '<span class="legend-item"><span class="svc-chip up">HTTPS</span><span class="svc-chip down">SSH</span><span class="svc-chip unknown">RDP</span><span class="svc-chip nocheck">SNMP</span> ' . th('Dienste in den gleichen Farben') . '</span></div>';
}

function page_start(string $title, string $active, array $crumbs = []): void
{
    html_head($title);
    $row = static function (string $key, string $label, string $ic, string $href, string $extra = '') use ($active): string {
        $cls = $key === $active ? ' active' : '';
        return '<a class="node-row' . $cls . '" href="' . h($href) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . icon($ic, 16, 'node-icon')
            . '<span class="node-label">' . h($label) . '</span>' . $extra . '</a>';
    };
    // Menügruppe der Kopfleiste (details/summary – funktioniert ohne Skript, app.js schließt beim Klick daneben)
    $group = static function (string $label, string $ic, array $keys, string $body, string $badge = '') use ($active): string {
        $on = in_array($active, $keys, true);
        return '<details class="menu' . ($on ? ' active' : '') . '"><summary class="topnav-link">' . icon($ic, 16) . '<span>' . h($label) . '</span>' . $badge
            . icon('chevron', 12, 'caret') . '</summary><div class="menu-panel">' . $body . '</div></details>';
    };
    $nets = net_list();

    echo '<div class="app" id="app">';
    echo '<header class="topbar"><div class="topbar-inner">';
    echo '<button class="icon-btn sidebar-toggle" id="sidebarToggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="' . th('Navigation ein-/ausblenden') . '">' . icon('menu', 20) . '</button>';
    echo '<a class="brand" href="./">' . icon('brand', 22, 'brand-mark') . '<span class="brand-text"><span>' . h(app_title()) . '</span>';
    if ((string)setting('app.company', '') !== '') {
        echo '<small class="brand-sub">' . h((string)setting('app.company')) . '</small>';
    }
    echo '</span></a>';

    echo '<div class="topnav-wrap" id="sidebar"><nav class="topnav" aria-label="' . th('Navigation') . '">';
    echo '<a class="topnav-link' . ($active === 'dashboard' ? ' active' : '') . '" href="' . h(url()) . '"' . ($active === 'dashboard' ? ' aria-current="page"' : '') . '>' . icon('home', 16) . '<span>' . th('Übersicht') . '</span></a>';

    $body = '<div class="menu-title">' . th('Netzwerke') . '<span class="section-count">' . count($nets) . '</span></div>';
    $down = 0;
    if ($nets === []) {
        $body .= '<p class="tree-empty">' . th('Noch keine Netzwerke.') . '</p>';
    }
    foreach ($nets as $n) {
        $hl = net_health($n);
        $down += $hl['down'] > 0 ? 1 : 0;
        $badge = $n['vlan_id'] !== null ? '<span class="vlan-tag">' . (int)$n['vlan_id'] . '</span>' : '';
        $extra = $badge . '<span class="node-count">' . (int)$n['hosts'] . '</span><i class="health-dot ' . $hl['class'] . '" title="'
            . th('{a} online · {b} offline · {c} ungeprüft', ['a' => $hl['up'], 'b' => $hl['down'], 'c' => $hl['unknown']]) . '"></i>';
        $body .= $row('net:' . $n['slug'], $n['name'], net_icon((string)$n['kind']), url(['p' => 'net', 'n' => $n['slug']]), $extra);
    }
    if (can('edit')) {
        $body .= '<a class="node-row add-row' . ($active === 'net_new' ? ' active' : '') . '" href="' . h(url(['p' => 'net_new'])) . '">' . icon('plus', 16, 'node-icon') . '<span class="node-label">' . th('Neues Netzwerk') . '</span></a>';
    }
    $keys = array_merge(['net_new'], array_map(static fn($n) => 'net:' . $n['slug'], $nets));
    echo $group(t('Netzwerke'), 'lan', $keys, $body, $down > 0 ? '<i class="health-dot down" title="' . th('{a} online · {b} offline · {c} ungeprüft', ['a' => '–', 'b' => $down, 'c' => '–']) . '"></i>' : '');

    $body = '<div class="menu-title">' . th('Infrastruktur') . '</div>'
        . $row('netmap', t('NetMap'), 'topology', url(['p' => 'netmap']))
        . $row('switches', t('Switches'), 'switch', url(['p' => 'switches']), '<span class="node-count">' . count(switches_list()) . '</span>')
        . $row('wans', t('WAN-Anschlüsse'), 'globe', url(['p' => 'wans']), '<span class="node-count">' . count(wan_links()) . '</span>' . wan_nav_badge());
    echo $group(t('Infrastruktur'), 'topology', ['netmap', 'switches', 'wans'], $body);

    $body = '<div class="menu-title">' . th('Vorlagen & Werkzeuge') . '</div>'
        . $row('masters', t('Master'), 'crown', url(['p' => 'masters']), '<span class="node-count">' . count(masters_store()['masters']) . '</span>');
    if (can('terminal')) {
        $body .= $row('terminal', t('SSH-Terminal'), 'terminal', url(['p' => 'terminal']));
    }
    if (can('caddy')) {
        $body .= $row('caddy', t('Caddyfile-Generator'), 'caddy', url(['p' => 'caddy']));
    }
    echo $group(t('Werkzeuge'), 'box', ['masters', 'terminal', 'caddy'], $body);

    if (can('settings')) {
        $body = '<div class="menu-title">' . th('Portal-Admin') . '</div>'
            . $row('audit', t('Audit-Log'), 'list', url(['p' => 'audit']))
            . $row('integrity', t('Integrität'), 'shield', url(['p' => 'integrity']))
            . $row('users', t('Benutzer'), 'users', url(['p' => 'users']))
            . $row('settings', t('Einstellungen'), 'gear', url(['p' => 'settings']))
            . $row('system', t('System & Pakete'), 'box', url(['p' => 'system']))
            . $row('diagnose', t('Diagnose & Debug'), 'pulse', url(['p' => 'diagnose']));
        echo $group(t('Portal-Admin'), 'gear', ['audit', 'integrity', 'users', 'settings', 'system', 'diagnose'], $body);
    }
    echo '</nav>';
    echo '<form class="search-wrap" method="get" action="./" role="search">' . icon('search', 15, 'search-icon')
        . '<input type="hidden" name="p" value="search"><input type="search" id="searchInput" name="q" value="' . h($active === 'search' ? get_str('q') : '') . '" placeholder="' . th('IP, Hostname, MAC, Dienst …') . '" autocomplete="off" spellcheck="false" aria-label="' . th('Suchen') . '"></form>';
    echo '</div>';

    $u = auth_user();
    $name = (string)($u['display_name'] ?: $u['username']);
    $initials = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $name) ?: '?', 0, 2));
    echo '<button class="icon-btn mobile-search" id="mobileSearch" type="button" aria-label="' . th('Suchen') . '">' . icon('search', 18) . '</button>';
    echo '<details class="menu user-menu"><summary class="user-btn" aria-label="' . th('Mein Konto') . '"><span class="avatar">' . h($initials) . '</span>' . icon('chevron', 12, 'caret') . '</summary>'
        . '<div class="menu-panel menu-right"><div class="user-card"><span class="avatar">' . h($initials) . '</span><div class="track-meta"><div class="track-title">' . h($name) . '</div>'
        . '<div class="track-artist">' . h(role_label(auth_role()) . ' · ' . source_label((string)$u['login_via'])) . '</div></div></div>'
        . $row('account', t('Mein Konto'), 'key', url(['p' => 'account']))
        . '<a class="node-row logout-link" href="' . h(url(['a' => 'logout'])) . '">' . icon('logout', 16, 'node-icon') . '<span class="node-label">' . th('Abmelden') . '</span></a>'
        . '</div></details>';
    echo '</div></header><div class="sidebar-scrim" id="sidebarScrim"></div>';

    // Werkzeugleiste unter der Kopfleiste: Pfad links, Schnell-Ping und Integrität rechts
    $last = integrity_last();
    echo '<div class="subbar"><div class="subbar-inner">';
    echo '<nav class="breadcrumb" aria-label="' . th('Pfad') . '">';
    $crumbs = $crumbs !== [] ? $crumbs : [[$title, null]];
    $lastIdx = count($crumbs) - 1;
    foreach ($crumbs as $i => [$label, $href]) {
        if ($i > 0) {
            echo '<span class="crumb-sep">' . icon('chevron', 11) . '</span>';
        }
        echo ($href !== null && $i !== $lastIdx) ? '<a href="' . h($href) . '">' . h($label) . '</a>' : '<span class="crumb-current">' . h($label) . '</span>';
    }
    echo '</nav><div class="subbar-tools">';
    if (can('ping')) {
        echo '<form class="quick-ping" id="quickPing" method="post" action="' . h(url(['a' => 'ping_target'])) . '">'
            . '<label class="scan-field">' . icon('pulse', 15) . '<input type="text" name="target" id="quickPingInput" placeholder="' . th('Schnell-Ping: IP oder Hostname') . '" autocomplete="off" spellcheck="false" aria-label="' . th('Schnell-Ping') . '" maxlength="253"></label>'
            . '<button class="pill-btn secondary small" type="submit">Ping</button></form>';
    }
    $pill = $last === null ? ['dim', 'shield', t('Integrität: ungeprüft')]
        : ($last['ok'] ? ['ok', 'shield', t('Integrität OK · {t}', ['t' => fmt_ago((int)$last['ts'])])] : ['bad', 'shield-x', t('Integrität verletzt')]);
    $open = can('integrity') ? 'a href="' . h(url(['p' => 'integrity'])) . '"' : 'span';
    $close = can('integrity') ? 'a' : 'span';
    echo '<' . $open . ' class="status-pill ' . $pill[0] . '" title="' . th('Letzte Integritätsprüfung') . '">' . icon($pill[1], 14) . '<span>' . h($pill[2]) . '</span></' . $close . '>';
    echo '</div></div></div>';

    echo '<main class="main" id="main"><div class="content" id="content">';
    foreach (flash_take() as $f) {
        $cls = in_array($f['type'], ['ok', 'error', 'info', 'warn'], true) ? $f['type'] : 'info';
        echo '<div class="flash flash-' . $cls . '" role="status">' . h($f['msg']) . '</div>';
    }
}

function page_end(): void
{
    echo '</div></main>';
    echo '<footer class="site-foot"><div class="site-foot-inner"><span>' . h(app_title()) . ' · v' . h(APP_VERSION) . '</span>'
        . '<span class="site-foot-copy">© ' . date('Y') . ' Lukas Geipel · ' . th('MIT-Lizenz') . '</span>' . lang_switch() . '</div></footer>';
    echo '<div class="ping-pop" id="pingPop" hidden><div class="ping-pop-head"><strong id="pingPopTitle">Ping</strong><button type="button" class="icon-btn" id="pingPopClose" aria-label="' . th('Schließen') . '">' . icon('x', 16) . '</button></div><pre class="console" id="pingPopOut"></pre></div>';
    echo '<div class="toast" id="toast" role="status" aria-live="polite"></div>';
    echo '</div><script src="' . h(asset_url('app.js')) . '"></script></body></html>';
}

function heading(string $title, string $sub = '', string $actions = ''): void
{
    echo '<div class="folder-heading"><div><h1>' . h($title) . '</h1>' . ($sub !== '' ? '<div class="folder-sub">' . $sub . '</div>' : '') . '</div>'
        . ($actions !== '' ? '<div class="folder-actions">' . $actions . '</div>' : '') . '</div>';
}

function btn(string $label, string $href, string $kind = 'secondary', string $ic = '', string $extra = ''): string
{
    return '<a class="pill-btn ' . $kind . '" href="' . h($href) . '"' . $extra . '>' . ($ic !== '' ? icon($ic, 15) : '') . h($label) . '</a>';
}

function post_button(string $label, array $params, string $kind = 'secondary', string $confirm = '', string $ic = '', array $fields = [], string $title = ''): string
{
    $hidden = csrf_field();
    foreach ($fields as $k => $v) {
        $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
    $c = $confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '';
    $t = $title !== '' ? ' title="' . h($title) . '" aria-label="' . h($title) . '"' : '';
    return '<form class="inline-form" method="post" action="' . h(url($params)) . '"' . $c . '>' . $hidden
        . '<button type="submit" class="pill-btn ' . $kind . '"' . $t . '>' . ($ic !== '' ? icon($ic, 15) : '') . h($label) . '</button></form>';
}

function badge(string $text, string $cls = 'dim'): string
{
    return '<span class="badge ' . $cls . '">' . h($text) . '</span>';
}

function render_error_page(string $title, string $message, int $code = 0): void
{
    if ($code > 0) {
        http_response_code($code);
    }
    html_head($title, 'login-body');
    echo '<main class="login-wrap"><div class="login-card wide"><div class="login-brand">' . icon('brand', 26, 'brand-mark') . '<span>' . h($title) . '</span></div>'
        . '<p class="login-sub">' . nl2br(h($message)) . '</p><a class="pill-btn secondary" href="./">' . th('Zur Startseite') . '</a></div></main></body></html>';
}

function render_login_page(?string $error, string $username = ''): void
{
    http_response_code($error !== null ? 401 : 200);
    html_head(t('Anmeldung'), 'login-body');
    $methods = [];
    if (setting('auth.local.enabled', true)) {
        $methods[] = t('lokales Konto');
    }
    if (setting('auth.pam.enabled')) {
        $methods[] = t('Systemkonto (PAM)');
    }
    if (setting('auth.ldap.enabled')) {
        $methods[] = setting('auth.ldap.mode') === 'ad' ? 'Active Directory' : 'LDAP';
    }
    echo '<main class="login-wrap"><div class="login-card"><div class="login-brand">' . icon('brand', 26, 'brand-mark') . '<span>' . h(app_title()) . '</span><span class="grow"></span>' . lang_switch() . '</div>';
    echo '<p class="login-sub">' . th('Anmeldung mit {m}.', ['m' => $methods !== [] ? implode(', ', $methods) : t('lokales Konto')]) . '</p>';
    foreach (flash_take() as $f) {
        echo '<p class="login-info">' . h($f['msg']) . '</p>';
    }
    if ($error !== null) {
        echo '<p class="login-error" role="alert">' . h($error) . '</p>';
    }
    $ph = setting('auth.ldap.enabled') && setting('auth.ldap.mode') === 'ad' ? 'max, max@' . setting('auth.ldap.ad_domain') . ' ' . t('oder') . ' ' . setting('auth.ldap.ad_netbios') . '\max' : '';
    echo '<form method="post" action="' . h(url(['a' => 'login'])) . '" autocomplete="off">' . csrf_field();
    echo '<label class="login-field"><span>' . th('Benutzername') . '</span><input type="text" name="username" value="' . h($username) . '" placeholder="' . h($ph) . '" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" required' . ($username === '' ? ' autofocus' : '') . '></label>';
    echo '<label class="login-field"><span>' . th('Passwort') . '</span><input type="password" name="password" autocomplete="current-password" required' . ($username !== '' ? ' autofocus' : '') . '></label>';
    if (setting('auth.pam_yubikey.enabled')) {
        echo '<label class="login-field"><span>YubiKey <em>' . th('(nur bei PAM + YubiKey: hineinklicken, Key berühren)') . '</em></span><input type="password" name="otp" autocomplete="one-time-code" maxlength="128"></label>';
    }
    echo '<button type="submit" class="pill-btn primary login-submit">' . th('Anmelden') . '</button></form>';
    echo '<p class="login-foot">' . th('Konten mit registriertem YubiKey werden nach dem Passwort zum Berühren des Keys aufgefordert. Jede Anmeldung wird protokolliert.') . '</p>';
    echo '</div></main></body></html>';
}

function render_2fa_page(array $pending): void
{
    $user = $pending['user'];
    $f = auth_user_factors((int)$user['id']);
    html_head('YubiKey', 'login-body');
    echo '<main class="login-wrap"><div class="login-card" id="waLogin" data-options="' . h(url(['a' => 'wa_login_options'])) . '" data-finish="' . h(url(['a' => 'wa_login_finish'])) . '"' . ($f['webauthn'] > 0 ? ' data-auto="1"' : '') . '>';
    echo '<div class="login-brand">' . icon('key', 26, 'brand-mark') . '<span>' . th('YubiKey bestätigen') . '</span></div>';
    echo '<p class="login-sub">' . th('Hallo {n} – zweiter Schritt der Anmeldung.', ['n' => (string)($user['display_name'] ?: $user['username'])]) . '</p>';
    $err = $_SESSION['twofa_error'] ?? null;
    unset($_SESSION['twofa_error']);
    echo '<p class="login-error" id="waError" role="alert"' . ($err ? '' : ' hidden') . '>' . h((string)$err) . '</p>';
    if ($f['webauthn'] > 0) {
        echo '<div class="wa-pulse" aria-hidden="true">' . icon('key', 42) . '</div>';
        echo '<button type="button" class="pill-btn primary login-submit" id="waStart">' . th('YubiKey berühren (FIDO2)') . '</button>';
    }
    if ($f['otp'] > 0) {
        if ($f['webauthn'] > 0) {
            echo '<div class="login-or"><span>' . th('oder') . '</span></div>';
        }
        echo '<form method="post" action="' . h(url(['a' => 'login_otp'])) . '" autocomplete="off">' . csrf_field()
            . '<label class="login-field"><span>Yubico-OTP <em>' . th('(ins Feld klicken, Key berühren)') . '</em></span><input type="password" name="otp" maxlength="64" autocomplete="one-time-code"' . ($f['webauthn'] === 0 ? ' autofocus' : '') . ' required></label>'
            . '<button type="submit" class="pill-btn ' . ($f['webauthn'] > 0 ? 'secondary' : 'primary') . ' login-submit">' . th('Bestätigen') . '</button></form>';
    }
    echo '<p class="login-foot"><a href="' . h(url(['a' => 'logout'])) . '">' . th('Abbrechen') . '</a></p>';
    echo '</div></main><script src="' . h(asset_url('app.js')) . '"></script></body></html>';
}

function render_setup_page(?string $error): void
{
    html_head(t('Ersteinrichtung'), 'login-body');
    echo '<main class="login-wrap"><div class="login-card"><div class="login-brand">' . icon('brand', 26, 'brand-mark') . '<span>' . th('Ersteinrichtung') . '</span><span class="grow"></span>' . lang_switch() . '</div>';
    echo '<p class="login-sub">' . th('Noch kein Benutzer vorhanden. Lege das Passwort für das lokale Konto „Administrator“ fest (database/users.sql). Der Integritätsschlüssel wurde soeben erzeugt (Teil 1 in der System-DB, Teil 2 in config/integrity.key) – beide getrennt sichern.') . '</p>';
    if ($error !== null) {
        echo '<p class="login-error" role="alert">' . h($error) . '</p>';
    }
    echo '<form method="post" action="' . h(url(['a' => 'setup'])) . '">' . csrf_field();
    echo '<label class="login-field"><span>' . th('Benutzername') . '</span><input type="text" value="Administrator" disabled></label>';
    echo '<label class="login-field"><span>' . th('Passwort (mind. 12 Zeichen, 3 Zeichenarten)') . '</span><input type="password" name="password" autocomplete="new-password" minlength="12" required autofocus></label>';
    echo '<label class="login-field"><span>' . th('Passwort wiederholen') . '</span><input type="password" name="password2" autocomplete="new-password" minlength="12" required></label>';
    echo '<button type="submit" class="pill-btn primary login-submit">' . th('Einrichten und anmelden') . '</button></form></div></main></body></html>';
}

// --- Formular-Bausteine (Beschriftungen werden vom Aufrufer übersetzt) ---------------------------

function f_text(string $name, string $label, mixed $value, array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $attrs = '';
    foreach (['required', 'readonly', 'autofocus', 'disabled'] as $b) {
        if (!empty($o[$b])) {
            $attrs .= ' ' . $b;
        }
    }
    foreach (['placeholder', 'maxlength', 'minlength', 'min', 'max', 'step', 'pattern', 'inputmode', 'list', 'autocomplete', 'id', 'title'] as $k) {
        if (isset($o[$k])) {
            $attrs .= ' ' . $k . '="' . h((string)$o[$k]) . '"';
        }
    }
    if (!isset($o['autocomplete'])) {
        $attrs .= ' autocomplete="off"';
    }
    $hint = isset($o['hint']) && $o['hint'] !== '' ? '<small>' . $o['hint'] . '</small>' : '';
    return '<label class="field' . (isset($o['class']) ? ' ' . $o['class'] : '') . '"><span>' . h($label) . '</span><input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"' . $attrs . '>' . $hint . '</label>';
}

function f_select(string $name, string $label, array $options, mixed $value, array $o = []): string
{
    $html = '<label class="field' . (isset($o['class']) ? ' ' . $o['class'] : '') . '"><span>' . h($label) . '</span><select name="' . h($name) . '"' . (isset($o['id']) ? ' id="' . h($o['id']) . '"' : '') . '>';
    $list = array_is_list($options);
    foreach ($options as $k => $v) {
        $key = $list ? (string)$v : (string)$k;
        $html .= '<option value="' . h($key) . '"' . ($key === (string)$value ? ' selected' : '') . '>' . h((string)$v) . '</option>';
    }
    return $html . '</select>' . (!empty($o['hint']) ? '<small>' . $o['hint'] . '</small>' : '') . '</label>';
}

function f_textarea(string $name, string $label, mixed $value, int $rows = 3, array $o = []): string
{
    return '<label class="field wide' . (isset($o['class']) ? ' ' . $o['class'] : '') . '"><span>' . h($label) . '</span><textarea name="' . h($name) . '" rows="' . $rows . '"'
        . (isset($o['placeholder']) ? ' placeholder="' . h($o['placeholder']) . '"' : '') . (!empty($o['mono']) ? ' class="mono" spellcheck="false"' : '') . '>' . h($value) . '</textarea>'
        . (!empty($o['hint']) ? '<small>' . $o['hint'] . '</small>' : '') . '</label>';
}

function f_check(string $name, string $label, bool $checked, string $hint = ''): string
{
    return '<label class="check"><input type="checkbox" name="' . h($name) . '" value="1"' . ($checked ? ' checked' : '') . '><span>' . h($label) . '</span>' . ($hint !== '' ? '<small>' . $hint . '</small>' : '') . '</label>';
}

function kv_list(array $rows): string
{
    $o = '<dl class="kv">';
    foreach ($rows as $k => $v) {
        $o .= '<dt>' . h($k) . '</dt><dd>' . ($v === null || $v === '' ? '<span class="dim">–</span>' : $v) . '</dd>';
    }
    return $o . '</dl>';
}

function copy_btn(string $text, string $label = ''): string
{
    $label = $label !== '' ? $label : t('Kopieren');
    return '<button type="button" class="icon-btn copy-btn" data-copy="' . h($text) . '" title="' . h($label) . '" aria-label="' . h($label) . '">' . icon('copy', 14) . '</button>';
}

/** Sprache umschalten (?a=lang&l=en&back=…). */
function action_lang(): never
{
    lang_set(get_str('l', 2));
    parse_str(get_str('back', 500), $params);
    unset($params['a']);
    redirect(url(array_map(static fn($v) => is_string($v) ? $v : null, $params)));
}
