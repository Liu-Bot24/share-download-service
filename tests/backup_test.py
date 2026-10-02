#!/usr/bin/env python3
"""Exercise the real backup CLI against isolated, synthetic filesystem fixtures."""
import os
import pathlib
import shutil
import sqlite3
import stat
import subprocess
import tempfile


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
                filename = snapshot.execute("SELECT name FROM files").fetchone()
            check(
                integrity == "ok" and marker == ("private marker",) and filename == ("fixture.txt",)
                and stat.S_IMODE(output.stat().st_mode) == 0o600,
                label + " retains an intact private 0600 snapshot",
            )

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
        # Refuse to back up if the mandatory document-root boundary cannot be resolved.
        public.unlink()
        rejected(private / "unverified.sqlite", private / "unverified.sqlite", "missing document root fails closed")
        with sqlite3.connect(source) as database:
            check(database.execute("PRAGMA integrity_check").fetchone() == ("ok",), "source database remains intact")
    print(f"{checks} backup CLI checks passed")


if __name__ == "__main__":
    main()
