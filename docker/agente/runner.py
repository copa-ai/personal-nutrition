#!/usr/bin/env python3
"""Servidor interno que ejecuta `claude -p` dentro del contenedor del agente.

La administracion (Laravel) no ejecuta claude: le pide las ejecuciones a este servidor por HTTP, de modo que
el agente solo ve su carpeta (agente/) y nunca la base de datos, el .env ni las claves de la administracion. Los archivos que
intercambian (encargo, resultado, base del agente) viajan en las peticiones HTTP: es la administracion quien los guarda en la base.

  POST   /ejecuciones        {prompt, herramientas?, timeout?, token?, archivos?: {ruta: base64}} -> 202 {id}
                             `archivos` (encargos/ y base/) se escriben en agente/ antes de lanzar claude
  GET    /ejecuciones/<id>?desde=N -> {estado, codigo, tiempo_agotado, interrumpida, chunks: [[tipo, texto], ...], siguiente}
  GET    /ejecuciones/<id>/archivos -> {archivos: {ruta: base64}}  lo que dejo la ejecucion en workspace/ y base/ (solo si ya termino)
  DELETE /ejecuciones/<id>   mata la ejecucion
  GET    /salud              sin autenticacion (para el healthcheck)

Las ejecuciones se guardan en disco (AGENTE_ESTADO: un <id>.json con su estado y un <id>.chunks con la salida), no solo en
memoria. Si el servidor o el contenedor se reinicia, las ejecuciones que ya habian terminado se siguen sirviendo y las que
estaban en marcha (su proceso `claude` muere con el reinicio) se dan por terminadas con `interrumpida: true`, de modo que
quien las seguia recibe una respuesta clara en vez de un 404.

Todo lo demas exige `Authorization: Bearer $AGENTE_SECRETO`. Solo biblioteca estandar.
"""
import base64
import hmac
import json
import os
import signal
import subprocess
import sys
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

SECRETO = os.environ.get("AGENTE_SECRETO", "")
CARPETA = os.environ.get("AGENTE_CARPETA", "/app/agente")
CLAUDE = os.environ.get("AGENTE_CLAUDE", "claude")
PUERTO = int(os.environ.get("AGENTE_PUERTO", "9000"))
# Donde se guardan las ejecuciones (un volumen propio: tiene que sobrevivir a un reinicio del contenedor).
ESTADO = os.environ.get("AGENTE_ESTADO", "/var/lib/agente-estado")
# Sandbox: si se define, claude SOLO tiene estas herramientas (p. ej. "Read,Glob,Grep") y se le deniegan expresamente las de red y Bash,
# pase lo que pase con la lista de permisos de agente/.claude/settings.json o con las herramientas que pida la administracion.
SOLO_HERRAMIENTAS = os.environ.get("AGENTE_SOLO_HERRAMIENTAS", "").strip()
DIRECTORIOS_EXTRA = [d for d in os.environ.get("AGENTE_DIRECTORIOS_EXTRA", "").split(":") if d]
MAX_SIMULTANEAS = int(os.environ.get("AGENTE_MAX_SIMULTANEAS", "3"))
MAX_CUERPO = 64_000_000         # bytes de una peticion (el prompt y, en base64, los archivos de entrada)
MAX_SALIDA = 20_000_000         # caracteres que se guardan de una ejecucion; el resto se descarta
RETENCION = 3600                # segundos que se recuerda una ejecucion terminada
TIMEOUT_MAXIMO = 4 * 3600
POSIX = os.name == "posix"

# Archivos que se intercambian con la administracion (rutas relativas a agente/).
ENTRADA = ("encargos/", "base/")      # administracion -> agente, en la peticion que lanza la ejecucion
SALIDA = ("workspace/", "base/")      # agente -> administracion, GET /ejecuciones/<id>/archivos
SUBIR_SIEMPRE = (".sqlite", "estado-actual.json")   # la base es un unico archivo (y su estado-actual.json va con ella): se devuelven aunque la ejecucion no los haya tocado
MAX_ARCHIVO = 8_000_000

ejecuciones = {}
cerrojo = threading.Lock()


def _ruta(id_, extension):
    return os.path.join(ESTADO, "%s.%s" % (id_, extension))


