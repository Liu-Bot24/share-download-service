<?php
declare(strict_types=1);

/** A local, rollback-journal database. Never placed in a public or network directory. */
final class Database
{
    public PDO $pdo;
    public function __construct(string $path)
    {
        if (!extension_loaded("pdo_sqlite")) {
            throw new RuntimeException("PHP pdo_sqlite is required.");
        }
        umask(0077);
        $this->pdo = new PDO("sqlite:" . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(
            "PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=DELETE; PRAGMA synchronous=FULL",
        );
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS files (
             id INTEGER PRIMARY KEY, public_id TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
             storage_name TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, bytes INTEGER NOT NULL,
             mtime INTEGER NOT NULL, inode INTEGER NOT NULL, sha256 TEXT NOT NULL, mime_type TEXT NOT NULL,
             state TEXT NOT NULL DEFAULT 'active', password_hash TEXT, policy_version INTEGER NOT NULL DEFAULT 1,
             revocation_epoch INTEGER NOT NULL DEFAULT 0, public_count INTEGER NOT NULL DEFAULT 0,
             legacy_count INTEGER NOT NULL DEFAULT 0, public_cap INTEGER, last_public_at INTEGER,
             auto_destroy INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
             destroyed_at INTEGER, trash_key TEXT, CHECK(public_count >= legacy_count), CHECK(public_cap IS NULL OR public_cap >= 0)
            );
            CREATE UNIQUE INDEX IF NOT EXISTS files_current_storage ON files(storage_name) WHERE state NOT IN ('trashed','destroyed');
            CREATE UNIQUE INDEX IF NOT EXISTS files_trash_key ON files(trash_key) WHERE trash_key IS NOT NULL;
            CREATE TABLE IF NOT EXISTS versions (
             id INTEGER PRIMARY KEY, file_id INTEGER NOT NULL REFERENCES files(id), version INTEGER NOT NULL,
             storage_name TEXT NOT NULL, sha256 TEXT NOT NULL, bytes INTEGER NOT NULL, created_at INTEGER NOT NULL,
             UNIQUE(file_id, version)
            );
            CREATE TABLE IF NOT EXISTS aliases (alias TEXT PRIMARY KEY, file_id INTEGER NOT NULL REFERENCES files(id));
            CREATE TABLE IF NOT EXISTS sessions (
             id INTEGER PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE, file_id INTEGER NOT NULL REFERENCES files(id),
             version INTEGER NOT NULL, policy_version INTEGER NOT NULL, revocation_epoch INTEGER NOT NULL,
             actor TEXT NOT NULL CHECK(actor IN ('public','admin')), admin_binding TEXT,
             created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, claimed_at INTEGER,
             ip TEXT NOT NULL, ip_source TEXT NOT NULL, region TEXT NOT NULL, geo_version TEXT NOT NULL,
             referrer TEXT NOT NULL, request_count INTEGER NOT NULL DEFAULT 0, observed_bytes INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS sessions_file_expiry ON sessions(file_id,expires_at);
            CREATE INDEX IF NOT EXISTS sessions_expiry_unclaimed ON sessions(expires_at) WHERE claimed_at IS NULL;
            CREATE INDEX IF NOT EXISTS sessions_public_candidates_ip ON sessions(ip,expires_at) WHERE claimed_at IS NULL AND actor='public';
            CREATE TABLE IF NOT EXISTS events (
             id INTEGER PRIMARY KEY, session_id INTEGER NOT NULL UNIQUE REFERENCES sessions(id),
             file_id INTEGER NOT NULL REFERENCES files(id), version INTEGER NOT NULL, filename TEXT NOT NULL,
             started_at INTEGER NOT NULL, last_request_at INTEGER NOT NULL, ip TEXT NOT NULL, ip_source TEXT NOT NULL,
             region TEXT NOT NULL, geo_version TEXT NOT NULL, referrer TEXT NOT NULL,
             status TEXT NOT NULL DEFAULT 'unknown', http_status INTEGER, request_count INTEGER NOT NULL DEFAULT 0,
             observed_bytes INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS events_time ON events(started_at,id);
            CREATE INDEX IF NOT EXISTS events_file_time ON events(file_id,started_at);
            CREATE INDEX IF NOT EXISTS events_region_time ON events(region,started_at);
            CREATE TABLE IF NOT EXISTS transfers (
             id INTEGER PRIMARY KEY, session_id INTEGER NOT NULL REFERENCES sessions(id), started_at INTEGER NOT NULL,
             ended_at INTEGER, http_status INTEGER NOT NULL, range_header TEXT NOT NULL, expected_bytes INTEGER NOT NULL,
             observed_bytes INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'inflight'
            );
            CREATE INDEX IF NOT EXISTS transfers_session ON transfers(session_id,ended_at);
            CREATE TABLE IF NOT EXISTS attempts (
             id INTEGER PRIMARY KEY, file_id INTEGER, occurred_at INTEGER NOT NULL, ip TEXT NOT NULL,
             reason TEXT NOT NULL, http_status INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS attempts_time ON attempts(occurred_at);
            CREATE TABLE IF NOT EXISTS audit (
             id INTEGER PRIMARY KEY, occurred_at INTEGER NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL,
             file_id INTEGER, detail TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS jobs (
             id INTEGER PRIMARY KEY, job_key TEXT NOT NULL UNIQUE, file_id INTEGER NOT NULL REFERENCES files(id),
             version INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending', created_at INTEGER NOT NULL,
             updated_at INTEGER NOT NULL, error TEXT
            );
            CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, attempts INTEGER NOT NULL, until_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS rate_limits_expiry ON rate_limits(until_at);
            CREATE TABLE IF NOT EXISTS guest_upload_window (
             id INTEGER PRIMARY KEY CHECK(id=1), token TEXT NOT NULL, opened_at INTEGER NOT NULL,
             expires_at INTEGER NOT NULL, closed_at INTEGER, received_count INTEGER NOT NULL DEFAULT 0,
             received_bytes INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS file_mutations (
             id TEXT PRIMARY KEY, kind TEXT NOT NULL, file_id INTEGER, committed INTEGER NOT NULL DEFAULT 0,
             files_dir TEXT NOT NULL, storage_dir TEXT NOT NULL, source TEXT NOT NULL, stage TEXT NOT NULL, target TEXT NOT NULL,
             source_inode INTEGER NOT NULL, source_device INTEGER NOT NULL, stage_inode INTEGER NOT NULL, stage_device INTEGER NOT NULL,
             sha256 TEXT NOT NULL, created_at INTEGER NOT NULL
            );
            SQL
            ,
        );
        // Serialize additive upgrades: concurrent first requests must not race ALTER TABLE.
        $this->transaction(function (): void {
            $columns = array_column($this->all("PRAGMA table_info(files)"), "name");
            if (!in_array("homepage_visible", $columns, true)) {
                $this->pdo->exec(
                    "ALTER TABLE files ADD COLUMN homepage_visible INTEGER NOT NULL DEFAULT 0 CHECK(homepage_visible IN (0,1))",
                );
            }
            if (!in_array("upload_origin", $columns, true)) {
                $this->pdo->exec(
                    "ALTER TABLE files ADD COLUMN upload_origin TEXT NOT NULL DEFAULT 'administrator'",
                );
            }
        });
        $this->run("INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)", [
            "timezone",
            "Asia/Shanghai",
        ]);
        $this->run("INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)", [
            "session_ttl",
            "86400",
        ]);
        $this->run("INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)", [
            "detail_started_at",
            (string) time(),
        ]);
    }
    public function run(string $sql, array $args = []): PDOStatement
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s;
    }
    public function one(string $sql, array $args = []): ?array
    {
        $r = $this->run($sql, $args)->fetch();
        return $r === false ? null : $r;
    }
    public function all(string $sql, array $args = []): array
    {
        return $this->run($sql, $args)->fetchAll();
    }
    public function transaction(callable $fn): mixed
    {
        for ($n = 0; ; $n++) {
            $begun = false;
            try {
                $this->pdo->exec("BEGIN IMMEDIATE");
                $begun = true;
                $out = $fn();
                $this->pdo->exec("COMMIT");
                return $out;
            } catch (Throwable $e) {
                if ($begun) {
                    $this->pdo->exec("ROLLBACK");
                }
                if (
                    $e instanceof PDOException &&
                    str_contains(strtolower($e->getMessage()), "locked") &&
                    $n < 3
                ) {
                    usleep(25000 * ($n + 1));
                    continue;
                }
                throw $e;
            }
        }
    }
}

final class ShareError extends RuntimeException
{
    public function __construct(
        public string $reason,
        string $message,
        public int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
