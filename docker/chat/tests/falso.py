"""Laravel FALSO: implementa en memoria el contrato de docs/chat-api-interna.md.

Sirve solo para probar el traductor. Textos especiales del usuario:
  "!lento ..."   la respuesta se queda IN_PROGRESS hasta que el test llama a liberar()
  "!falla ..."   la respuesta acaba FAILED con error
  "!secreto ..." la respuesta es un mensaje COMPLETED con una peticion de secreto
"""

from __future__ import annotations

import copy
import hashlib
import json
import re
import threading
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ID_VALIDO = re.compile(r"\A[A-Za-z0-9_-]{1,64}\Z")
HEX64 = re.compile(r"\A[0-9a-f]{64}\Z")
FINALES = ("COMPLETED", "FAILED", "CANCELLED")

HTTP = {
    "bad_request": 422, "too_large": 413, "unsupported_media": 415, "hash_mismatch": 422,
    "not_found": 404, "file_missing": 409, "bad_state": 409, "history_full": 507,
    "storage_full": 507, "busy": 503, "service_error": 500, "unimplemented": 501,
    "deshabilitado": 503,
}


class Fallo(Exception):
    def __init__(self, codigo: str, mensaje: str = "fallo de prueba") -> None:
        self.codigo = codigo
        self.mensaje = mensaje


def ahora() -> int:
    return int(time.time() * 1000)