class Ejecucion:
    def __init__(self, orden, entorno, timeout):
        self.id = uuid.uuid4().hex
        self.chunks = []            # [tipo, texto] en el orden en que salieron
        self.guardado = 0
        self.codigo = None
        self.tiempo_agotado = False
        self.interrumpida = False
        self.terminado_en = None
        self.cerrojo = threading.Lock()
        self.inicio = time.time()
        self.archivo_chunks = open(_ruta(self.id, "chunks"), "a", encoding="utf-8")
        self._guardar_estado()
        try:
            self.proceso = subprocess.Popen(
                orden, cwd=CARPETA, env=entorno, stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=POSIX,
            )
        except OSError:
            self.archivo_chunks.close()   # no llego a lanzarse: no queda nada que recuperar
            for extension in ("json", "chunks"):
                os.remove(_ruta(self.id, extension))
            raise
        self.lectores = [threading.Thread(target=self._leer, args=(t, f), daemon=True)
                         for t, f in (("out", self.proceso.stdout), ("err", self.proceso.stderr))]
        for l in self.lectores:
            l.start()
        threading.Thread(target=self._esperar, args=(timeout,), daemon=True).start()

    @classmethod
    def recuperar(cls, datos):
        """Una ejecucion de antes del reinicio: solo lectura (ya no hay proceso). Si no habia terminado, se da por interrumpida."""
        e = cls.__new__(cls)
        e.id = datos["id"]
        e.inicio = datos.get("inicio", time.time())
        e.codigo = datos.get("codigo")
        e.tiempo_agotado = bool(datos.get("tiempo_agotado"))
        e.interrumpida = bool(datos.get("interrumpida"))
        e.terminado_en = datos.get("terminado_en")
        e.cerrojo = threading.Lock()
        e.proceso = None
        e.archivo_chunks = None
        e.chunks = []
        e.guardado = 0
        try:
            with open(_ruta(e.id, "chunks"), encoding="utf-8") as f:
                for linea in f:
                    try:
                        tipo, texto = json.loads(linea)
                    except ValueError:
                        continue   # la ultima linea puede haber quedado a medias al morir el servidor
                    e.chunks.append([tipo, texto])
                    e.guardado += len(texto)
        except FileNotFoundError:
            pass
        if e.terminado_en is None:
            e.interrumpida = True
            e.codigo = -1
            e.terminado_en = time.time()
            aviso = ["err", "\n[runner] el agente se reinicio mientras se ejecutaba: la ejecucion se perdio.\n"]
            e.chunks.append(aviso)
            with open(_ruta(e.id, "chunks"), "a", encoding="utf-8") as f:
                f.write(json.dumps(aviso, ensure_ascii=False) + "\n")
            try:
                e._guardar_estado()
            except OSError as ex:   # se sigue sirviendo desde memoria; en el siguiente arranque se volvera a intentar
                sys.stderr.write("runner: no se pudo guardar el estado de %s: %s\n" % (e.id, ex))
        return e

    def _guardar_estado(self):
        # Escritura atomica: un reinicio a mitad no deja un .json corrupto.
        destino = _ruta(self.id, "json")
        with open(destino + ".tmp", "w", encoding="utf-8") as f:
            json.dump({"id": self.id, "inicio": self.inicio, "codigo": self.codigo, "tiempo_agotado": self.tiempo_agotado,
                       "interrumpida": self.interrumpida, "terminado_en": self.terminado_en}, f)
        os.replace(destino + ".tmp", destino)

    def _anadir(self, tipo, texto):
        """Guarda un trozo de salida en memoria y en disco. Llamar con self.cerrojo tomado."""
        if self.guardado < MAX_SALIDA:
            self.chunks.append([tipo, texto])
            self.guardado += len(texto)
            if self.archivo_chunks:
                self.archivo_chunks.write(json.dumps([tipo, texto], ensure_ascii=False) + "\n")
                self.archivo_chunks.flush()

    def _leer(self, tipo, flujo):
        # Se lee lo que haya (no linea a linea) para que el registro de un menú largo vaya llegando.
        while True:
            datos = os.read(flujo.fileno(), 8192)
            if not datos:
                return
            texto = datos.decode("utf-8", errors="replace")
            with self.cerrojo:
                self._anadir(tipo, texto)

    def _esperar(self, timeout):
        try:
            self.codigo = self.proceso.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            self.tiempo_agotado = True
            self.matar()
            self.codigo = self.proceso.wait()
        # Que los lectores vacien las tuberias antes de dar la ejecucion por terminada (un nieto puede mantenerlas abiertas).
        for l in self.lectores:
            l.join(timeout=5)
        with self.cerrojo:
            self.terminado_en = time.time()
            self._guardar_estado()   # despues de que la salida este completa en disco
            self.archivo_chunks.close()

    def matar(self):
        if self.proceso is None or self.proceso.poll() is not None:
            return
        try:
            if POSIX:
                os.killpg(self.proceso.pid, signal.SIGKILL)   # claude lanza hijos (Bash, python...)
            else:
                self.proceso.kill()
        except (ProcessLookupError, PermissionError):
            pass

    @property
    def corriendo(self):
        return self.terminado_en is None

    def archivos(self):
        """Lo que dejo esta ejecucion en workspace/ y base/: lo modificado desde que empezo, y la base entera (SUBIR_SIEMPRE)."""
        salida = {}
        for carpeta in SALIDA:
            raiz = os.path.join(CARPETA, carpeta)
            for dirpath, _, nombres in os.walk(raiz):
                for nombre in nombres:
                    ruta = os.path.join(dirpath, nombre)
                    relativa = os.path.relpath(ruta, CARPETA).replace(os.sep, "/")
                    if os.path.islink(ruta) or not os.path.isfile(ruta) or os.path.getsize(ruta) > MAX_ARCHIVO:
                        continue
                    if os.path.getmtime(ruta) >= self.inicio - 2 or relativa.endswith(SUBIR_SIEMPRE):
                        with open(ruta, "rb") as f:
                            salida[relativa] = base64.b64encode(f.read()).decode("ascii")
        return salida

    def estado(self, desde):
        with self.cerrojo:
            trozo = self.chunks[desde:]
            siguiente = desde + len(trozo)
        return {
            "estado": "corriendo" if self.corriendo else "terminado",
            "codigo": self.codigo if not self.corriendo else None,
            "tiempo_agotado": self.tiempo_agotado,
            "interrumpida": self.interrumpida,
            "chunks": trozo,
            "siguiente": siguiente,
        }


