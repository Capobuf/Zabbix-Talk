"""Local health endpoint for the Zabbix Talk bootstrap."""

import logging
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class HealthHandler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(2)

    def do_GET(self):
        if self.path != "/health":
            self.send_error(404)
            return

        body = b'{"service":"zabbix-talk","status":"ok"}\n'
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        # Avoid logging request paths or headers, which may contain future secrets.
        pass


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    with ThreadingHTTPServer(("127.0.0.1", 8765), HealthHandler) as server:
        logging.info("Zabbix Talk health listening on 127.0.0.1:8765")
        server.serve_forever()
