"""Fixture HTTP server for TableRefresh integration tests.

Only reachable on the private Docker network. Serves deterministic responses
for success and failure scenarios. Standard library only.
"""

import threading
import urllib.error
import urllib.request
import zlib
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

        # Large bodies are generated in chunks: building hundreds of MB in memory first can take
        # longer than the client's timeout under memory pressure, and holds the GIL meanwhile.
        if path == "/big":
            size = int(_qs(query, "bytes", "1000000"))
            self.send_response(200)
            self.send_header("Content-Type", "text/csv; charset=utf-8")
            if _qs(query, "cl", "1") == "1":
                self.send_header("Content-Length", str(size))
            else:
                self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                for part in _repeated(b"col1,col2,col3\n", b"1234567890,abcdefghij,ABCDEFGHIJ\n", size):
                    self.wfile.write(part)
            return None

        if path == "/gzip":
            size = int(_qs(query, "bytes", "50000000"))
            body = _gzip(_repeated(b"a,b\n", b"1,2\n", 4 + size // 4 * 4))
            return self._send(200, body, headers={"Content-Encoding": "gzip"})

        if path.startswith("/google/export/"):
            # Like docs.google.com/.../export?format=csv: a 307 with Google's odd content type.
            name = path[len("/google/export/"):]
            return self._send(307, b"", "application/binary", headers={"Location": "/google/ugc/" + name})

        if path.startswith("/google/ugc/"):
            # Like *.googleusercontent.com: the CSV as an attachment (tests write CRLF, no final EOL).
            body = self._file("data", path[len("/google/ugc/"):])
            if body is None:
                return self._send(404, b"Not found", "text/html; charset=utf-8")
            headers = {"Content-Disposition": 'attachment; filename="Sheet1.csv"'}
            return self._send(200, body, "text/csv; charset=utf-8", headers=headers)

        if path == "/gzip-huge":
            # About 1 GB of CSV when decompressed, about 1 MB on the wire.
            body = _huge_gzip()
            return self._send(200, body, headers={"Content-Encoding": "gzip"})

        if path == "/truncated":
            # The connection closes before the announced body is complete.
            mode = _qs(query, "mode", "cl")
            part = b"a,b\n" + b"1,2\n" * 200
            self.send_response(200)
            self.send_header("Content-Type", "text/csv")
            if mode == "chunked":
                self.send_header("Transfer-Encoding", "chunked")
                self.end_headers()
                self.wfile.write(b"%x\r\n%s\r\n" % (len(part), part))  # No terminating 0-chunk.
            else:
                self.send_header("Content-Length", str(len(part) * 2))
                self.end_headers()
                self.wfile.write(part)
            self.wfile.flush()
            self.close_connection = True
            return None

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


def _repeated(head, row, size):
    """Yields head + row repeated, cut to size bytes, in chunks of about 1 MB."""
    block = row * (1024 * 1024 // len(row))
    part = head
    while size > 0:
        part = part[:size]
        yield part
        size -= len(part)
        part = block


def _gzip(parts):
    """gzip container of the concatenated parts."""
    compressor = zlib.compressobj(9, zlib.DEFLATED, 31)  # 31: gzip container.
    return b"".join([compressor.compress(part) for part in parts] + [compressor.flush()])


_HUGE = []


def _huge_gzip():
    if not _HUGE:
        compressor = zlib.compressobj(9, zlib.DEFLATED, 31)  # 31: gzip container.
        chunk = b"a,b\n" + b"1,2\n" * (1024 * 1024 // 4)
        parts = [compressor.compress(chunk) for _ in range(1024)]
        parts.append(compressor.flush())
        _HUGE.append(b"".join(parts))
    return _HUGE[0]


class ProxyHandler(BaseHTTPRequestHandler):
    """Minimal forward proxy for plain-HTTP absolute-URI requests (no CONNECT)."""

    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        print("PROXY %s %s" % (self.command, self.path), flush=True)

    def do_GET(self):
        if not self.path.startswith("http://"):
            self.send_error(400, "absolute URI required")
            return
        request = urllib.request.Request(self.path, headers={"User-Agent": "fixture-proxy"})
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect())
        try:
            response = opener.open(request, timeout=10)
            status, headers, body = response.status, response.headers, response.read()
        except urllib.error.HTTPError as error:
            status, headers, body = error.code, error.headers, error.read()
        if status == 200 and "csv" in headers.get("Content-Type", ""):
            body = b"via-proxy\n" + body  # Marks data that went through the proxy.
        self.send_response(status)
        for name in ("Content-Type", "Location"):
            if headers.get(name):
                self.send_header(name, headers.get(name))
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


if __name__ == "__main__":
    ThreadingHTTPServer.daemon_threads = True
    threading.Thread(target=_huge_gzip, daemon=True).start()  # Build the gzip bomb ahead of time.
    proxy = ThreadingHTTPServer(("0.0.0.0", 3128), ProxyHandler)
    threading.Thread(target=proxy.serve_forever, daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", 80), Handler).serve_forever()