def escribir_entrada(archivos):
    """Escribe en agente/ los archivos de entrada que manda la administracion. Solo encargos/ y base/, sin salir de agente/."""
    if archivos is None:
        return
    if not isinstance(archivos, dict):
        raise ValueError("archivos tiene que ser un objeto ruta -> base64")
    raiz = os.path.realpath(CARPETA)
    for relativa, contenido in archivos.items():
        if not isinstance(relativa, str) or not isinstance(contenido, str) or not relativa.startswith(ENTRADA) or ".." in relativa.split("/"):
            raise ValueError("archivo no permitido: %r" % (relativa,))
        destino = os.path.realpath(os.path.join(CARPETA, relativa))
        if not destino.startswith(raiz + os.sep):
            raise ValueError("archivo fuera de agente/: %r" % (relativa,))
        os.makedirs(os.path.dirname(destino), exist_ok=True)
        with open(destino, "wb") as f:
            f.write(base64.b64decode(contenido, validate=True))


def limpiar():
    ahora = time.time()
    for k in [k for k, e in ejecuciones.items() if e.terminado_en and ahora - e.terminado_en > RETENCION]:
        del ejecuciones[k]
        for extension in ("json", "chunks"):
            try:
                os.remove(_ruta(k, extension))
            except FileNotFoundError:
                pass


def recuperar():
    """Vuelve a cargar las ejecuciones guardadas por una vida anterior del servidor."""
    os.makedirs(ESTADO, exist_ok=True)
    for nombre in sorted(os.listdir(ESTADO)):
        if not nombre.endswith(".json"):
            continue
        try:
            with open(os.path.join(ESTADO, nombre), encoding="utf-8") as f:
                e = Ejecucion.recuperar(json.load(f))
            ejecuciones[e.id] = e
        except (OSError, ValueError, KeyError) as ex:
            sys.stderr.write("runner: no se pudo recuperar %s: %s\n" % (nombre, ex))


def orden_de(datos):
    prompt = datos.get("prompt")
    if not isinstance(prompt, str) or not prompt.strip():
        raise ValueError("falta el prompt")
    orden = [CLAUDE, "-p", prompt]
    modelo = datos.get("modelo")
    if modelo:
        if not isinstance(modelo, str) or modelo.startswith("-"):
            raise ValueError("modelo no valido")
        orden += ["--model", modelo]
    herramientas = datos.get("herramientas")
    if herramientas:
        if not isinstance(herramientas, str):
            raise ValueError("herramientas tiene que ser un texto")
        orden += ["--allowedTools", herramientas]
    if datos.get("stream") is True:
        orden += ["--output-format", "stream-json", "--verbose"]   # un evento JSON por linea; la administracion lo traduce
    if SOLO_HERRAMIENTAS:
        orden += ["--tools", SOLO_HERRAMIENTAS, "--disallowedTools", "Bash,WebFetch,WebSearch,Task,Write,Edit,NotebookEdit"]
        for d in DIRECTORIOS_EXTRA:
            orden += ["--add-dir", d]
    timeout = datos.get("timeout", 300)
    if not isinstance(timeout, (int, float)) or timeout <= 0:
        raise ValueError("timeout no valido")
    entorno = dict(os.environ)
    entorno.pop("AGENTE_SECRETO", None)   # claude y lo que lance no necesitan el secreto del servidor
    token = datos.get("token")
    if token:
        if not isinstance(token, str):
            raise ValueError("token no valido")
        entorno["CLAUDE_CODE_OAUTH_TOKEN"] = token
    return orden, entorno, min(float(timeout), TIMEOUT_MAXIMO)


