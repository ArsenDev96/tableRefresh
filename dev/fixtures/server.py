"""Fixture HTTP server for TableRefresh integration tests.

Only reachable on the private Docker network. Serves deterministic responses
for success and failure scenarios. Standard library only.
"""

import gzip
import os
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

ROOT = "/srv"


def _qs(query, key, default):
    return query.get(key, [default])[0]


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # Keep container logs readable.
        print("%s %s" % (self.command, self.path), flush=True)

    def _send(self, status, body, content_type="text/csv; charset=utf-8", headers=None, content_length=True):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        for name, value in (headers or {}).items():
            self.send_header(name, value)
        if content_length:
            self.send_header("Content-Length", str(len(body)))
        else:
            self.send_header("Connection", "close")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _file(self, base, name):
        path = os.path.realpath(os.path.join(ROOT, base, name))
        if not path.startswith(os.path.join(ROOT, base) + os.sep) or not os.path.isfile(path):
            return None
        with open(path, "rb") as handle:
            return handle.read()

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):  # noqa: C901 - one switch over fixture routes.
        url = urlparse(self.path)
        query = parse_qs(url.query)
        path = url.path

        if path.startswith("/static/") or path.startswith("/data/"):
            base, name = path[1:].split("/", 1)
            body = self._file(base, name)
            if body is None:
                return self._send(404, b"Not found", "text/plain")
            ctype = _qs(query, "type", "text/csv; charset=utf-8")
            delay = float(_qs(query, "delay", "0"))
            if delay:
                time.sleep(delay)
            return self._send(200, body, ctype)

        if path == "/html":
            body = b"<!DOCTYPE html><html><head><title>Sign in</title></head><body><form>Login</form></body></html>"
            return self._send(200, body, "text/html; charset=utf-8")

        if path == "/html-as-csv":
            body = b"\n\n  <!doctype html>\n<html><body><p>Sorry, this sheet is private</p></body></html>"
            return self._send(200, body, "text/csv")

        if path == "/hang":
            time.sleep(float(_qs(query, "seconds", "30")))
            return self._send(200, b"a,b\n1,2\n")

        if path == "/big":
            size = int(_qs(query, "bytes", "1000000"))
            row = b"1234567890,abcdefghij,ABCDEFGHIJ\n"
            body = b"col1,col2,col3\n" + row * (size // len(row) + 1)
            body = body[:size]
            return self._send(200, body, content_length=_qs(query, "cl", "1") == "1")

        if path == "/gzip":
            size = int(_qs(query, "bytes", "50000000"))
            body = gzip.compress(b"a,b\n" + b"1,2\n" * (size // 4), compresslevel=9)
            return self._send(200, body, headers={"Content-Encoding": "gzip"})

        if path == "/redirect":
            target = _qs(query, "to", "/static/simple.csv")
            code = int(_qs(query, "code", "302"))
            return self._send(code, b"", "text/plain", headers={"Location": target})

        if path == "/redirect-chain":
            hops = int(_qs(query, "hops", "3"))
            target = "/static/simple.csv" if hops <= 1 else "/redirect-chain?hops=%d" % (hops - 1)
            return self._send(302, b"", "text/plain", headers={"Location": target})

        if path == "/status":
            code = int(_qs(query, "code", "500"))
            return self._send(code, b"error", "text/plain")

        if path == "/binary":
            return self._send(200, bytes(range(256)) * 8, "application/octet-stream")

        return self._send(404, b"Not found", "text/plain")


if __name__ == "__main__":
    ThreadingHTTPServer.daemon_threads = True
    ThreadingHTTPServer(("0.0.0.0", 80), Handler).serve_forever()
