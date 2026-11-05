#!/usr/bin/env python3
"""Pagina de estado del arranque: lo primero que escucha en :8000, antes de que exista la web.

Sirve /estado (la pagina), /estado.json (fases que escribe start.sh) y /app.log (el log del arranque; admite
«Range: bytes=-N» para leer solo el final). Cualquier otra ruta enseña la pagina con 503: la web aun no esta lista.
start.sh lo para justo antes de lanzar FrankenPHP, que sirve esas mismas rutas desde su Caddyfile con los mismos ficheros.
Solo biblioteca estandar: corre como el usuario `app`, con el entorno vacio.
"""
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PUBLICO = sys.argv[1]
PUERTO = int(sys.argv[2])
PAGINA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "index.html")


class Manejador(BaseHTTPRequestHandler):
    server_version = "estado"

    def log_message(self, *args):  # la pagina consulta cada 2 s: no llenar app.log con eso
        pass

    def responder(self, codigo, tipo, cuerpo, extra=None):
        self.send_response(codigo)
        self.send_header("Content-Type", tipo)
        self.send_header("Content-Length", str(len(cuerpo)))
        self.send_header("Cache-Control", "no-store")
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(cuerpo)

    def fichero(self, nombre, tipo):
        try:
            with open(os.path.join(PUBLICO, nombre), "rb") as f:
                datos = f.read()
        except OSError:
            datos = b""
        rango = self.headers.get("Range", "")
        if nombre == "app.log" and rango.startswith("bytes=-") and rango[7:].isdigit() and datos:
            n = int(rango[7:])
            total = len(datos)
            return self.responder(206, tipo, datos[-n:] if n else b"", {
                "Content-Range": f"bytes {max(total - n, 0)}-{total - 1}/{total}"})
        self.responder(200, tipo, datos)

    do_HEAD = do_GET = lambda self: self.servir()

    def servir(self):
        ruta = self.path.split("?", 1)[0]
        if ruta == "/estado.json":
            return self.fichero("estado.json", "application/json")
        if ruta == "/app.log":
            return self.fichero("app.log", "text/plain; charset=utf-8")
        with open(PAGINA, "rb") as f:
            self.responder(200 if ruta == "/estado" else 503, "text/html; charset=utf-8", f.read())


ThreadingHTTPServer(("0.0.0.0", PUERTO), Manejador).serve_forever()
