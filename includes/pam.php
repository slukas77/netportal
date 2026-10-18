<?php
/**
 * PAM-Anbindung über ein separates setuid-root-Programm (pam-helper/) – php-fpm
 * selbst bekommt nie erhöhte Rechte.
 *
 * Zwei Dienste:
 *   std      -> /etc/pam.d/netportal          (Passwort: pam_unix, sssd, winbind …)
 *   yubikey  -> /etc/pam.d/netportal-yubikey  (Passwort + YubiKey: pam_yubico (OTP) / pam_u2f)
 *
 * Mechanismus (Einstellungen → Anmeldung):
 *   helper     eigener Helfer pam-helper/pam_check.c (empfohlen; Dienstnamen fest einkompiliert)
 *   pamtester  Standardwerkzeug "pamtester" (setuid-root installiert)
 *
 * Protokoll zum Helfer, nur über stdin (nie argv/env):
 *   Zeile 1 Dienst ("std"|"yubikey"), Zeile 2 Benutzer, Zeile 3 OTP (leer erlaubt), Rest Passwort
 * Exit-Codes: 0 ok, 1 falsche Daten, 2 Konto abgelehnt, 3 Eingabe ungültig, 4 interner Fehler
 */

declare(strict_types=1);

const PAM_TIMEOUT_SECONDS = 15;

function pam_authenticate_user(string $serviceKey, string $user, string $pass, string $otp): int
{
    if (!function_exists('proc_open')) {
        error_log('netportal: proc_open ist deaktiviert – PAM nicht möglich');
        return 4;
    }
    if ((string)setting('auth.pam.mechanism', 'helper') === 'pamtester') {
        $service = (string)setting($serviceKey === 'yubikey' ? 'auth.pam_yubikey.service' : 'auth.pam.service');
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $service)) {
            return 4;
        }
        $bin = (string)setting('auth.pam.pamtester_path', '/usr/bin/pamtester');
        $stdin = $pass . "\n" . ($otp !== '' ? $otp . "\n" : '');
        [$code, $out] = proc_run([$bin, $service, $user, 'authenticate', 'acct_mgmt'], $stdin, PAM_TIMEOUT_SECONDS);
        if ($code === 0) {
            return 0;
        }
        if ($code < 0 || $code > 250) {
            return 4;
        }
        return stripos($out, 'successfully authenticated') !== false ? 2 : 1;
    }
    $bin = (string)setting('auth.pam.helper_path', '/usr/local/sbin/netportal-pam-check');
    [$code] = proc_run([$bin], $serviceKey . "\n" . $user . "\n" . $otp . "\n" . $pass, PAM_TIMEOUT_SECONDS);
    return ($code >= 0 && $code <= 4) ? $code : 4;
}

/**
 * Startet ein Programm OHNE Shell (Argumente als Liste), schreibt stdin,
 * wartet mit Timeout. Liefert [exitcode (-1 = Start fehlgeschlagen, -2 = Timeout), stdout, stderr, ms].
 */
function proc_run(array $cmd, string $stdin = '', float $timeout = 10.0, int $maxOut = 262144): array
{
    $bin = (string)$cmd[0];
    if (!is_file($bin) || !is_executable($bin)) {
        return [-1, '', t('Programm nicht gefunden oder nicht ausführbar: {p}', ['p' => $bin]), 0];
    }
    $t0 = microtime(true);
    $env = ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C', 'HOME' => APP_ROOT . '/config'];
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return [-1, '', 'proc_open fehlgeschlagen', 0];
    }
    if ($stdin !== '') {
        @fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $timedOut = false;
    while (true) {
        $st = proc_get_status($proc);
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        if (strlen($out) > $maxOut) {
            $out = substr($out, 0, $maxOut) . "\n[… " . t('Ausgabe gekürzt') . ']';
            proc_terminate($proc, 9);
            break;
        }
        if (!$st['running']) {
            break;
        }
        if (microtime(true) - $t0 > $timeout) {
            proc_terminate($proc, 9);
            $timedOut = true;
            break;
        }
        $r = [$pipes[1], $pipes[2]];
        $w = $e = null;
        @stream_select($r, $w, $e, 0, 50000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if (isset($st) && !$st['running'] && $st['exitcode'] !== -1) {
        $code = (int)$st['exitcode'];
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    return [$timedOut ? -2 : $code, $out, $err, $ms];
}

function pam_diagnose(): array
{
    $rows = [];
    $mech = (string)setting('auth.pam.mechanism', 'helper');
    $bin = $mech === 'pamtester' ? (string)setting('auth.pam.pamtester_path') : (string)setting('auth.pam.helper_path');
    $exists = is_file($bin);
    $setuid = $exists && (fileperms($bin) & 04000) && fileowner($bin) === 0;
    $rows[] = ['label' => ($mech === 'pamtester' ? 'pamtester' : t('PAM-Helfer')) . ' ' . $bin, 'ok' => $exists && $setuid,
        'detail' => !$exists ? t('nicht vorhanden – unter System „PAM-Helfer installieren“') : ($setuid ? t('setuid root – ok') : t('nicht setuid root (chmod 4755, Besitzer root)'))];
    foreach (['std' => 'netportal', 'yubikey' => 'netportal-yubikey'] as $k => $svc) {
        $name = $mech === 'helper' ? $svc : (string)setting($k === 'std' ? 'auth.pam.service' : 'auth.pam_yubikey.service');
        $f = '/etc/pam.d/' . $name;
        $rows[] = ['label' => t('PAM-Dienst') . ' ' . $f, 'ok' => is_file($f), 'detail' => is_file($f) ? t('vorhanden') : t('fehlt')];
    }
    $rows[] = ['label' => 'proc_open', 'ok' => function_exists('proc_open'), 'detail' => function_exists('proc_open') ? t('verfügbar') : t('in disable_functions gesperrt')];
    return $rows;
}

/** PAM-Tester (System-Seite): prüft Zugangsdaten gegen einen der beiden Dienste, ohne anzumelden. */
function pam_test(string $service, string $user, string $pass, string $otp): array
{
    $t0 = microtime(true);
    $code = pam_authenticate_user($service === 'yubikey' ? 'yubikey' : 'std', $user, $pass, $otp);
    $text = match ($code) {
        0 => t('Erfolg: Benutzer und Passwort (und ggf. YubiKey) wurden von PAM akzeptiert.'),
        1 => t('Abgelehnt: falsche Zugangsdaten (oder YubiKey-OTP ungültig).'),
        2 => t('Zugangsdaten korrekt, aber Konto abgelehnt (gesperrt/abgelaufen, account-Phase).'),
        3 => t('Eingabe ungültig (Benutzername/OTP-Format).'),
        default => t('Interner Fehler: Helfer fehlt, ist nicht setuid root oder der PAM-Dienst fehlt.'),
    };
    return ['code' => $code, 'ok' => $code === 0, 'text' => $text, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}
