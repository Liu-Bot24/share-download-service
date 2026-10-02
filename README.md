# Share Files

A small, private PHP file-sharing workspace for BaoTa/Nginx. Redesigned from the synchronized production baseline **cee9b827**. The existing manager account, 45 MiB authenticated uploads, recoverable deletion and old `/d/{filename}` links remain supported.

## What is included

- Responsive Chinese overview, file workspace and detail drawer, session history, analytics and settings
- Public visitor file catalog at `/`, including direct/password downloads and public file metadata; private administration remains under `/admin`
- Optional per-file password, SHA256 cached during indexing, immutable link aliases and version checks
- Atomic SQLite quota claims, single-use counting per resumable session, proper GET/HEAD/conditional/single-Range behavior
- Private administrator download tickets, independent from all public quotas and charts
- Time/IP/approximate local geography/sanitized referrer per accepted public session; second-level timestamps, default Asia/Shanghai
- Explicit opt-in automatic permanent entity deletion after quota exhaustion, protected by valid download sessions and cross-process file locks; metadata and history retained
- Recoverable manual trash, authenticated restore to paused state, optimistic policy updates and audit history
- No external fonts, analytics, chart libraries, per-visitor geolocation requests or frontend build chain

## Runtime and local development

PHP 8.2+ with PDO SQLite, session, fileinfo and mbstring. Run `composer install --no-dev --no-scripts` to install the locked pure-PHP MMDB reader. Tests also require `pcntl` on Linux for the real 20-process race test. SQLite must be on a **local filesystem**. This version deliberately uses **DELETE journaling and FULL synchronous mode**, not WAL; the WAL-reset version caveat does not apply. Use a supported, patched PHP/SQLite release.

The only public document root is `public/`. Both `files/` and `storage/` must stay outside it. The web/PHP user must have private read/write access to them (directories 0700, or carefully scoped 0770 if required by BaoTa). Never put real files, manager credentials, session files, databases or GeoIP data in git.

```sh
export SHARE_BASE_URL=http://127.0.0.1:8080
export SHARE_ALLOW_HTTP=1 # Loopback development only; cannot enable public HTTP.
# Optional, absolute private locations. Defaults are project/files and project/storage.
export SHARE_FILES_DIR=/absolute/private/files
export SHARE_STORAGE_DIR=/absolute/private/storage
php scripts/create-manager.php admin < /secure/temporary/password-input
php scripts/import-file.php /path/to/document.pdf
php -S 127.0.0.1:8080 -t public scripts/dev-router.php
```

For a pre-existing installation, keep the existing private `storage/manager.json`; its username and salted password hash are read as-is. The initializer refuses to overwrite credentials. Do not put passwords in command arguments, URLs, shell history or repository files. Remove any temporary password input securely under your normal credential handling process.

`SHARE_BASE_URL` defaults to `https://share.playai.ren`. Requests with an unrecognized Host are rejected. Set its actual canonical HTTPS origin before using another domain. Do not derive public URLs from arbitrary incoming Host headers. Public assets and password pages load only same-origin resources.

## Download semantics

One public download means **one session authorized to start**, not proof that the visitor saved a file. The entrance creates a five-minute candidate and redirects transparently to a high-entropy bearer token. The first valid GET of its entity claims exactly one quota slot and event in a short `BEGIN IMMEDIATE` transaction. Active session lifetime defaults to 24 hours and is configurable from 300 seconds to 48 hours. HEAD, password errors, 304, 416, failed authorization and administrator tickets do not count. Range retries on the same final URL do not recount. Interrupted transfers are not refunded. A new entrance URL visit intentionally creates a new candidate; download managers must resume the final redirected URL to reuse the same session.

Single byte ranges are supported, including suffix/open ranges and If-Range. Multipart ranges are explicitly rejected with 416 before quota claim; use sequential or parallel single-range requests on the same token. Each token allows at most 3 concurrent requests, 128 total requests and bounded observed retransmission bytes. These are abuse bounds, not user identity guarantees. Forwarding a valid token or the downloaded file cannot be prevented by a file password.

An administrator's dedicated **后台下载** button is a CSRF-protected POST. It works for paused, password-protected or quota-exhausted entities, but does not change public count, last-public time or analytics. Visiting a public link as an administrator still follows and counts against public rules. Logout invalidates administrator tickets.

All response bodies use the PHP streaming driver in this release. It holds an advisory shared entity lock, reports only server-observed transmission and allows the destruction worker to prove that no PHP sender is active. **Do not replace it with X-Accel-Redirect** without adding a reliable completion/lease collector and revalidating in-flight safety. This is a deliberate safety-first change from the implementation specification's suggested acceleration option. Tune PHP-FPM capacity and timeouts for actual file sizes and concurrency before production.

## Quotas and lifecycle

Lifetime count includes imported legacy totals plus detailed new sessions. Setting “from now allow N” sets cap = current count + N. Adding N preserves any existing remaining allowance. Pause/resume never resets count. Ordinary pause and exhaustion only block new claims; existing claimed sessions can resume until expiry. Explicit revocation blocks subsequent requests but cannot retract bytes already sent.

