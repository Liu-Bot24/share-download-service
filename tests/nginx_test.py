#!/usr/bin/env python3
"""Real Nginx syntax, routing and bearer-log tests using an isolated fake upstream."""
import contextlib
import os
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
NGINX = os.environ.get("NGINX_BINARY", "nginx")


def main():
    checks = 0

    def check(condition, label):
        nonlocal checks
        checks += 1
        if not condition:
            raise AssertionError(label)
        print("PASS", label)

    with tempfile.TemporaryDirectory(prefix="share-nginx-") as directory:
        tmp = pathlib.Path(directory)
        (tmp / "public/assets").mkdir(parents=True)
        (tmp / "public/assets/fixture.txt").write_text("static fixture")
        (tmp / "public/index.php").write_text("THIS_PHP_SOURCE_MUST_NEVER_BE_SERVED")
        (tmp / "logs").mkdir()
        params = pathlib.Path(os.environ.get("NGINX_FASTCGI_PARAMS", "/etc/nginx/fastcgi_params"))
        shutil.copy2(params, tmp / "fastcgi_params")
        with contextlib.closing(socket.socket()) as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        routes = (ROOT / "download-route.conf").read_text()
        log_format = (ROOT / "docs/nginx-http.conf").read_text()
        configuration = f"""
pid {tmp}/nginx.pid;
error_log {tmp}/process.log notice;
events {{ worker_connections 64; }}
http {{
    {log_format}
    client_body_temp_path {tmp}/body;
    fastcgi_temp_path {tmp}/fastcgi;
    server {{
        listen 127.0.0.1:{port};
        server_name localhost;
        root {tmp}/public;
        index index.php;
        client_max_body_size 46m;
        access_log {tmp}/access.log share_redacted;
        error_log /dev/null;
        {routes}
    }}
}}
"""
        config = tmp / "nginx.conf"
        config.write_text(configuration)
        command = [NGINX, "-p", str(tmp) + "/", "-c", str(config)]
        result = subprocess.run(command + ["-t"], capture_output=True, text=True, timeout=10)
        check(result.returncode == 0, "complete replacement route set passes nginx -t: " + result.stderr.strip())
        config.write_text(configuration.replace("index index.php;", "index index.php;\nlocation / { return 200; }"))
        result = subprocess.run(command + ["-t"], capture_output=True, text=True, timeout=10)
        check(result.returncode != 0 and 'duplicate location "/"' in result.stderr, "appending to an old location / is rejected, confirming replacement is required")
        config.write_text(configuration)
        stderr = open(tmp / "stderr.log", "w+")
        server = subprocess.Popen(command + ["-g", "daemon off; master_process off;"], stdout=stderr, stderr=stderr)
        base = f"http://127.0.0.1:{port}"
        marker = "SYNTHETIC_SECRET_7f295bb3"

        def request(path):
            req = urllib.request.Request(base + path, headers={"Referer": f"https://fixture.invalid/{marker}", "Cookie": f"test={marker}"})
            try:
                response = urllib.request.urlopen(req, timeout=5)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, response.read()

        try:
            for _ in range(100):
                try:
                    if request("/assets/fixture.txt")[0] == 200:
                        break
                except OSError:
                    time.sleep(0.03)
            else:
                raise RuntimeError("Isolated Nginx fixture did not become ready")
            for route in ["/", "/admin", "/d/fixture.txt", "/transfer/" + "a" * 64]:
                status, body = request(route + "?secret=" + marker)
                check(status == 502 and b"THIS_PHP_SOURCE" not in body, route + " internally reaches the protected final FastCGI location")
            for route in ["/files/fixture.txt", "/storage/share.sqlite", "/src/ShareStore.php", "/scripts/maintenance.php", "/index.php", "/other.php"]:
                check(request(route)[0] == 404, "direct private/executable path denied: " + route)
            check(request("/assets/fixture.txt?secret=" + marker) == (200, b"static fixture"), "normal static assets still work with the replacement")
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
            stderr.close()
        logs = "\n".join(path.read_text() for path in tmp.glob("*.log"))
        check(marker not in logs and "a" * 64 not in logs and "fixture.invalid" not in logs, "bearer path, query, referrer and cookie markers are absent from all request/process logs")
        access = (tmp / "access.log").read_text()
        check("200" in access and "/assets/" not in access and "/transfer/" not in access, "static access logs use the redacted format while final FastCGI access logging stays off")
    print(f"{checks} Nginx checks passed")


if __name__ == "__main__":
    main()
