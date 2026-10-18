<?php
/**
 * System-Datenbank database/_system.sqlite: Audit-Log, Siegel (Datei-Hashes)
 * der SQL-Dateien und Schlüsselteil 1. Benutzer stehen in database/users.sql.
 *
 * Die Netzwerke selbst liegen NICHT hier, sondern je als eigene SQL-Datei
 * (database/net-<name>.sql, siehe netstore.php).
 */

declare(strict_types=1);

const SYS_SCHEMA_VERSION = 1;

function sys(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $fresh = !is_file(SYS_DB_FILE) || (int)@filesize(SYS_DB_FILE) === 0;
    $pdo = new PDO('sqlite:' . SYS_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($fresh) {
        // neue Datenbank: gelöschte Audit-Einträge geben den Platz schrittweise frei
        $pdo->exec('PRAGMA auto_vacuum = INCREMENTAL');
    }
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    @chmod(SYS_DB_FILE, 0640);
    sys_migrate($pdo);
    return $pdo;
}

function sys_migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= SYS_SCHEMA_VERSION) {
        return;
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS audit (
  id INTEGER PRIMARY KEY,
  ts TEXT NOT NULL,
  request_id TEXT,
  username TEXT,
  auth_via TEXT,
  role TEXT,
  ip TEXT,
  user_agent TEXT,
  session TEXT,
  method TEXT,
  uri TEXT,
  action TEXT NOT NULL,
  category TEXT,
  result TEXT NOT NULL DEFAULT 'ok',
  net TEXT,
  object_type TEXT,
  object_id TEXT,
  object_label TEXT,
  detail TEXT,
  prev_hash TEXT,
  hash TEXT
);
CREATE INDEX IF NOT EXISTS ix_audit_ts ON audit(ts);
CREATE INDEX IF NOT EXISTS ix_audit_user ON audit(username);
CREATE INDEX IF NOT EXISTS ix_audit_net ON audit(net, object_type, object_id);
CREATE INDEX IF NOT EXISTS ix_audit_action ON audit(action);
CREATE TABLE IF NOT EXISTS seals (
  file TEXT PRIMARY KEY,
  sha256 TEXT NOT NULL,
  hmac TEXT NOT NULL,
  bytes INTEGER,
  rows INTEGER,
  sealed_at TEXT,
  sealed_by TEXT
);
CREATE TABLE IF NOT EXISTS meta (
  mkey TEXT PRIMARY KEY,
  mvalue TEXT
);
SQL);
    $pdo->exec('PRAGMA user_version = ' . SYS_SCHEMA_VERSION);
}

function sys_all(string $sql, array $p = []): array
{
    $st = sys()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function sys_one(string $sql, array $p = []): ?array
{
    $st = sys()->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function sys_val(string $sql, array $p = []): mixed
{
    $st = sys()->prepare($sql);
    $st->execute($p);
    $r = $st->fetchColumn();
    return $r === false ? null : $r;
}

function sys_exec(string $sql, array $p = []): int
{
    $st = sys()->prepare($sql);
    $st->execute($p);
    return $st->rowCount();
}

function sys_insert(string $table, array $row): int
{
    $cols = array_keys($row);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    sys()->prepare($sql)->execute(array_values($row));
    return (int)sys()->lastInsertId();
}

function sys_update(string $table, array $row, int $id): void
{
    $sets = implode(',', array_map(static fn($c) => $c . ' = ?', array_keys($row)));
    sys()->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE id = ?')->execute([...array_values($row), $id]);
}

function meta_get(string $k): ?string
{
    $v = sys_val('SELECT mvalue FROM meta WHERE mkey = ?', [$k]);
    return $v === null ? null : (string)$v;
}

function meta_set(string $k, string $v): void
{
    sys_exec('INSERT INTO meta (mkey, mvalue) VALUES (?, ?) ON CONFLICT(mkey) DO UPDATE SET mvalue = excluded.mvalue', [$k, $v]);
}