class LaravelFalso:
    def __init__(self, secreto: str = "secreto-de-prueba") -> None:
        self.secreto = secreto
        self.cerrojo = threading.RLock()
        self.mensajes: list[dict] = []
        self.claves: dict[str, dict] = {}
        self.archivos: dict[str, dict] = {}
        self.secretos: dict[tuple[str, str], dict] = {}
        self.titulo: str | None = None
        self.titulo_ms = 0
        self.titulo_en_primera_respuesta = "Titulo de prueba"
        self.creada_ms = ahora()
        self.deshabilitado = False
        self.capacidades_listas = threading.Event()
        self.capacidades_listas.set()
        self.capacidades = {
            "tipos_mime": ["image/jpeg", "image/png"],
            "max_archivo_bytes": 4 * 1024 * 1024,
            "max_archivos": 4,
            "max_texto_bytes": 16384,
            "soporta_ramas": False,
            "soporta_cancelar": True,
        }
        self.paso = 0.03
        self.historial_max: int | None = None
        self.forzar_busy = False
        self.fallo_al_guardar = False
        self.almacen_lleno = False
        self.liberar_evento = threading.Event()
        self.peticiones: list[tuple[str, str]] = []
        self.valores_secretos: list[str] = []
        self._respuestas = 0

        falso = self

        class Manejador(BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *args):  # silencio
                pass

            def _responder(self, estado: int, cuerpo: bytes, tipo: str = "application/json") -> None:
                self.send_response(estado)
                self.send_header("Content-Type", tipo)
                self.send_header("Content-Length", str(len(cuerpo)))
                self.end_headers()
                self.wfile.write(cuerpo)

            def _despachar(self, metodo: str) -> None:
                longitud = int(self.headers.get("Content-Length") or 0)
                cuerpo = self.rfile.read(longitud) if longitud else b""
                try:
                    if self.headers.get("Authorization") != "Bearer " + falso.secreto:
                        raise Fallo("service_error", "no autorizado")
                    ruta, _, consulta = self.path.partition("?")
                    base = "/interno/chat"
                    assert ruta.startswith(base)
                    ruta = ruta[len(base):]
                    falso.peticiones.append((metodo, ruta.split("?")[0]))
                    resultado = falso.manejar(metodo, ruta, urllib.parse.parse_qs(consulta), cuerpo, self.headers)
                except Fallo as f:
                    datos = json.dumps({"codigo": f.codigo, "mensaje": f.mensaje}).encode()
                    estado = 401 if f.mensaje == "no autorizado" else HTTP[f.codigo]
                    self._responder(estado, datos)
                    return
                if isinstance(resultado, bytes):
                    self._responder(200, resultado, resultado_tipo(self))
                else:
                    self._responder(200, json.dumps(resultado).encode())

            def do_GET(self):
                self._despachar("GET")

            def do_POST(self):
                self._despachar("POST")

            def do_PUT(self):
                self._despachar("PUT")

        def resultado_tipo(_):
            return "application/octet-stream"

        self.servidor = ThreadingHTTPServer(("127.0.0.1", 0), Manejador)
        self.servidor.daemon_threads = True
        self.puerto = self.servidor.server_address[1]
        self.hilo = threading.Thread(target=lambda: self.servidor.serve_forever(poll_interval=0.02), daemon=True)

    # -- ciclo de vida -------------------------------------------------

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.puerto}"

    def iniciar(self) -> "LaravelFalso":
        self.hilo.start()
        return self

    def parar(self) -> None:
        self.liberar()
        self.servidor.shutdown()
        self.servidor.server_close()

    def liberar(self) -> None:
        self.liberar_evento.set()

    # -- utilidades de mensajes ----------------------------------------

    def _nuevo(self, **campos) -> dict:
        with self.cerrojo:
            ms = ahora()
            m = {
                "message_id": "m%d" % (len(self.mensajes) + 1),
                "sequence": len(self.mensajes) + 1,
                "conversation_id": None,
                "content": [],
                "status": "COMPLETED",
                "created_ms": ms,
                "updated_ms": ms,
                "reply_to": None,
                "pinned": False,
                "metadata": {},
                "from_service": False,
                "reasoning": "",
                "idempotency_key": None,
                "error": None,
                "suggestions": [],
                "corrector": None,
            }
            m.update(campos)
            self.mensajes.append(m)
            return m

    def buscar(self, id_mensaje: str) -> dict:
        for m in self.mensajes:
            if m["message_id"] == id_mensaje:
                return m
        raise Fallo("not_found", "El mensaje no existe.")

    @staticmethod
    def texto(m: dict) -> str:
        return "".join(p.get("texto") or "" for p in m["content"] if p["tipo"] == "texto")

    def ultima(self) -> int:
        return len(self.mensajes)

    # -- enrutado ------------------------------------------------------

    def manejar(self, metodo, ruta, consulta, cuerpo, cabeceras):
        if ruta == "/salud":
            return {"habilitado": not self.deshabilitado}
        if self.deshabilitado:
            raise Fallo("deshabilitado", "Chat apagado.")
        if ruta == "/capacidades":
            if not self.capacidades_listas.is_set():
                raise Fallo("service_error", "arrancando")
            return self.capacidades
        datos = json.loads(cuerpo) if cuerpo and metodo == "POST" else {}
        with self.cerrojo:
            if ruta == "/conversacion":
                return {"titulo": self.titulo, "creada_ms": self.creada_ms, "ultima_secuencia": self.ultima()}
            if ruta == "/mensajes" and metodo == "POST":
                return self.enviar(datos)
            if ruta == "/mensajes" and metodo == "GET":
                return {"mensajes": self.listar(consulta)}
            m = re.fullmatch(r"/mensajes/([^/]+)(?:/(fijar|cancelar))?", ruta)
            if m:
                mensaje = self.buscar(urllib.parse.unquote(m.group(1)))
                if m.group(2) is None:
                    return copy.deepcopy(mensaje)
                if m.group(2) == "fijar":
                    if bool(datos["fijado"]) != mensaje["pinned"]:
                        mensaje["pinned"] = bool(datos["fijado"])
                        mensaje["updated_ms"] = max(ahora(), mensaje["updated_ms"])
                    return copy.deepcopy(mensaje)
                if mensaje["status"] in FINALES:
                    raise Fallo("bad_state", "El mensaje ya es final.")
                mensaje["status"] = "CANCELLED"
                mensaje["updated_ms"] = ahora()
                return copy.deepcopy(mensaje)
            if ruta == "/sincronizar":
                return self.sincronizar(datos)
            if ruta == "/archivos/consultar":
                return {
                    "resultados": [
                        {"hash": h, "disponible": h in self.archivos} for h in datos["hashes"]
                    ]
                }
            m = re.fullmatch(r"/archivos/([0-9a-f]{64})", ruta)
            if m and metodo == "PUT":
                return self.subir(m.group(1), cuerpo, cabeceras)
            if m and metodo == "GET":
                if m.group(1) not in self.archivos:
                    raise Fallo("not_found", "No existe.")
                return self.archivos[m.group(1)]["datos"]
            if ruta == "/secretos":
                return self.secreto_respuesta(datos)
        raise Fallo("not_found", "Ruta desconocida.")

    # -- mensajes ------------------------------------------------------

    def listar(self, consulta) -> list[dict]:
        despues = int(consulta.get("despues", ["0"])[0])
        antes = int(consulta.get("antes", ["0"])[0])
        limite = int(consulta.get("limite", ["0"])[0])
        ultimos = int(consulta.get("ultimos", ["0"])[0])
        if limite > 0 and ultimos > 0:
            raise Fallo("bad_request", "limite y ultimos")
        if despues > self.ultima():
            raise Fallo("bad_request", "despues mayor que la ultima")
        rango = [
            m for m in self.mensajes if m["sequence"] > despues and (antes == 0 or m["sequence"] < antes)
        ]
        if ultimos > 0:
            rango = rango[-ultimos:]
        else:
            rango = rango[: (limite or 500)]
        return copy.deepcopy(rango)

    def enviar(self, d: dict) -> dict:
        if self.fallo_al_guardar:
            raise Fallo("service_error", "No se pudo guardar.")
        contenido = d.get("content") or []
        clave = d.get("idempotency_key")
        adjuntos = [p for p in contenido if p.get("tipo") in ("imagen", "audio")]
        # paso 1
        if not contenido:
            raise Fallo("bad_request", "sin contenido")
        for p in contenido:
            if p.get("tipo") not in ("texto", "imagen", "audio"):
                raise Fallo("bad_request", "parte no valida")
        if not adjuntos and not "".join(p.get("texto") or "" for p in contenido):
            raise Fallo("bad_request", "texto vacio")
        if clave is not None and not ID_VALIDO.match(clave):
            raise Fallo("bad_request", "clave no valida")
        for p in adjuntos:
            mime = p["mime"].split(";")[0].strip().lower()
            if not HEX64.match(p["hash"]) or p["tamano"] < 1:
                raise Fallo("bad_request", "adjunto")
            if (p["tipo"] == "imagen" and mime.startswith("audio/")) or (
                p["tipo"] == "audio" and mime.startswith("image/")
            ):
                raise Fallo("bad_request", "clase de mime")
        if len(adjuntos) > 4:
            raise Fallo("bad_request", "demasiados adjuntos")
        # paso 2
        texto = "".join(p.get("texto") or "" for p in contenido if p["tipo"] == "texto")
        if len(texto.encode()) > self.capacidades["max_texto_bytes"]:
            raise Fallo("too_large", "texto")
        for p in adjuntos:
            if p["tamano"] > self.capacidades["max_archivo_bytes"]:
                raise Fallo("too_large", "archivo")
        # paso 3
        for p in adjuntos:
            if p["mime"].split(";")[0].strip().lower() not in self.capacidades["tipos_mime"]:
                raise Fallo("unsupported_media", "tipo")
        # paso 4
        if d.get("reply_to"):
            self.buscar(d["reply_to"])
        # paso 5
        for p in adjuntos:
            if p["hash"] not in self.archivos:
                raise Fallo("file_missing", "falta el archivo")
        # paso 6
        if clave is not None and clave in self.claves:
            return copy.deepcopy(self.claves[clave])
        # pasos 7 y 8
        if self.historial_max is not None and len(self.mensajes) + 2 > self.historial_max:
            raise Fallo("history_full", "historial lleno")
        if self.forzar_busy:
            raise Fallo("busy", "ocupado")
        partes = []
        for p in contenido:
            q = dict(p)
            if "mime" in q:
                q["mime"] = q["mime"].split(";")[0].strip().lower()
            partes.append(q)
        m = self._nuevo(
            content=partes, reply_to=d.get("reply_to"), idempotency_key=clave, corrector=d.get("corrector")
        )
        if clave is not None:
            self.claves[clave] = m
        resultado = copy.deepcopy(m)
        threading.Thread(target=self._responder, args=(m["message_id"],), daemon=True).start()
        return resultado

    def _responder(self, id_usuario: str) -> None:
        with self.cerrojo:
            usuario = self.buscar(id_usuario)
            texto = self.texto(usuario)
            if texto.startswith("!secreto"):
                self._nuevo(
                    from_service=True,
                    reply_to=id_usuario,
                    content=[
                        {"tipo": "texto", "texto": "Necesito un secreto."},
                        {"tipo": "peticion_secreto", "request_id": "sr%d" % (len(self.mensajes) + 1),
                         "nombre": "TEST_SECRET", "prosa": "Hace falta para probar."},
                    ],
                )
                return
            asistente = self._nuevo(
                from_service=True, reply_to=id_usuario, status="IN_PROGRESS",
                content=[{"tipo": "texto", "texto": ""}],
            )
        if texto.startswith("!lento"):
            self.liberar_evento.wait(30)
        self._escribir(asistente["message_id"], reasoning="Pienso. ")
        for trozo in ("eco: ", texto, " ✓"):
            time.sleep(self.paso)
            self._escribir(asistente["message_id"], texto=trozo)
        time.sleep(self.paso)
        with self.cerrojo:
            if asistente["status"] in FINALES:
                return
            if texto.startswith("!falla"):
                asistente["status"] = "FAILED"
                asistente["error"] = "No se pudo responder."
            else:
                asistente["status"] = "COMPLETED"
                asistente["suggestions"] = ["Si", "No"]
            asistente["updated_ms"] = max(ahora(), asistente["updated_ms"])
            if self.titulo is None and self.titulo_en_primera_respuesta:
                self.titulo = self.titulo_en_primera_respuesta
                self.titulo_ms = ahora()

    def _escribir(self, id_mensaje: str, texto: str = "", reasoning: str = "") -> None:
        with self.cerrojo:
            m = self.buscar(id_mensaje)
            if m["status"] in FINALES:
                return
            m["content"][-1]["texto"] += texto
            m["reasoning"] += reasoning

    def sincronizar(self, d: dict) -> dict:
        despues = d["despues"]
        nuevos = [copy.deepcopy(m) for m in self.mensajes if m["sequence"] > despues]
        deltas, estados, fijados = [], [], []
        for a in d["abiertos"]:
            m = self.buscar(a["id"])
            t = self.texto(m).encode("utf-8")
            r = m["reasoning"].encode("utf-8")
            nt = _recortar(t[a["texto"]:])
            nr = _recortar(r[a["razonamiento"]:])
            if nt or nr:
                deltas.append({"id": a["id"], "texto_anadido": nt.decode(), "razonamiento_anadido": nr.decode()})
            quedan = len(t) - a["texto"] - len(nt) + len(r) - a["razonamiento"] - len(nr)
            if m["status"] != a["estado"] and quedan == 0:
                estado = {"id": a["id"], "estado": m["status"], "actualizado_ms": m["updated_ms"],
                          "error": m["error"] if m["status"] == "FAILED" else None,
                          "sugerencias": m["suggestions"] if m["status"] == "COMPLETED" else []}
                estados.append(estado)
            if m["pinned"] != a["fijado"]:
                fijados.append({"id": a["id"], "fijado": m["pinned"], "actualizado_ms": m["updated_ms"]})
        cambio = self.titulo != d["titulo"]
        return {
            "ultima_secuencia": self.ultima(),
            "nuevos": nuevos,
            "deltas": deltas,
            "estados": estados,
            "fijados": fijados,
            "titulo": {"cambio": cambio, "valor": self.titulo if cambio else None, "actualizado_ms": self.titulo_ms},
        }

    # -- archivos ------------------------------------------------------

    def subir(self, hex_hash: str, cuerpo: bytes, cab) -> dict:
        mime = (cab.get("X-Mime") or "").lower()
        declarado = int(cab.get("X-Tamano") or -1)
        if declarado < 1:
            raise Fallo("bad_request", "tamano")
        if declarado > self.capacidades["max_archivo_bytes"] or len(cuerpo) > self.capacidades["max_archivo_bytes"]:
            raise Fallo("too_large", "grande")
        if mime not in self.capacidades["tipos_mime"]:
            raise Fallo("unsupported_media", "tipo")
        if self.almacen_lleno:
            raise Fallo("storage_full", "lleno")
        if declarado != len(cuerpo) or hashlib.sha3_256(cuerpo).hexdigest() != hex_hash:
            raise Fallo("hash_mismatch", "hash")
        nombre = urllib.parse.unquote(cab["X-Nombre"]) if cab.get("X-Nombre") else None
        self.archivos.setdefault(hex_hash, {"mime": mime, "datos": cuerpo, "nombre": nombre})
        return {"hash": hex_hash, "mime": mime, "tamano": len(cuerpo), "nombre": nombre}

    # -- secretos ------------------------------------------------------

    def secreto_respuesta(self, d: dict) -> dict:
        m = self.buscar(d["message_id"])
        if not any(p["tipo"] == "peticion_secreto" and p["request_id"] == d["request_id"] for p in m["content"]):
            raise Fallo("not_found", "peticion")
        valor, omitido = d.get("valor"), bool(d.get("omitido"))
        if (valor is None) == (not omitido):
            raise Fallo("bad_request", "exactamente uno")
        clave = (d["message_id"], d["request_id"])
        if clave in self.secretos:
            return {}
        self.secretos[clave] = {"omitido": omitido}
        if valor is not None:
            self.valores_secretos.append(valor)  # solo para que el test sepa que llego
        self._nuevo(
            from_service=True, reply_to=d["message_id"],
            content=[{"tipo": "texto", "texto": "Omitido." if omitido else "Gracias."}],
        )
        return {}


def _recortar(datos: bytes, maximo: int = 65536) -> bytes:
    corte = datos[:maximo]
    if len(datos) > maximo:
        while corte:
            try:
                corte.decode("utf-8")
                break
            except UnicodeDecodeError:
                corte = corte[:-1]
    return corte
