#!/usr/bin/env python3
"""Exercise the real backup CLI against isolated, synthetic filesystem fixtures."""
import os
import concurrent.futures
import hashlib
import json
import pathlib
import shutil
import sqlite3
import stat
import subprocess
import tempfile
import threading
import time


ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BINARY", "php")


def main():
    checks = 0

    def check(condition, label):
        nonlocal checks
        checks += 1
        if not condition:
            raise AssertionError(label)
        print("PASS", label)

    with tempfile.TemporaryDirectory(prefix="share-backup-") as directory:
        tmp = pathlib.Path(directory)
        app = tmp / "app"
        app.mkdir()
        # Copy application code only. Never use the checkout's files, storage or credentials.
        shutil.copytree(ROOT / "src", app / "src")
        (app / "scripts").mkdir()
        shutil.copy2(ROOT / "scripts/maintenance.php", app / "scripts/maintenance.php")
        public = app / "public"
        files = tmp / "shared-files"
        storage = tmp / "storage"
        private = tmp / "private-backups"
        for path in [public, files, storage, private]:
            (path / "nested").mkdir(parents=True)
        (files / "fixture.txt").write_text("isolated fixture")
        env = {**os.environ, "SHARE_FILES_DIR": str(files), "SHARE_STORAGE_DIR": str(storage)}

        def command(*args, environment=None):
            return subprocess.run(
                [PHP, str(app / "scripts/maintenance.php"), *args],
                cwd=app, env=environment or env, capture_output=True, text=True, timeout=20,
            )

        initialization = command("scan")
        check(initialization.returncode == 0, "isolated database initialized")
        source = storage / "share.sqlite"
        with sqlite3.connect(source) as database:
            database.execute("INSERT INTO settings VALUES (?, ?)", ("backup_fixture", "private marker"))

        # Fileinfo may be absent in BaoTa. Exercise the actual import publication path.
        mime_source = tmp / "mime-input.php"
        mime_bytes = b'<?php echo "synthetic attachment"; ?>'
        mime_source.write_bytes(mime_bytes)
        # Add a restriction to the actual runtime configuration; never remove its
        # existing disabled functions while constructing the compatibility fixture.
        disabled_probe = subprocess.run(
            [PHP, "-r", "echo ini_get('disable_functions');"],
            cwd=app, env=env, capture_output=True, text=True, timeout=20,
        )
        check(disabled_probe.returncode == 0, "MIME compatibility fixture reads the existing PHP restrictions")
        disabled_functions = [name.strip() for name in disabled_probe.stdout.split(",") if name.strip()]
        if "mime_content_type" not in {name.lower() for name in disabled_functions}:
            disabled_functions.append("mime_content_type")
        imported = subprocess.run(
            [PHP, "-d", "disable_functions=" + ",".join(disabled_functions), "-r",
             "require $argv[1]; $s=new ShareStore($argv[2],$argv[3]); "
             "$f=$s->importFile($argv[4],'mime-fallback.php'); "
             "echo json_encode(['mime'=>$f['mime_type'],'sha256'=>$f['sha256']]);",
             str(app / "src/bootstrap.php"), str(files), str(storage / "stats.json"), str(mime_source)],
            cwd=app, env=env, capture_output=True, text=True, timeout=20,
        )
        imported_data = json.loads(imported.stdout) if imported.returncode == 0 else {}
        check(
            imported.returncode == 0 and imported_data.get("mime") == "application/octet-stream"
            and imported_data.get("sha256") == hashlib.sha256(mime_bytes).hexdigest()
            and (files / "mime-fallback.php").read_bytes() == mime_bytes,
            "missing MIME detector preserves imported attachment bytes and uses the safe fallback: " + imported.stderr.strip(),
        )

        def rejected(target, output, label, environment=None):
            result = command("backup", str(target), environment=environment)
            check(
                result.returncode == 1 and not output.exists() and not output.is_symlink(),
                label + ": " + result.stderr.strip(),
            )

        for root, label in [(public, "document root"), (files, "configured shared-files root")]:
            rejected(root / "direct.sqlite", root / "direct.sqlite", label + " rejected")
            rejected(root / "nested/deep.sqlite", root / "nested/deep.sqlite", label + " descendant rejected")

        rejected("public/relative.sqlite", public / "relative.sqlite", "relative public destination rejected")
        rejected("../shared-files/relative.sqlite", files / "relative.sqlite", "relative shared destination rejected")
        rejected(
            str(private) + "/../app/public/traversal.sqlite", public / "traversal.sqlite",
            "absolute traversal into document root rejected",
        )
        rejected(
            "../private-backups/../shared-files/traversal.sqlite", files / "traversal.sqlite",
            "relative traversal into configured files rejected",
        )

        for name, destination in [("web-alias", public), ("files-alias", files), ("app-alias", app)]:
            (tmp / name).symlink_to(destination, target_is_directory=True)
        rejected(tmp / "web-alias/link.sqlite", public / "link.sqlite", "public parent symlink rejected")
        rejected(tmp / "files-alias/link.sqlite", files / "link.sqlite", "shared parent symlink rejected")
        rejected(
            tmp / "app-alias/public/nested/ancestor.sqlite", public / "nested/ancestor.sqlite",
            "symlink in an earlier ancestor rejected",
        )
        (tmp / "bridge").symlink_to(public / "nested", target_is_directory=True)
        rejected(
            str(tmp / "bridge") + "/../symlink-dotdot.sqlite", public / "symlink-dotdot.sqlite",
            "dot-dot is resolved after its symlink ancestor",
        )
        configured_alias = {**env, "SHARE_FILES_DIR": str(tmp / "files-alias")}
        rejected(
            files / "configured-alias.sqlite", files / "configured-alias.sqlite",
            "configured shared directory itself may be an alias", configured_alias,
        )
        (public / "private-link").symlink_to(private, target_is_directory=True)
        rejected(
            public / "private-link/exposed.sqlite", private / "exposed.sqlite",
            "private target reached through a public symlink rejected",
        )
        # The deployed public directory may itself be a release symlink.
        resolved_public = app / "document-root"
        public.rename(resolved_public)
        public.symlink_to(resolved_public, target_is_directory=True)
        rejected(
            resolved_public / "real-root.sqlite", resolved_public / "real-root.sqlite",
            "canonical document root rejected when public is a symlink",
        )
        rejected(public / "alias-root.sqlite", resolved_public / "alias-root.sqlite", "aliased document root rejected")

        for name, contents in [("existing.sqlite", b"existing private backup"), ("empty.sqlite", b"")]:
            existing = private / name
            existing.write_bytes(contents)
            result = command("backup", str(existing))
            check(result.returncode == 1 and existing.read_bytes() == contents, "existing destination preserved: " + name)
        existing_link = private / "existing-link.sqlite"
        existing_link.symlink_to(private / "existing.sqlite")
        result = command("backup", str(existing_link))
        check(
            result.returncode == 1 and existing_link.is_symlink()
            and (private / "existing.sqlite").read_bytes() == b"existing private backup",
            "existing symlink and its target preserved",
        )
        dangling = private / "dangling.sqlite"
        dangling.symlink_to(resolved_public / "dangling-target.sqlite")
        result = command("backup", str(dangling))
        check(
            result.returncode == 1 and dangling.is_symlink()
            and not (resolved_public / "dangling-target.sqlite").exists(),
            "dangling destination symlink rejected without following it",
        )
        rejected(private / "missing/backup.sqlite", private / "missing", "missing parent is not created")
        (tmp / "broken-parent").symlink_to(tmp / "absent-parent", target_is_directory=True)
        rejected(tmp / "broken-parent/backup.sqlite", tmp / "absent-parent", "dangling parent rejected")
        rejected(str(private / "directory-intent") + "/", private / "directory-intent", "trailing slash is not treated as a filename")
        check(command("backup").returncode == 1, "missing destination rejected")
        check(command("backup", str(private)).returncode == 1 and private.is_dir(), "existing directory rejected")
        rejected("file://" + str(private / "uri.sqlite"), private / "uri.sqlite", "SQLite URI input rejected")

        def saved(target, output, label):
            result = command("backup", str(target))
            check(result.returncode == 0 and output.is_file(), label + ": " + result.stderr.strip())
            with sqlite3.connect(output) as snapshot:
                integrity = snapshot.execute("PRAGMA integrity_check").fetchone()[0]
                marker = snapshot.execute("SELECT value FROM settings WHERE key='backup_fixture'").fetchone()
                filename = snapshot.execute("SELECT name FROM files WHERE name='fixture.txt'").fetchone()
            check(
                integrity == "ok" and marker == ("private marker",) and filename == ("fixture.txt",)
                and stat.S_IMODE(output.stat().st_mode) == 0o600,
                label + " retains an intact private 0600 snapshot",
            )
            check(not list(output.parent.glob(".share-backup-*")), label + " removes its private staging file")

        saved(private / "valid.sqlite", private / "valid.sqlite", "absolute private backup")
        saved("../private-backups/relative.sqlite", private / "relative.sqlite", "relative private backup")
        saved(
            "../private-backups/nested/../traversal.sqlite", private / "traversal.sqlite",
            "private-only traversal resolves correctly",
        )
        (tmp / "private-alias").symlink_to(private, target_is_directory=True)
        saved(tmp / "private-alias/safe-link.sqlite", private / "safe-link.sqlite", "private symlink alias")
        for sibling in [app / "document-root-backups", app / "public-backups", tmp / "shared-files-backups"]:
            sibling.mkdir()
            saved(sibling / "valid.sqlite", sibling / "valid.sqlite", "separator-aware sibling: " + sibling.name)

        race_target = private / "race.sqlite"
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda _: command("backup", str(race_target)), range(2)))
        check(sorted(result.returncode for result in results) == [0, 1],
              "two backup processes competing for one target have exactly one publisher")
        with sqlite3.connect(race_target) as snapshot:
            check(snapshot.execute("PRAGMA integrity_check").fetchone() == ("ok",)
                  and snapshot.execute("SELECT value FROM settings WHERE key='backup_fixture'").fetchone() == ("private marker",),
                  "competing backups never overwrite or degrade the winning snapshot")
        check(not list(private.glob(".share-backup-*")), "competing backup processes leave no staged snapshot")

        # Each writer commits both values in one transaction. An online snapshot must
        # see one committed generation, even while another connection is updating it.
        with sqlite3.connect(source) as database:
            database.executemany("INSERT INTO settings VALUES (?, ?)", [("consistency_a", "0"), ("consistency_b", "0")])
            database.execute("CREATE TABLE backup_padding(payload BLOB)")
            database.execute("INSERT INTO backup_padding VALUES(zeroblob(4194304))")
        stop_writer = threading.Event()
        writer_ready = threading.Event()
        writer_errors = []
        generations = []

        def write_generations():
            try:
                with sqlite3.connect(source, timeout=10) as database:
                    generation = 0
                    while not stop_writer.is_set():
                        generation += 1
                        database.execute("UPDATE settings SET value=? WHERE key IN ('consistency_a','consistency_b')", (str(generation),))
                        database.commit()
                        generations.append(generation)
                        writer_ready.set()
                        time.sleep(0.002)
            except Exception as error:
                writer_errors.append(str(error))
                writer_ready.set()

        writer = threading.Thread(target=write_generations)
        writer.start()
        concurrent_target = private / "concurrent.sqlite"
        try:
            check(writer_ready.wait(10) and not writer_errors, "concurrent source writer starts")
            concurrent_result = command("backup", str(concurrent_target))
        finally:
            stop_writer.set()
            writer.join(timeout=15)
        check(concurrent_result.returncode == 0 and not writer.is_alive() and not writer_errors,
              "online backup completes with a concurrent source writer: " + concurrent_result.stderr.strip())
        with sqlite3.connect(concurrent_target) as snapshot:
            pair = snapshot.execute("SELECT value FROM settings WHERE key IN ('consistency_a','consistency_b') ORDER BY key").fetchall()
            check(snapshot.execute("PRAGMA integrity_check").fetchone() == ("ok",)
                  and len(pair) == 2 and pair[0] == pair[1] and int(pair[0][0]) >= 1,
                  "online backup contains an intact committed generation, never half a transaction")
        check(generations and not list(private.glob(".share-backup-*")), "online backup preserves its source and cleans staging")
        # Refuse to back up if the mandatory document-root boundary cannot be resolved.
        public.unlink()
        rejected(private / "unverified.sqlite", private / "unverified.sqlite", "missing document root fails closed")
        with sqlite3.connect(source) as database:
            check(database.execute("PRAGMA integrity_check").fetchone() == ("ok",), "source database remains intact")
    print(f"{checks} backup CLI checks passed")


if __name__ == "__main__":
    main()
