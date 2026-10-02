# Migration and deployment runbook

This branch is a draft for review. It has not accessed or deployed to production. Code base: `cee9b82735d404f33858b7906852b235c7e3943f`, which added the currently synchronized authenticated upload and recoverable deletion. Production runtime, actual visitor proxy chain, file volume and storage permissions still need operator validation.

## Before any deployment

1. Confirm PHP 8.2+, PDO SQLite, fileinfo, mbstring and the PHP-FPM owner. Confirm local filesystem storage, adequate disk space, and no direct `/files/` or `/storage/` exposure. Inspect the actual BaoTa Nginx include layout before applying the example; do not overwrite unrelated vhost configuration.
2. Back up the production code, canonical settings, manager configuration, legacy JSON, existing trash metadata and files. Keep secrets out of source control. Stop writes/download authorizations during final migration so the old JSON writer and new database never run concurrently.
3. Copy a private backup into an isolated rehearsal directory. Use different `SHARE_FILES_DIR`, `SHARE_STORAGE_DIR` and canonical origin. Never point tests or development at production paths.
4. Start the new version against the rehearsal copy. Legacy import is one-time and leaves source JSON unchanged. Review invalid timestamp errors explicitly; do not guess offset-less historical dates. Current entities import their old cumulative count and last-public instant. Old JSON entries whose entity is absent stay as missing records. Valid pre-upgrade trash packets are imported as trashed records with their known totals, original name and recovery key, and can be restored from the UI when the original name is free. No detailed events are invented.
5. Compare file names, byte sizes/hashes, alias mappings, total lifetime counts, per-file last time and manager login. Run `maintenance.php reconcile`. Compare all migrated public totals to the legacy source. New charts intentionally begin at detailed logging activation; they cannot describe unknown historical daily behavior.
6. Run the unit, HTTP and browser suites. Add production-like large file/concurrency checks with isolated fixtures, including an actual supported download manager that follows redirects and resumes the final token URL. Do not enable production destruction policies for acceptance testing.

## Cutover after explicit deployment approval

- Freeze the old writer, make a final consistent backup, migrate the final increment, and verify counts before reopening.
- Set document root to `public/`, the correct HTTPS `SHARE_BASE_URL`, protected data paths and verified proxy CIDRs. Match PHP upload limit (45 MiB), post limit (46 MiB) and Nginx body limit. Preserve the original manager.json; do not initialize a new account over it.
- Require HTTPS and disable public caching for public entrance/transfer/password routes. Tokens appear in final URLs: use the supplied redacted Nginx format, remove URI/query/referer/cookie logging from the final PHP location and any upstream CDN/error collector too. Standard access/error log products can expose bearer tokens unless configured carefully.
- Retain PHP streaming. Never switch to Nginx offload while assuming the existing file lock remains held. Configure reasonable PHP-FPM slots/timeouts; downloads occupy a worker.
- Install the `maintenance.php jobs` minute cron only after isolated lifecycle verification. The code does not install cron or change system security settings. Monitor failed/pending jobs and free disk space; the settings page displays active jobs and any safety errors.
- Check direct old link (no extra click), protected link, valid/invalid HEAD/Range, parallel final quota slot, admin download exclusion, history/referrer redaction/timezone, uploaded file, recovery and private path denial. Verify secure cookie behavior behind the actual TLS terminator.

## Failure and recovery

- Database lock, corrupt legacy input or storage failures reject new authorizations; there is no permissive fallback counter. Inspect server error correlation IDs privately. SQLite transactions use busy_timeout and bounded retry.
- The rollback-journal database must remain on local disk. Back up using `maintenance.php backup <new private path>` (SQLite `VACUUM INTO`), not a blind copy of an active database. The parent directory must already exist. The command rejects existing destinations (including dangling symlinks), the document root, and the configured shared-files directory; it resolves symlinks and traversal in all supplied ancestors before writing to the canonical private path. Back up file entities independently and verify correspondence. Review both code and database migration behavior before using a different journal mode.
- A crash after a claim may leave transmission status unknown; quota is intentionally not refunded. Full emitted byte counts do not prove that a visitor saved a file.
- A crashed PHP process releases its OS file lock. The destruction worker still waits for session expiry before an exclusive lock permits finalization. Unlink and marking are idempotent. If an entity changed or has multiple hard links, a job stays visibly deferred; investigate the file mapping before retrying. Never bypass the safety check by deleting another path.
- A filesystem rename and SQLite commit cannot be atomic together. Recoverable deletion writes `trash/<key>/metadata.json` with original name and stable file ID before moving the entity. If a crash occurs between move and database commit, leave the share unavailable and use that recovery packet to reconcile the exact same entity with its existing record; do not create a fresh count or reassign old links. Check conflicts before moving anything back. The same caution applies to a crash during restore. No content is intentionally discarded by trash.
- Do not modify active files in place through unrelated tools. The app checks cached inode/size/mtime and versions; advisory locks cannot control external programs that ignore them. Publish a new uniquely named entity for external replacements.
- To roll back after any new downloads, first preserve the complete new database and logs. Restoring only an old JSON snapshot would lose accepted sessions and quota history. Reconcile the increment explicitly before reactivating an older writer. Do not delete the new database to “retry migration” on live data.

## Deliberate initial limits

- SQLite single-node, PHP streaming; no distributed storage or Nginx completion collector
- One ordinary byte range per request, not multipart range responses
- Local DB-IP/MMDB geography with an explicit-license one-command installer; no production database or credentials bundled. Small custom JSON CIDR datasets also work. Unknown locations stay unknown.
- Original historical JSON has no session details; never fabricate them
- Access-attempt diagnostics show the latest 100 entries; public-session records and all analytics have shared date/file/region/source/status filtering
- No automatic retention deletion or log anonymization; historical evidence remains until a separately approved retention workflow is implemented
- Valid pre-upgrade trash packets and newly trashed records support a UI restore action; malformed or unexpected packets require operator review
