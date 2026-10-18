<?php
/**
 * Richtet den root-Helfer für „System & Pakete“ ein (einmalig, als root):
 *
 *   sudo php system/install.php                  # PHP-FPM läuft als www-data
 *   sudo php system/install.php caddy            # anderer PHP-FPM-Benutzer
 *   sudo php system/install.php --uninstall
 *
 * Danach kann ein Portal-Admin unter „System & Pakete“ fehlende PHP-Module und
 * Pakete (feste Liste, nur Debian/Ubuntu) installieren und den PAM-Helfer bauen.
 */

declare(strict_types=1);

const SBIN = '/usr/local/sbin/netportal-admin';
const SHARE = '/usr/local/share/netportal';
const SUDOERS = '/etc/sudoers.d/netportal';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (posix_geteuid() !== 0) {
    fwrite(STDERR, "bitte als root: sudo php system/install.php\n");
    exit(1);
}
$arg = $argv[1] ?? 'www-data';
if ($arg === '--uninstall') {
    @unlink(SBIN);
    @unlink(SUDOERS);
    foreach (glob(SHARE . '/pam-helper/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir(SHARE . '/pam-helper');
    @rmdir(SHARE);
    echo "entfernt\n";
    exit(0);
}
if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $arg) || posix_getpwnam($arg) === false) {
    fwrite(STDERR, "unbekannter Benutzer: $arg\n");
    exit(1);
}
$php = PHP_BINARY;
$src = __DIR__;
$app = dirname(__DIR__);

// Helfer: feste Shebang-Zeile mit dem PHP dieses Systems
$code = (string)file_get_contents($src . '/netportal-admin');
$code = preg_replace('/^#!.*\n/', '#!' . $php . "\n", $code, 1);
file_put_contents(SBIN, $code);
chown(SBIN, 0);
chgrp(SBIN, 0);
chmod(SBIN, 0755);

// Quellen des PAM-Helfers an einen Ort, den der Webserver nicht verändern kann
@mkdir(SHARE . '/pam-helper', 0755, true);
foreach (['pam_check.c', 'netportal.pam', 'netportal-yubikey.pam'] as $f) {
    copy($app . '/pam-helper/' . $f, SHARE . '/pam-helper/' . $f);
    chmod(SHARE . '/pam-helper/' . $f, 0644);
}

// sudoers: nur dieses eine Programm, ohne Passwort, nur für den Webserver-Benutzer
// ohne "Defaults … !requiretty": unter Debian/Ubuntu ohnehin aus, und sudo-rs (Ubuntu 26.04) kennt die Einstellung nicht
$rule = "# Network Portal: root-Helfer (nur dieses Programm)\n" . $arg . ' ALL=(root) NOPASSWD: ' . SBIN . "\n";
$tmp = SUDOERS . '.tmp';
file_put_contents($tmp, $rule);
chmod($tmp, 0440);
exec('/usr/sbin/visudo -cf ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
if ($rc !== 0) {
    @unlink($tmp);
    fwrite(STDERR, "sudoers-Prüfung fehlgeschlagen:\n" . implode("\n", $out) . "\n");
    exit(1);
}
rename($tmp, SUDOERS);
echo "Fertig: " . SBIN . " (PHP: $php) – erlaubt für Benutzer $arg (" . SUDOERS . ")\n";