Automatic destruction is **off by default**. Enabling it, or changing its trigger while enabled, requires the administrator to type the exact filename after the UI explains the cap, irreversibility, existing-transfer protection and retained records. Exhaustion changes the file to `destroy_pending`. The worker waits for all current candidates/tickets/sessions to expire and obtains an exclusive cross-process entity lock. It rechecks version, policy and quota, changes to `destroying`, verifies the entity's inode/size/mtime and that it is not hard-linked, then unlinks only that entity. Missing/changed/shared entities defer with an operator-visible job error. A retry after unlink but before database finalization finishes idempotently. No backups are deleted and no secure disk erasure is claimed.

While pending, disabling destruction or adding quota cancels the pending job. Once destroying starts, edits and administrator downloads are blocked. Historical file metadata, event snapshots, counts, time and audit are never cascade-deleted. Reintroducing a filename after trash/destruction creates a new public ID; the old name alias remains attached to its old record rather than silently serving different content.

```sh
php scripts/maintenance.php scan       # After placing/changing files in the mapped directory
php scripts/maintenance.php jobs       # Run every minute via cron under the PHP owner
php scripts/maintenance.php reconcile  # Read-only count/event reconciliation, exit 2 on differences
php scripts/maintenance.php backup /private/new-backup.sqlite
```

No scheduled worker is created automatically. Until the operator installs the `jobs` cron, pending entities remain protected and visible. Example after deployment approval: `* * * * * /usr/bin/php /path/to/share/scripts/maintenance.php jobs`. Explicit scans hash only new/changed indexed entities; normal file lists never hash every file. Do not edit bytes in place while distributing them; import a new filename/version and scan under a maintenance window. External software ignoring the application's advisory locks is outside in-flight guarantees.

## Migration and operational safety

Read [the migration/runbook](docs/OPERATIONS.md) before deployment. Opening the new application creates private `storage/share.sqlite` and performs a one-time idempotent import from `stats.json`. Only known legacy count/last-time are imported; **no historical IP, region, source or per-download events are invented**. Orphan legacy entries become missing records so lifetime totals survive. Legacy timestamps must contain an explicit offset; malformed or offset-less timestamps fail closed for operator review. The original JSON is left unchanged.

Uploads and scans preserve names; secrets and real visitor logs are never included in this repository. Administrative file history is private. Settings changes affect rendering and day buckets, not stored UTC instants. Unknown geography is retained in charts. IP means a network address, not a person. Referer can be missing or forged and is never used as authorization.

## Local-only geolocation and trusted proxies

`SHARE_TRUSTED_PROXIES` is a comma-separated list of verified reverse-proxy CIDRs. It defaults empty. Forwarded addresses are accepted only from a trusted socket peer and processed from the right of the chain. Do not trust every address or blindly trust the leftmost header value.

Local geography works with the real **DB-IP City Lite MMDB** using the locked `maxmind-db/reader` dependency. The [official data page](https://db-ip.com/db/download/ip-to-city-lite) provides free monthly downloads without an account, under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Attribution is included on the admin pages. Coverage and city accuracy are limited; it is an approximate network location, not a visitor's physical position.

```sh
composer install --no-dev --no-scripts
# Read and accept the linked license before running the explicit acceptance command:
php scripts/geoip.php install --accept-dbip-license
php scripts/geoip.php lookup 8.8.8.8
```

The installer discovers the current download only from the official DB-IP page, uses HTTPS certificate validation, bounds compressed/expanded size, validates an MMDB lookup, and atomically installs `storage/geoip/dbip-city-lite.mmdb`. The application detects it automatically. Repeat monthly to update; the command does not install cron. A failed update keeps the existing database. Only the database is downloaded; no visitor address is sent to DB-IP or another provider. The installer requires PHP HTTPS streams and zlib. It will not bypass disabled networking or certificate errors.

`SHARE_GEOIP_FILE` can override the default with a private licensed `.mmdb` file or a small local CIDR JSON dataset in `docs/geoip.example.json` format. Unconfigured/unknown data displays “未知”; private/reserved addresses are separately labeled. Unit tests include a genuine MaxMind MMDB test fixture and validate country/city lookup and a missing-address fallback without external requests.

## Tests

```sh
composer install --no-dev --no-scripts
php tests/run.php
php tests/mutations.php             # Requires pcntl + posix: concurrency, fault injection and crash recovery
python3 tests/backup_test.py        # Isolated CLI backup destination and snapshot checks
python3 tests/http_test.py          # Starts an isolated PHP fixture; requires php on PATH
python3 tests/nginx_test.py         # Isolated Nginx routing/log fixture; requires nginx on PATH
npm ci && npx playwright install chromium
node tests/browser.mjs             # Isolated desktop/mobile screenshots and interaction suite
find src public scripts templates tests -name '*.php' -exec php -l {} \;
```

The tests never use production paths or credentials and destruct only randomly created temporary fixture files. The test fixture is not included in application output. The HTTP suite covers direct links, passwords, HEAD/Range/304, CSRF, authentication, Host rejection, upload, trash/restore and admin/public metric separation. Browser checks cover desktop/mobile, drawer history/cancel, navigation, keyboard and form feedback.
