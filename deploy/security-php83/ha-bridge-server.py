#!/usr/bin/env python3
"""Expose only the existing sudo-approved HA actions to the PHP container."""
import os
import json
import re
import socketserver
import subprocess

SOCKET = "/var/lib/simanis-ha-bridge/bridge.sock"
ACTIONS = {
    "bothwa-start": "simanis-bothwa-start",
    "bothwa-stop": "simanis-bothwa-stop",
    "bothwa-status": "simanis-bothwa-status",
    "ocr-start": "simanis-ktp-ocr-start",
    "ocr-stop": "simanis-ktp-ocr-stop",
    "ocr-restart": "simanis-ktp-ocr-restart",
    "ocr-status": "simanis-ktp-ocr-status",
    "minio-sync": "simanis-minio-sync",
    "ocr-config": "simanis-ocr-config-update",
}


def command_version(arguments, pattern=None):
    try:
        result = subprocess.run(
            arguments,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            universal_newlines=True,
            timeout=3,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return None
    if result.returncode:
        return None
    first_line = result.stdout.splitlines()[0].strip() if result.stdout else ""
    if pattern:
        match = re.search(pattern, first_line)
        return match.group(1) if match else None
    return first_line or None


def system_info():
    os_name = None
    try:
        with open("/etc/os-release", encoding="utf-8") as release:
            for line in release:
                if line.startswith("PRETTY_NAME="):
                    os_name = line.partition("=")[2].strip().strip('"')
                    break
    except OSError:
        pass
    return {
        "host_os": os_name,
        "apache": command_version(["/usr/sbin/apache2", "-v"], r"Apache/([^ ]+)"),
        "php_cli_host": command_version(["/usr/bin/php", "-r", "echo PHP_VERSION;"]),
        "minio": command_version(["/usr/local/bin/minio", "--version"], r"(RELEASE\.[^ ]+)"),
        "docker": command_version(["/usr/bin/dockerd", "--version"], r"Docker version ([^,]+)"),
    }


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        action = self.rfile.readline(64).decode("ascii", "replace").strip()
        if action == "system-info":
            try:
                payload = json.dumps(system_info())
            except Exception:
                self.wfile.write(b"SIMANIS_EXIT:1\nHost inventory unavailable\n")
                return
            self.wfile.write(b"SIMANIS_EXIT:0\n")
            self.wfile.write((payload + "\n").encode())
            return
        script = ACTIONS.get(action)
        if script is None:
            self.wfile.write(b"SIMANIS_EXIT:2\nUnknown HA action\n")
            return
        try:
            payload = None
            if action == "ocr-config":
                payload = self.rfile.readline(2049)
                if len(payload) > 2048:
                    self.wfile.write(b"SIMANIS_EXIT:2\nOCR settings too large\n")
                    return
            result = subprocess.run(
                ["sudo", "-n", "/usr/local/sbin/" + script],
                input=payload,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                timeout=120,
                check=False,
            )
            self.wfile.write(("SIMANIS_EXIT:%d\n" % result.returncode).encode())
            self.wfile.write(result.stdout[-65536:])
        except subprocess.TimeoutExpired:
            self.wfile.write(b"SIMANIS_EXIT:124\nHA action timed out\n")


if __name__ == "__main__":
    if os.path.exists(SOCKET):
        os.unlink(SOCKET)
    with socketserver.UnixStreamServer(SOCKET, Handler) as server:
        os.chmod(SOCKET, 0o660)
        server.serve_forever()