class Manejador(BaseHTTPRequestHandler):
    server_version = "agente-runner"

    def log_message(self, formato, *args):   # nunca se registran cuerpos (llevan el prompt y el token)
        sys.stderr.write("%s %s\n" % (self.address_string(), formato % args))

    def _responder(self, codigo, cuerpo):
        datos = json.dumps(cuerpo, ensure_ascii=False).encode("utf-8")
        self.send_response(codigo)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(datos)))
        self.end_headers()
        self.wfile.write(datos)

    def _autorizado(self):
        dado = self.headers.get("Authorization", "")
        if not dado.startswith("Bearer ") or not hmac.compare_digest(dado[7:].encode(), SECRETO.encode()):
            self._responder(401, {"error": "no autorizado"})
            return False
        return True

    def _id(self):
        partes = urlparse(self.path).path.strip("/").split("/")
        return partes[1] if len(partes) in (2, 3) and partes[0] == "ejecuciones" else None

    def _pide_archivos(self):
        return urlparse(self.path).path.strip("/").endswith("/archivos")

    def do_GET(self):
        ruta = urlparse(self.path)
        if ruta.path == "/salud":
            return self._responder(200, {"ok": True})
        if not self._autorizado():
            return
        e = ejecuciones.get(self._id() or "")
        if e is None:
            return self._responder(404, {"error": "no existe esa ejecucion"})
        if self._pide_archivos():
            if e.corriendo:
                return self._responder(409, {"error": "la ejecucion sigue en marcha"})
            return self._responder(200, {"archivos": e.archivos()})
        try:
            desde = max(0, int(parse_qs(ruta.query).get("desde", ["0"])[0]))
        except ValueError:
            desde = 0
        self._responder(200, e.estado(desde))

    def do_POST(self):
        if not self._autorizado():
            return
        if urlparse(self.path).path != "/ejecuciones":
            return self._responder(404, {"error": "ruta desconocida"})
        try:
            largo = int(self.headers.get("Content-Length", "0"))
            if not 0 < largo <= MAX_CUERPO:
                return self._responder(413, {"error": "cuerpo vacio o demasiado grande"})
            datos = json.loads(self.rfile.read(largo))
            orden, entorno, timeout = orden_de(datos)
            archivos = datos.get("archivos")
        except (ValueError, json.JSONDecodeError) as ex:
            return self._responder(400, {"error": str(ex)})
        with cerrojo:
            limpiar()
            if sum(1 for e in ejecuciones.values() if e.corriendo) >= MAX_SIMULTANEAS:
                return self._responder(429, {"error": "demasiadas ejecuciones a la vez"})
            try:
                escribir_entrada(archivos)
                e = Ejecucion(orden, entorno, timeout)
            except OSError as ex:
                return self._responder(500, {"error": "no se pudo lanzar claude: %s" % ex})
            except ValueError as ex:   # p. ej. un byte nulo en el prompt: Popen lo rechaza
                return self._responder(400, {"error": "no se pudo lanzar claude: %s" % ex})
            ejecuciones[e.id] = e
        self._responder(202, {"id": e.id})

    def do_DELETE(self):
        if not self._autorizado():
            return
        e = ejecuciones.get(self._id() or "")
        if e is None:
            return self._responder(404, {"error": "no existe esa ejecucion"})
        e.matar()
        self._responder(200, {"ok": True})


def main():
    if len(SECRETO) < 16:
        sys.exit("AGENTE_SECRETO tiene que tener al menos 16 caracteres.")
    recuperar()
    servidor = ThreadingHTTPServer(("0.0.0.0", PUERTO), Manejador)
    servidor.daemon_threads = True
    print("runner escuchando en :%d (carpeta %s, estado %s)" % (PUERTO, CARPETA, ESTADO), flush=True)
    servidor.serve_forever()


if __name__ == "__main__":
    main()
