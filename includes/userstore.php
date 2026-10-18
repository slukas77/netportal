<?php
/**
 * Benutzer des Portals: database/users.sql
 *
 * Alle Portal-Konten (lokale Anmeldung mit Passwort, aber auch die Rollen-
 * Einträge von PAM- und LDAP-Benutzern) sowie die registrierten YubiKeys
 * stehen in EINER SQL-Datei mit derselben Integritätssicherung wie die
 * Netzwerke: HMAC je Zeile, HMAC je Datei, Siegel in der System-DB.
 *
 * Wird ein Benutzer-Eintrag am Portal vorbei verändert (z. B. Rolle oder
 * Passwort-Hash), stimmt sein Hash nicht mehr – mit diesem Konto ist dann keine
 * Anmeldung mehr möglich, bis ein Portal-Admin die Datei geprüft und neu
 * versiegelt hat.
 */

declare(strict_types=1);

const USERS_NS = '@users';
const USERS_SEAL = 'users.sql';

const USERS_SCHEMA = [
    'users' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'username' => 'TEXT NOT NULL',
        'display_name' => 'TEXT',
        'email' => 'TEXT',
        'auth_source' => "TEXT NOT NULL DEFAULT 'local'",
        'password_hash' => 'TEXT',
        'role' => "TEXT NOT NULL DEFAULT 'leser'",
        'active' => 'INTEGER NOT NULL DEFAULT 1',
        'must_change_pw' => 'INTEGER NOT NULL DEFAULT 0',
        'require_2fa' => 'INTEGER NOT NULL DEFAULT 0',
        'last_login' => 'TEXT',
        'created_at' => 'TEXT', 'created_by' => 'TEXT', 'updated_at' => 'TEXT', 'updated_by' => 'TEXT',
        'row_hash' => 'TEXT NOT NULL',
    ],
    'webauthn_credentials' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'user_id' => 'INTEGER NOT NULL REFERENCES users(id)',
        'credential_id' => 'TEXT NOT NULL',
        'public_key' => 'TEXT NOT NULL',
        'alg' => 'INTEGER NOT NULL',
        'sign_count' => 'INTEGER NOT NULL DEFAULT 0',
        'name' => 'TEXT',
        'created_at' => 'TEXT',
        'last_used' => 'TEXT',
        'row_hash' => 'TEXT NOT NULL',
    ],
    'yubico_otp_keys' => [
        'id' => 'INTEGER NOT NULL PRIMARY KEY',
        'user_id' => 'INTEGER NOT NULL REFERENCES users(id)',
        'public_id' => 'TEXT NOT NULL',
        'name' => 'TEXT',
        'created_at' => 'TEXT',
        'last_used' => 'TEXT',
        'row_hash' => 'TEXT NOT NULL',
    ],
];
const USERS_INDEXES = [
    'CREATE UNIQUE INDEX ix_users_name ON users (username);',
    'CREATE INDEX ix_wa_user ON webauthn_credentials (user_id);',
    'CREATE INDEX ix_otp_user ON yubico_otp_keys (user_id);',
];

function users_file(): string
{
    return DB_DIR . '/users.sql';
}

/** Lädt users.sql (legt sie beim ersten Start an bzw. übernimmt Konten aus älteren Versionen). */
function users_store(bool $fresh = false): array
{
    static $st = null;
    if ($st !== null && !$fresh) {
        return $st;
    }
    if (!is_file(users_file())) {
        users_bootstrap();
    }
    $r = sqlfile_read(users_file(), USERS_SCHEMA);
    $st = $r['tables'] + ['sha256' => $r['sha256'], 'bytes' => $r['bytes'], 'parse' => $r['parse']];
    return $st;
}

/** Erste Anlage von users.sql; übernimmt Benutzer aus der System-DB älterer Versionen (1.0). */
function users_bootstrap(): void
{
    $lock = sqlfile_lock('users');
    try {
        if (is_file(users_file())) {
            return;
        }
        $st = array_fill_keys(array_keys(USERS_SCHEMA), []);
        $migrated = 0;
        try {
            $hasOld = (int)sys_val("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'") > 0;
            if ($hasOld) {
                foreach (sys_all('SELECT * FROM users ORDER BY id') as $u) {
                    $u['role'] = $u['role'] === 'operator' ? 'poweruser' : $u['role'];
                    users_put($st, 'users', $u);
                    $migrated++;
                }
                foreach (sys_all('SELECT * FROM webauthn_credentials ORDER BY id') as $k) {
                    users_put($st, 'webauthn_credentials', $k);
                }
                foreach (sys_all('SELECT * FROM yubico_otp_keys ORDER BY id') as $k) {
                    users_put($st, 'yubico_otp_keys', $k);
                }
            }
        } catch (Throwable $e) {
            error_log('netportal: Übernahme alter Benutzer: ' . $e->getMessage());
        }
        users_save($st, $migrated > 0 ? 'migration' : 'setup');
        if ($migrated > 0) {
            sys()->exec('DROP TABLE IF EXISTS webauthn_credentials; DROP TABLE IF EXISTS yubico_otp_keys; DROP TABLE IF EXISTS users;');
            audit('user_update', ['quelle' => 'Migration', 'benutzer_nach_users_sql' => $migrated]);
        }
    } finally {
        sqlfile_unlock($lock);
    }
}

