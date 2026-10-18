<?php
/**
 * Lädt alle Bausteine in fester Reihenfolge (Web und CLI).
 * Voraussetzung: die Konstanten aus index.php bzw. bin/netportal-cli.php
 * und includes/compat.php sind bereits geladen.
 */

declare(strict_types=1);

foreach ([
    'util', 'i18n', 'settings', 'sysdb', 'audit', 'integrity', 'ipcalc', 'sqlfile', 'store',
    'netstore', 'userstore', 'masters', 'auth', 'pam', 'ldap', 'webauthn', 'yubico', 'nettools',
    'iconset', 'icons', 'syscheck', 'terminal', 'caddy', 'switches', 'wan', 'netmap', 'diagnose', 'layout',
] as $__inc) {
    require_once APP_ROOT . '/includes/' . $__inc . '.php';
}
foreach (['networks', 'hosts', 'users', 'settings', 'system', 'integrity', 'audit', 'masters', 'terminal', 'caddy', 'switches', 'wan', 'netmap', 'diagnose'] as $__inc) {
    require_once APP_ROOT . '/includes/pages/' . $__inc . '.php';
}
unset($__inc);
