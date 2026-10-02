#!/usr/bin/env python3
"""Run additional HTTP/browser gates with fresh local synthetic fixtures.

Usage: python3 tests/merge_gate_fixture.py http|browser
The browser mode requires the repository's locked Playwright runtime and Chromium.
"""
import contextlib
import json
import os
import pathlib
import secrets
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BINARY", "php")


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("http", "browser"):
        raise SystemExit("Usage: python3 tests/merge_gate_fixture.py http|browser")
    with tempfile.TemporaryDirectory(prefix="share-browser-gate-") as temporary:
        private = pathlib.Path(temporary)
        for name in ("files", "storage"):
            (private / name).mkdir(mode=0o700)
        with contextlib.closing(socket.socket()) as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        base = "http://127.0.0.1:" + str(port)
        password = secrets.token_urlsafe(24)
        env = {
            **os.environ,
            "SHARE_FILES_DIR": str(private / "files"),
            "SHARE_STORAGE_DIR": str(private / "storage"),
            "SHARE_BASE_URL": base,
            "SHARE_ALLOW_HTTP": "1",
            "SHARE_FIXTURE_PASSWORD": password,
            "PHP_CLI_SERVER_WORKERS": "4",
        }
        fixture = subprocess.run(
            [PHP, "tests/browser-fixture.php"], cwd=ROOT, env=env,
            capture_output=True, text=True, check=True,
        )
        meta = private / "connection.json"
        meta.write_text(json.dumps({
            "private": str(private), "base": base, "username": "browser-test",
            "password": password, "files": json.loads(fixture.stdout)["files"],
        }), encoding="utf-8")
        meta.chmod(0o600)
        env["SHARE_GATE_META"] = str(meta)
        with (private / "server.log").open("w+") as log:
            server = subprocess.Popen(
                [PHP, "-S", "127.0.0.1:" + str(port), "-t", "public", "scripts/dev-router.php"],
                cwd=ROOT, env=env, stdout=log, stderr=log, start_new_session=True,
            )
            try:
                ready = False
                for _ in range(100):
                    if server.poll() is not None:
                        break
                    try:
                        with urllib.request.urlopen(base + "/admin/login", timeout=1) as response:
                            ready = response.status == 200
                        if ready:
                            break
                    except (OSError, ValueError):
                        pass
                    time.sleep(0.05)
                if not ready:
                    raise RuntimeError("Isolated PHP fixture did not start; no production paths were used")
                command = (
                    [sys.executable, "tests/merge_gate_http.py"]
                    if sys.argv[1] == "http"
                    else ["node", "tests/merge_gate_browser.mjs"]
                )
                result = subprocess.run(command, cwd=ROOT, env=env)
                return result.returncode
            finally:
                if os.name == "posix":
                    with contextlib.suppress(ProcessLookupError):
                        os.killpg(server.pid, signal.SIGTERM)
                elif server.poll() is None:
                    server.terminate()
                with contextlib.suppress(subprocess.TimeoutExpired):
                    server.wait(timeout=5)


if __name__ == "__main__":
    raise SystemExit(main())