function users_put(array &$st, string $table, array $row): int
{
    $row = sqlfile_row(USERS_SCHEMA, $table, $row, $st[$table], USERS_NS);
    $st[$table][$row['id']] = $row;
    return $row['id'];
}

function users_save(array &$st, ?string $by = null): void
{
    $by ??= current_actor();
    $u = $st['users'];
    $header = [
        'Network Portal · Benutzer des Portals (SQLite-Dialekt)',
        'Inhalt   : ' . count($u) . ' Benutzer, ' . count($st['webauthn_credentials']) . ' FIDO2-Keys, ' . count($st['yubico_otp_keys']) . ' OTP-Keys',
        'Geschrieben ' . now_str() . ' von ' . $by,
        'Passwörter nur als bcrypt/argon2-Hash. Jede Zeile trägt ein HMAC (row_hash);',
        'veränderte Konten können sich nicht mehr anmelden.',
    ];
    $sql = sqlfile_dump(USERS_SCHEMA, $st, $header, USERS_INDEXES);
    sqlfile_save(users_file(), USERS_SEAL, $sql, count($u) + count($st['webauthn_credentials']) + count($st['yubico_otp_keys']), $by);
    $st['sha256'] = hash('sha256', $sql);
}

/** Ändert users.sql unter Sperre. $fn(array &$st): mixed */
function users_write(callable $fn): mixed
{
    users_store();
    $lock = sqlfile_lock('users');
    try {
        $st = users_store(true);
        [$ok, $why] = sqlfile_seal_ok(USERS_SEAL, $st['sha256'], $st['parse']['body_hmac_ok']);
        if (!$ok) {
            audit('net_write_blocked', ['datei' => 'users.sql', 'grund' => $why], null, 'denied', ['type' => 'user', 'label' => 'users.sql']);
            throw new StoreWriteBlocked(t('Schreibschutz für users.sql: {w}. Ein Portal-Admin muss die Abweichung unter „Integrität“ prüfen und neu versiegeln.', ['w' => $why]));
        }
        $result = $fn($st);
        users_save($st);
        users_store(true);
        return $result;
    } finally {
        sqlfile_unlock($lock);
    }
}

function user_row_ok(array $u, string $table = 'users'): bool
{
    return sqlfile_row_valid(USERS_NS, $table, $u);
}

function user_get(int $id): ?array
{
    return users_store()['users'][$id] ?? null;
}

function user_by_name(string $name): ?array
{
    foreach (users_store()['users'] as $u) {
        if (strcasecmp((string)$u['username'], $name) === 0) {
            return $u;
        }
    }
    return null;
}

function users_list(): array
{
    $l = array_values(users_store()['users']);
    usort($l, static fn($a, $b) => strcasecmp((string)$a['username'], (string)$b['username']));
    return $l;
}

function users_count(): int
{
    return count(users_store()['users']);
}

/** Gültige Keys eines Benutzers (Einträge mit falschem Hash werden ignoriert). */
function user_keys(int $uid, string $table, bool $onlyValid = true): array
{
    $out = [];
    foreach (users_store()[$table] as $k) {
        if ((int)$k['user_id'] === $uid && (!$onlyValid || user_row_ok($k, $table))) {
            $out[] = $k;
        }
    }
    return $out;
}

/** Einzelne Felder eines Benutzers ändern. */
function user_update(int $id, array $fields): array
{
    return users_write(static function (array &$st) use ($id, $fields): array {
        $old = $st['users'][$id] ?? throw new RuntimeException('Benutzer nicht gefunden');
        users_put($st, 'users', array_merge($old, $fields));
        return $old;
    });
}

function user_insert(array $row): int
{
    return users_write(static fn(array &$st): int => users_put($st, 'users', $row + ['id' => null]));
}

function user_delete(int $id): void
{
    users_write(static function (array &$st) use ($id): void {
        unset($st['users'][$id]);
        foreach (['webauthn_credentials', 'yubico_otp_keys'] as $t) {
            foreach ($st[$t] as $kid => $k) {
                if ((int)$k['user_id'] === $id) {
                    unset($st[$t][$kid]);
                }
            }
        }
    });
}
