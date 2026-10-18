<?php
/**
 * Seite „SSH-Terminal“: Host und Benutzer auswählen, verbinden, mehrere
 * Sitzungen als Tabs. Aufruf auch direkt aus jedem Eintrag (?p=terminal&n=…&id=…).
 */

declare(strict_types=1);

function page_terminal(): void
{
    $targets = terminal_targets();
    $selNet = get_str('n', 60);
    $selId = (int)($_GET['id'] ?? 0);
    $sel = null;
    if ($selNet !== '' && isset($targets[$selNet])) {
        foreach ($targets[$selNet]['hosts'] as $h) {
            if ($h['id'] === $selId) {
                $sel = $h;
            }
        }
    }
    $problems = terminal_problems();
    $keyOk = terminal_key_allowed();
    $popup = get_str('popup', 1) === '1';
    page_start(t('SSH-Terminal'), 'terminal', [[t('SSH-Terminal'), null]]);
    if ($problems !== []) {
        echo '<div class="flash flash-warn">' . th('Das SSH-Terminal ist noch nicht einsatzbereit:') . ' ' . h(implode(' · ', $problems))
            . (can('system') ? ' · <a href="' . h(url(['p' => 'system'])) . '">' . th('System & Pakete') . '</a>' : '') . '</div>';
    }
    $defUser = (string)setting('ssh.default_user', 'root');
    $opts = '';
    foreach ($targets as $slug => $grp) {
        $opts .= '<optgroup label="' . h($grp['name']) . '">';
        foreach ($grp['hosts'] as $h) {
            $val = $slug . ':' . $h['id'];
            $opts .= '<option value="' . h($val) . '" data-user="' . h($h['user']) . '" data-port="' . (int)$h['port'] . '" data-label="' . h($h['name']) . '"'
                . ($sel !== null && $selNet === $slug && $sel['id'] === $h['id'] ? ' selected' : '') . '>' . h($h['name'] . ($h['ip'] !== '' && $h['ip'] !== $h['name'] ? ' · ' . $h['ip'] : '')) . '</option>';
        }
        $opts .= '</optgroup>';
    }
    if (can('ssh_free')) {
        $opts .= '<option value="@free">' . th('— freies Ziel (IP oder Name) —') . '</option>';
    }
    $users = array_values(array_unique(array_filter([$sel['user'] ?? '', $defUser, 'root', 'admin', 'administrator', 'ubuntu', 'debian', 'pi'])));
    echo '<section class="card term-connect" id="termConnect" data-open="' . h(url(['a' => 'term_open'])) . '" data-read="' . h(url(['a' => 'term_read'])) . '"'
        . ' data-write="' . h(url(['a' => 'term_write'])) . '" data-resize="' . h(url(['a' => 'term_resize'])) . '" data-close="' . h(url(['a' => 'term_close'])) . '"'
        . ' data-default-user="' . h($defUser) . '" data-auto="' . ($sel !== null && get_str('auto', 1) === '1' ? '1' : '0') . '">'
        . '<form id="termForm" class="term-form" autocomplete="off">'
        . '<label class="field term-host"><span>' . th('Host') . '</span><input type="search" id="termHostFilter" placeholder="' . th('Host suchen …') . '" aria-label="' . th('Host suchen …') . '">'
        . '<select id="termHost" name="host" required>' . ($sel === null ? '<option value="">' . th('— Eintrag wählen —') . '</option>' : '') . $opts . '</select></label>'
        . '<label class="field term-free" hidden><span>' . th('Freies Ziel') . '</span><input type="text" name="target" maxlength="255" placeholder="10.0.0.5 / host.firma.local" spellcheck="false"></label>'
        . '<label class="field term-user"><span>' . th('Benutzer') . '</span><input type="text" id="termUser" name="user" list="termUsers" maxlength="64" value="' . h(($sel['user'] ?? '') !== '' ? $sel['user'] : $defUser) . '" spellcheck="false" required></label>'
        . '<datalist id="termUsers">' . implode('', array_map(static fn($u) => '<option value="' . h($u) . '">', $users)) . '</datalist>'
        . '<label class="field term-port"><span>Port</span><input type="text" id="termPort" name="port" inputmode="numeric" maxlength="5" value="' . (int)($sel['port'] ?? 22) . '"></label>'
        . '<label class="field term-auth"><span>' . th('Anmeldung') . '</span><select name="auth" id="termAuth"><option value="password">' . th('Passwort (im Terminal)') . '</option>'
        . ($keyOk ? '<option value="key">' . th('Portal-Schlüssel') . '</option>' : '') . '</select></label>'
        . '<div class="field term-go"><span>&nbsp;</span><button class="pill-btn primary" type="submit"' . ($problems !== [] ? ' disabled' : '') . '>' . icon('terminal', 15) . th('Verbinden') . '</button></div>'
        . '</form><div class="term-recent" id="termRecent" aria-label="' . th('Zuletzt verbunden') . '"></div></section>';

    echo '<section class="term-panel" id="termPanel" hidden><div class="term-tabs" id="termTabs" role="tablist"></div>'
        . '<div class="term-toolbar"><span class="term-state" id="termState"></span><span class="grow"></span>'
        . '<button type="button" class="icon-btn" id="termFontDown" title="' . th('Schrift kleiner') . '" aria-label="' . th('Schrift kleiner') . '">A−</button>'
        . '<button type="button" class="icon-btn" id="termFontUp" title="' . th('Schrift größer') . '" aria-label="' . th('Schrift größer') . '">A+</button>'
        . '<button type="button" class="icon-btn" id="termPaste" title="' . th('Einfügen') . '" aria-label="' . th('Einfügen') . '">' . icon('copy', 15) . '</button>'
        . ($popup ? '' : '<button type="button" class="icon-btn" id="termPopout" title="' . th('In neuem Fenster') . '" aria-label="' . th('In neuem Fenster') . '">' . icon('external', 15) . '</button>')
        . '<button type="button" class="icon-btn" id="termFull" title="' . th('Vollbild') . '" aria-label="' . th('Vollbild') . '">' . icon('maximize', 15) . '</button>'
        . '<button type="button" class="pill-btn danger small" id="termDisconnect">' . icon('power', 14) . th('Trennen') . '</button></div>'
        . '<div class="term-screens" id="termScreens"></div></section>';
    echo '<p class="hint term-hint">' . th('Das Passwort tippst du direkt im Terminal – es wird weder gespeichert noch protokolliert. Kopieren: Text markieren · Einfügen: Strg+Umschalt+V oder Knopf. Jede Sitzung (Ziel, Benutzer, Dauer) steht im Audit-Log.') . '</p>';
    echo '<script src="' . h(asset_url('terminal.js')) . '"></script>';
    page_end();
}
