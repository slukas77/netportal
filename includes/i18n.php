<?php
/**
 * Mehrsprachigkeit: Deutsch (Quellsprache) und Englisch (en_US).
 *
 * Vorwahl: gewählte Sprache (Cookie) → sonst Browser (Accept-Language):
 * bevorzugt der Browser Deutsch, wird Deutsch gezeigt, sonst Englisch.
 * Umschalten jederzeit in der Fußzeile bzw. auf der Login-Seite.
 *
 * Texte werden im Code auf Deutsch geschrieben: t('Netzwerk gespeichert.')
 * Platzhalter: t('{n} Hosts', ['n' => 5]). Die englischen Texte stehen in
 * includes/lang/en.php (Schlüssel = deutscher Text). Fehlt eine Übersetzung,
 * erscheint der deutsche Text.
 */

declare(strict_types=1);

const LANGS = ['de' => 'Deutsch', 'en' => 'English (US)'];

function lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    if (PHP_SAPI === 'cli') {
        $env = strtolower((string)(getenv('NETPORTAL_LANG') ?: substr((string)getenv('LANG'), 0, 2)));
        return $lang = $env === 'en' ? 'en' : 'de';
    }
    $c = (string)($_COOKIE['np_lang'] ?? '');
    if (isset(LANGS[$c])) {
        return $lang = $c;
    }
    return $lang = lang_from_header((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
}

/** Accept-Language auswerten (mit q-Werten). Deutsch nur, wenn der Browser es vor Englisch nennt. */
function lang_from_header(string $h): string
{
    $best = ['de' => -1.0, 'en' => -1.0];
    foreach (explode(',', $h) as $i => $part) {
        $bits = explode(';', trim($part));
        $code = strtolower(substr(trim($bits[0]), 0, 2));
        $q = 1.0;
        foreach (array_slice($bits, 1) as $b) {
            if (preg_match('/^\s*q=([0-9.]+)/', $b, $m)) {
                $q = (float)$m[1];
            }
        }
        $q -= $i * 0.0001; // Reihenfolge als Gleichstand-Entscheidung
        if (isset($best[$code]) && $q > $best[$code]) {
            $best[$code] = $q;
        }
    }
    return $best['de'] > $best['en'] ? 'de' : 'en';
}

function lang_set(string $l): void
{
    if (!isset(LANGS[$l])) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    setcookie('np_lang', $l, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}

function lang_table(): array
{
    static $tab = null;
    if ($tab === null) {
        $tab = lang() === 'en' && is_file(APP_ROOT . '/includes/lang/en.php') ? (array)require APP_ROOT . '/includes/lang/en.php' : [];
    }
    return $tab;
}

/** Übersetzen. $p ersetzt {name}-Platzhalter. */
function t(string $de, array $p = []): string
{
    $s = lang_table()[$de] ?? $de;
    if ($p !== []) {
        $r = [];
        foreach ($p as $k => $v) {
            $r['{' . $k . '}'] = (string)$v;
        }
        $s = strtr($s, $r);
    }
    return $s;
}

/** Wie t(), Ergebnis HTML-escaped. */
function th(string $de, array $p = []): string
{
    return h(t($de, $p));
}

/** Datum/Uhrzeit je Sprache (de: 12.10.2026 14:05 · en: 10/12/2026 2:05 PM). */
function fmt_dt(int $ts, bool $time = true, bool $seconds = false): string
{
    if (lang() === 'en') {
        return date($time ? ($seconds ? 'm/d/Y g:i:s A' : 'm/d/Y g:i A') : 'm/d/Y', $ts);
    }
    return date($time ? ($seconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i') : 'd.m.Y', $ts);
}

function fmt_num(float|int $n, int $dec = 0): string
{
    return lang() === 'en' ? number_format((float)$n, $dec, '.', ',') : number_format((float)$n, $dec, ',', '.');
}

/** Texte, die das Frontend (app.js) braucht – als JSON-Block in die Seite. */
const JS_STRINGS = [
    'Kopiert: {v}', 'Kopieren nicht möglich', '{n} Einträge', 'online', 'offline', 'gerade eben', 'keine', 'Ping läuft …',
    '✓ erreichbar', '✗ nicht erreichbar', '(TCP-Ping)', 'zum Host', 'läuft …', '{a} von {b} Hosts online',
    '✓ {t} antwortet · {ms} ms', '✗ {t} antwortet nicht', '{n} Dienst(e) nicht erreichbar', 'Alle prüfbaren Dienste erreichbar',
    'Prüfe Ports …', '{n} offene Ports', 'geprüft', 'geschlossen', 'gefiltert', 'bereits erfasst', 'Keine offenen Ports gefunden.',
    'Ausgewählte als Dienste übernehmen', 'Schließen', 'Nichts ausgewählt', '{n} Dienst(e) übernommen', 'Dienst bearbeiten', 'Dienst hinzufügen',
    'Verbinde …', 'bekannt', 'ABWEICHEND von known_hosts', 'noch nicht bekannt', 'Host-Keys konnten nicht gelesen werden (ssh-keyscan fehlt?).',
    'Mindestens eine Zeile', 'Erste VLAN-ID fehlt', '{n} Zeilen erzeugt – prüfen und „Alle anlegen“', 'Teste Verbindung …',
    'Dieser Browser unterstützt keine Sicherheitsschlüssel (oder die Seite läuft nicht über HTTPS).', 'Abgebrochen oder Zeit abgelaufen – bitte erneut versuchen.',
    'YubiKey „{n}“ registriert', 'Dieser Key ist bereits registriert.', 'Abgebrochen oder Zeit abgelaufen.', 'Unerwartete Antwort ({s})', 'Fehler {s}',
    'iperf3 läuft ({s} s) …', 'Senden', 'Empfangen', 'Wiederholungen', 'Jitter', 'Verlust', 'Pakete', 'Ermittle MAC und Hardware …',
    'Ermittelt', 'nicht gefunden', 'Installation läuft …', 'Installation abgeschlossen', 'Installation fehlgeschlagen', 'Prüfe …',
    'Port {p}: {s}', 'Dienste werden geprüft …', 'Fehler',
    'kein CIDR hinterlegt', 'kein Gateway hinterlegt', 'Versatz liegt außerhalb des Netzes', 'wird entfernt', 'nicht möglich', 'IP belegt',
    'vorhanden – wird abgeglichen', 'wird angelegt', 'wird beim Speichern geprüft', 'gültig', '{n} Fehler', 'Neue Site',
    'Verbindung beendet', 'wegen Inaktivität beendet', 'Höchstdauer erreicht', 'zu viel Ausgabe – Sitzung beendet', 'getrennt', 'verbunden',
    'Verbindung zum Portal verloren', 'Sitzung nicht gefunden.', 'Zuletzt:', 'Verbinden', 'Verbinde mit {t} …', '{ok} verfügbar · {bad} nicht verfügbar · {unk} unbekannt', 'Bitte Pakete auswählen',
    '{a} von {b} Anschlüssen erreichbar', 'Anzeigen', 'Verbergen', 'Werte der Vorlage übernommen',
];

function js_strings_json(): string
{
    $out = [];
    foreach (JS_STRINGS as $s) {
        $out[$s] = t($s);
    }
    return (string)json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}
