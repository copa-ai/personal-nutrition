#!/usr/bin/env python3
"""Cliente gRPC de prueba extremo a extremo: recorre el flujo de un cliente real (chatui) contra el sistema REAL.

NO es un unittest (los de esta carpeta usan un Laravel falso): necesita levantado todo lo de verdad.

  - PostgreSQL con la base migrada y con los datos de HogarSeeder (hogar «Casa», perfiles Marta y Javier, despensa) y SIN token de Claude
    ni peticiones de secreto previas (base limpia: el recorrido pide el token y lo contesta).
  - Laravel sirviendo el prefijo interno en 127.0.0.1:8001 (php -S 127.0.0.1:8001 -t public) y un worker de cola (php artisan queue:work),
    los dos con QUEUE_CONNECTION=database, CHAT_SECRETO, AGENTE_URL y AGENTE_ALMACEN=base.
  - El runner del agente (docker/agente/runner.py) con AGENTE_CLAUDE=docker/pruebas/claude-falso.py sobre una COPIA de agente/.
  - El traductor (docker/chat/servidor.py).

Variables de entorno (valores por defecto entre parentesis):
  E2E_DIRECCION        gRPC del traductor (127.0.0.1:50051)
  E2E_PSQL             orden que abre psql en la base, partida por espacios (docker exec -i nutrition-pg-prueba psql -U nutrition -d pg_e2e)
  E2E_PGDUMP           orden que vuelca la base entera (docker exec nutrition-pg-prueba pg_dump -U nutrition pg_e2e)
  E2E_AGENTE_CARPETA   carpeta agente/ que usa el runner (para cambiar modo-falso); sin ella se omiten las pruebas que lo necesitan
  E2E_BUSCAR_SECRETO   archivos o carpetas, separados por «:», donde NO puede aparecer el secreto (logs, estado del runner, storage...)
  E2E_CONTENEDORES     contenedores de docker cuyos logs tambien se revisan, separados por «:» (por defecto ninguno)
Sale con 0 si todo pasa, 1 si algo falla (y dice que).
"""

from __future__ import annotations

import hashlib
import json
import os
import queue
import re
import shlex
import struct
import subprocess
import sys
import threading
import time
import traceback
import zlib

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.dirname(AQUI)
GENERADO = os.environ.get("CHAT_STUBS") or os.path.join(RAIZ, "generado")
if not os.path.exists(os.path.join(GENERADO, "chat_pb2.py")):
    subprocess.run([os.path.join(RAIZ, "generar.sh"), GENERADO], check=True)
sys.path.insert(0, GENERADO)

import chat_pb2 as pb  # noqa: E402
import chat_pb2_grpc as pb_grpc  # noqa: E402
import grpc  # noqa: E402

DIRECCION = os.environ.get("E2E_DIRECCION", "127.0.0.1:50051")
PSQL = shlex.split(os.environ.get("E2E_PSQL", "docker exec -i nutrition-pg-prueba psql -U nutrition -d pg_e2e"))
PGDUMP = shlex.split(os.environ.get("E2E_PGDUMP", "docker exec nutrition-pg-prueba pg_dump -U nutrition pg_e2e"))
CARPETA_AGENTE = os.environ.get("E2E_AGENTE_CARPETA", "")
BUSCAR = [r for r in os.environ.get("E2E_BUSCAR_SECRETO", "").split(":") if r]
CONTENEDORES = [c for c in os.environ.get("E2E_CONTENEDORES", "").split(":") if c]

# El valor de prueba del token de Claude: lo bastante raro para que buscarlo no de falsos positivos.
SECRETO = "sk-ant-oat01-E2E" + hashlib.sha3_256(b"e2e").hexdigest()[:40]
ESTADOS = {
    pb.MESSAGE_STATUS_PENDING: "PENDING",
    pb.MESSAGE_STATUS_IN_PROGRESS: "IN_PROGRESS",
    pb.MESSAGE_STATUS_COMPLETED: "COMPLETED",
    pb.MESSAGE_STATUS_FAILED: "FAILED",
    pb.MESSAGE_STATUS_CANCELLED: "CANCELLED",
}
FINALES = ("COMPLETED", "FAILED", "CANCELLED")


# ---------------------------------------------------------------- utilidades


def sql(consulta: str) -> str:
    """Ejecuta una consulta y devuelve la salida sin cabeceras (-At)."""
    r = subprocess.run([*PSQL, "-At", "-c", consulta], capture_output=True, text=True, check=False)
    if r.returncode != 0:
        raise RuntimeError(f"psql: {r.stderr.strip()}")
    return r.stdout.strip()


def comprobar(condicion: object, mensaje: str) -> None:
    if not condicion:
        raise AssertionError(mensaje)


def esperar(predicado, tiempo: float = 60.0, descripcion: str = "la condicion"):
    limite = time.time() + tiempo
    while time.time() < limite:
        valor = predicado()
        if valor:
            return valor
        time.sleep(0.15)
    raise AssertionError(f"Tiempo agotado esperando {descripcion}")


def parte_texto(texto: str) -> pb.ContentPart:
    return pb.ContentPart(text=pb.TextContent(text=texto))


def texto_de(m: pb.Message) -> str:
    return "".join(p.text.text for p in m.content if p.WhichOneof("kind") == "text")


def hash_de(datos: bytes) -> pb.Hash:
    return pb.Hash(type=hashlib.sha3_256(b"").digest(), value=hashlib.sha3_256(datos).digest())


def png(ancho: int = 16, alto: int = 16, color: tuple[int, int, int] = (200, 80, 40)) -> bytes:
    """Un PNG valido (RGB) hecho a mano, sin Pillow."""

    def trozo(tipo: bytes, datos: bytes) -> bytes:
        return struct.pack(">I", len(datos)) + tipo + datos + struct.pack(">I", zlib.crc32(tipo + datos) & 0xFFFFFFFF)

    fila = b"\x00" + bytes(color) * ancho
    return (
        b"\x89PNG\r\n\x1a\n"
        + trozo(b"IHDR", struct.pack(">IIBBBBB", ancho, alto, 8, 2, 0, 0, 0))
        + trozo(b"IDAT", zlib.compress(fila * alto))
        + trozo(b"IEND", b"")
    )


def bloques(texto: str, tipo: str) -> list[str]:
    return re.findall(r"```" + tipo + r"\n(.*?)\n```", texto, re.S)


def pulsar_tecla(teclado: str, etiqueta: str, valores: dict[str, str] | None = None) -> str:
    """Lo que hace un cliente al pulsar una tecla: toma su data-say y sustituye los {campo} por lo que hay en los campos."""
    for m in re.finditer(r"<button([^>]*)>(.*?)</button>", teclado, re.S):
        if m.group(2).strip() != etiqueta:
            continue
        di = re.search(r'data-say="([^"]*)"', m.group(1))
        comprobar(di, f"la tecla «{etiqueta}» no tiene data-say")
        texto = di.group(1).replace("&quot;", '"').replace("&amp;", "&").replace("&lt;", "<").replace("&gt;", ">")
        return re.sub(r"\{(\w+)\}", lambda c: (valores or {}).get(c.group(1), ""), texto)
    raise AssertionError(f"No hay tecla «{etiqueta}» en el teclado")


def campos_de(teclado: str) -> dict[str, dict[str, object]]:
    """Campos del formulario: nombre -> {tipo, valor, opciones}."""
    campos: dict[str, dict[str, object]] = {}
    for m in re.finditer(r"<select([^>]*)>(.*?)</select>", teclado, re.S):
        nombre = re.search(r'name="([^"]+)"', m.group(1))
        if nombre:
            opciones = re.findall(r"<option([^>]*)>(.*?)</option>", m.group(2), re.S)
            campos[nombre.group(1)] = {
                "tipo": "select",
                "opciones": [re.sub(r"<[^>]+>", "", t) for _, t in opciones],
                "elegidas": [re.sub(r"<[^>]+>", "", t) for a, t in opciones if "selected" in a],
            }
    for m in re.finditer(r"<input([^>]*)>", teclado):
        nombre = re.search(r'name="([^"]+)"', m.group(1))
        if nombre:
            valor = re.search(r'value="([^"]*)"', m.group(1))
            campos[nombre.group(1)] = {"tipo": "input", "valor": valor.group(1) if valor else ""}
    return campos


# ---------------------------------------------------------------- cliente


class Seguimiento:
    """Lo que un cliente sabe de la conversacion, reconstruido SOLO con los eventos de Connect (mensajes, deltas y estados)."""

    def __init__(self) -> None:
        self.mensajes: dict[str, dict] = {}
        self.orden: list[str] = []
        self.eventos: list[pb.ServerEvent] = []
        self.listo: pb.SubscriptionReady | None = None
        self.errores: list[pb.ProtocolError] = []
        self.terminado: grpc.RpcError | None = None
        self.cond = threading.Condition()

    def aplicar(self, ev: pb.ServerEvent) -> None:
        with self.cond:
            self.eventos.append(ev)
            tipo = ev.WhichOneof("event")
            if tipo == "subscription_ready":
                self.listo = ev.subscription_ready
            elif tipo == "error":
                self.errores.append(ev.error)
            elif tipo == "message_created":
                m = ev.message_created.message
                if m.message_id not in self.mensajes:
                    self.orden.append(m.message_id)
                self.mensajes[m.message_id] = {
                    "seq": m.sequence, "estado": ESTADOS[m.status], "texto": texto_de(m), "razon": m.reasoning,
                    "servicio": m.from_service, "reply_to": m.reply_to_message_id if m.HasField("reply_to_message_id") else None,
                    "partes": [p.WhichOneof("kind") for p in m.content], "sugerencias": list(m.suggestions),
                    "deltas_texto": 0, "deltas_razon": 0, "msg": m, "error": m.error if m.HasField("error") else None,
                }
            elif tipo == "message_delta":
                d = ev.message_delta
                m = self.mensajes[d.message_id]
                m["texto"] += d.text_append
                m["razon"] += d.reasoning_append
                m["deltas_texto"] += 1 if d.text_append else 0
                m["deltas_razon"] += 1 if d.reasoning_append else 0
            elif tipo == "message_status_changed":
                s = ev.message_status_changed
                m = self.mensajes[s.message_id]
                m["estado"] = ESTADOS[s.status]
                m["sugerencias"] = list(s.suggestions)
                m["error"] = s.error if s.HasField("error") else None
            self.cond.notify_all()

    def esperar(self, predicado, tiempo: float = 60.0, descripcion: str = "un evento"):
        limite = time.time() + tiempo
        with self.cond:
            while True:
                valor = predicado()
                if valor:
                    return valor
                resto = limite - time.time()
                if resto <= 0:
                    raise AssertionError(f"Tiempo agotado esperando {descripcion}")
                self.cond.wait(min(resto, 0.5))


class Conexion:
    """Un Connect abierto: suscripcion y lectura de eventos en un hilo."""

    def __init__(self, stub: pb_grpc.ChatServiceStub, despues: int = 0) -> None:
        self.salida: queue.Queue = queue.Queue()
        self.seguimiento = Seguimiento()
        self.salida.put(pb.ClientEvent(subscribe=pb.SubscribeRequest(after_sequence=despues)))
        self.llamada = stub.Connect(self._peticiones())
        self.hilo = threading.Thread(target=self._leer, daemon=True)
        self.hilo.start()

    def _peticiones(self):
        while True:
            ev = self.salida.get()
            if ev is None:
                return
            yield ev

    def _leer(self) -> None:
        try:
            for ev in self.llamada:
                self.seguimiento.aplicar(ev)
        except grpc.RpcError as exc:
            with self.seguimiento.cond:
                self.seguimiento.terminado = exc
                self.seguimiento.cond.notify_all()

    def enviar(self, evento: pb.ClientEvent) -> None:
        self.salida.put(evento)

    def cerrar(self) -> None:
        self.salida.put(None)
        self.llamada.cancel()


class Contexto:
    def __init__(self) -> None:
        self.canal = grpc.insecure_channel(DIRECCION)
        self.chat = pb_grpc.ChatServiceStub(self.canal)
        self.archivos = pb_grpc.FileServiceStub(self.canal)
        self.conexion: Conexion | None = None
        self.ultimo_usuario = 0
        self.foto: bytes | None = None
        self.mensaje_secreto: str | None = None

    @property
    def seg(self) -> Seguimiento:
        assert self.conexion is not None
        return self.conexion.seguimiento

    def decir(self, texto: str, imagenes: list[pb.ContentPart] | None = None, clave: str | None = None) -> pb.Message:
        """SendMessage como un cliente. Devuelve el mensaje de la persona."""
        peticion = pb.SendMessageRequest(content=[parte_texto(texto)] if texto else [])
        peticion.content.extend(imagenes or [])
        if clave:
            peticion.idempotency_key = clave
        m = self.chat.SendMessage(peticion, timeout=30)
        self.ultimo_usuario = m.sequence
        return m

    def respuesta_a(self, usuario: pb.Message, tiempo: float = 90.0) -> dict:
        """Espera (por el flujo de Connect) la respuesta del servicio a ese mensaje, ya final, y la devuelve."""

        def final():
            for id_ in self.seg.orden:
                m = self.seg.mensajes[id_]
                if m["servicio"] and m["reply_to"] == usuario.message_id and m["estado"] in FINALES:
                    return m
            return None

        return self.seg.esperar(final, tiempo, f"la respuesta a «{texto_de(usuario)[:40]}»")

    def preguntar(self, texto: str, imagenes: list[pb.ContentPart] | None = None) -> dict:
        return self.respuesta_a(self.decir(texto, imagenes))


# ---------------------------------------------------------------- los pasos

PASOS: list[tuple[str, object]] = []


def paso(nombre: str):
    def registrar(f):
        PASOS.append((nombre, f))
        return f

    return registrar


@paso("GetCapabilities")
def p_capacidades(c: Contexto) -> None:
    cap = c.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=10)
    comprobar(set(cap.accepted_mime_types) == {"image/jpeg", "image/png"}, f"mime {list(cap.accepted_mime_types)}")
    comprobar(cap.max_file_bytes == 4194304 and cap.max_files_per_message == 4 and cap.max_text_bytes == 16384, "limites")
    comprobar(cap.supports_cancel and not cap.supports_fork, "cancelar si, ramas no")


@paso("Connect y subscribe (SubscriptionReady, sin mensajes previos)")
def p_connect(c: Contexto) -> None:
    c.conexion = Conexion(c.chat, 0)
    listo = c.seg.esperar(lambda: c.seg.listo, 15, "SubscriptionReady")
    comprobar(listo.latest_sequence == 0, f"la base no esta limpia: latest_sequence={listo.latest_sequence}")


@paso("Texto «hola»: el bot responde con un teclado (bloque keyboard) y sugerencias")
def p_hola(c: Contexto) -> None:
    usuario = c.decir("hola")
    comprobar(usuario.status == pb.MESSAGE_STATUS_COMPLETED and not usuario.from_service, "el mensaje de la persona debe estar COMPLETED")
    r = c.respuesta_a(usuario)
    comprobar(r["estado"] == "COMPLETED", f"estado {r['estado']} {r['error']}")
    comprobar("```keyboard" in r["texto"], "el saludo debe llevar un teclado")
    comprobar(bloques(r["texto"], "keyboard"), "bloque keyboard cerrado")
    comprobar(r["seq"] == usuario.sequence + 1, "la respuesta es el mensaje siguiente")
    comprobar("Casa" in r["texto"], "el bot conoce el hogar")


@paso("Despensa: la respuesta lleva un widget (bloque html)")
def p_widget(c: Contexto) -> None:
    r = c.preguntar("qué hay en la despensa")
    comprobar(r["estado"] == "COMPLETED", r["estado"])
    comprobar(bloques(r["texto"], "html"), f"sin bloque html: {r['texto'][:200]!r}")
    comprobar("arroz" in r["texto"].lower(), "el widget lista la despensa")


@paso("Teclado: «Registrar lo que he comido» -> formulario -> la tecla envia el comando -> se registra la comida")
def p_registro_por_teclado(c: Contexto) -> None:
    saludo = next(m for m in c.seg.mensajes.values() if m["servicio"] and "```keyboard" in m["texto"])
    orden = pulsar_tecla(bloques(saludo["texto"], "keyboard")[0], "Registrar lo que he comido")
    r = c.preguntar(orden)
    teclado = bloques(r["texto"], "keyboard")
    comprobar(teclado, f"el bot debe devolver el formulario: {r['texto'][:200]!r}")
    campos = campos_de(teclado[0])
    comprobar({"perfil", "momento", "fecha", "descripcion"} <= set(campos), f"campos {list(campos)}")
    hoy = str(campos["fecha"]["valor"])
    comando = pulsar_tecla(teclado[0], "Registrar", {"perfil": "Marta", "momento": "Comida", "fecha": hoy, "descripcion": "ensalada de garbanzos e2e"})
    r2 = c.preguntar(comando)
    comprobar(r2["estado"] == "COMPLETED", r2["estado"])
    fila = esperar(lambda: sql("select r.perfil_id||'|'||r.momento||'|'||r.origen||'|'||r.descripcion from registros_comida r where descripcion like '%garbanzos e2e%'"), 5, "la fila en registros_comida")
    comprobar("|comida|" in fila, fila)
    perfil = sql("select p.nombre from perfiles p join registros_comida r on r.perfil_id=p.id where r.descripcion like '%garbanzos e2e%'")
    comprobar(perfil == "Marta", f"perfil {perfil}")


@paso("Foto: QueryFiles (no esta) -> UploadFile -> QueryFiles (esta) -> SendMessage con ImageContent")
def p_foto(c: Contexto) -> None:
    datos = png()
    c.foto = datos
    h = hash_de(datos)

    def consultar() -> bool:
        respuestas = list(c.archivos.QueryFiles(iter([pb.QueryFilesRequest(hash=h)]), timeout=15))
        comprobar(len(respuestas) == 1 and respuestas[0].hash == h, "QueryFiles devuelve una respuesta por hash")
        return respuestas[0].available

    comprobar(not consultar(), "el archivo aun no deberia estar")
    ref = pb.FileReference(hashes=[h], mime_type="image/png", size_bytes=len(datos), filename="plato-e2e.png")
    trozos = [pb.UploadFileRequest(file=ref)] + [pb.UploadFileRequest(chunk=datos[i : i + 100]) for i in range(0, len(datos), 100)]
    subida = c.archivos.UploadFile(iter(trozos), timeout=30)
    comprobar(subida.file.size_bytes == len(datos) and subida.file.mime_type == "image/png", f"UploadFile -> {subida}")
    comprobar(consultar(), "QueryFiles deberia decir que esta")

    # En Postgres, en chat_archivos y byte a byte.
    hex_ = hashlib.sha3_256(datos).hexdigest()
    comprobar(sql(f"select tamano||'|'||mime||'|'||encode(contenido,'hex') from chat_archivos where hash='{hex_}'") == f"{len(datos)}|image/png|{datos.hex()}", "chat_archivos")

    # La foto va sin texto: el bot pide los datos y la retoma cuando llegan.
    imagen = pb.ContentPart(image=pb.ImageContent(file=pb.FileReference(hashes=[h], mime_type="image/png", size_bytes=len(datos), filename="plato-e2e.png"), alt_text="mi plato"))
    r = c.preguntar("", [imagen])
    comprobar(r["estado"] == "COMPLETED", f"{r['estado']} {r['error']}")
    comprobar(bloques(r["texto"], "keyboard"), f"sin formulario: {r['texto'][:200]!r}")
    c.mensaje_foto = r  # type: ignore[attr-defined]
    teclado = bloques(r["texto"], "keyboard")[0]
    hoy = str(campos_de(teclado)["fecha"]["valor"])
    r2 = c.preguntar(pulsar_tecla(teclado, "Registrar", {"perfil": "Javier", "momento": "Cena", "fecha": hoy, "descripcion": "pasta con tomate e2e"}))
    comprobar(r2["estado"] == "COMPLETED", r2["estado"])
    enlace = esperar(lambda: sql("select foto_hash from registros_comida where descripcion like '%pasta con tomate e2e%'"), 5, "el registro con foto")
    comprobar(enlace == hex_, f"foto_hash {enlace!r} != {hex_!r}: la foto debe quedar enlazada al registro")
    comprobar(sql(f"select count(*) from chat_archivos where hash='{hex_}'") == "1", "una sola fila de archivo")


@paso("DownloadFile: los bytes y el hash coinciden")
def p_descarga(c: Contexto) -> None:
    datos = c.foto
    comprobar(datos is not None, "depende del paso de la foto")
    trozos = list(c.archivos.DownloadFile(pb.DownloadFileRequest(hash=hash_de(datos)), timeout=15))
    recibido = b"".join(t.chunk for t in trozos)
    comprobar(hashlib.sha3_256(recibido).digest() == hashlib.sha3_256(datos).digest(), "el hash de lo descargado no coincide")
    comprobar(recibido == datos, "los bytes no coinciden")
    try:
        list(c.archivos.DownloadFile(pb.DownloadFileRequest(hash=hash_de(b"no existe")), timeout=10))
        raise AssertionError("descargar un archivo inexistente debia fallar")
    except grpc.RpcError as exc:
        comprobar(exc.code() == grpc.StatusCode.NOT_FOUND, f"codigo {exc.code()}")


@paso("Menu: pregunta por varios perfiles (el bot pregunta de quien o responde por todos)")
def p_menu(c: Contexto) -> None:
    r = c.preguntar("qué ceno hoy")
    comprobar(r["estado"] == "COMPLETED", r["estado"])
    comprobar(r["texto"].strip() != "", "respuesta vacia")
    teclado = bloques(r["texto"], "keyboard")
    comprobar(teclado, f"con dos perfiles y sin decir de quien, el bot debe preguntar con un formulario: {r['texto'][:200]!r}")
    orden = pulsar_tecla(teclado[0], "Ver el menú", {"perfil": "Todos"})
    r2 = c.preguntar(orden)
    comprobar(r2["estado"] == "COMPLETED" and bloques(r2["texto"], "html"), f"el menu llega en un widget: {r2['texto'][:200]!r}")
    comprobar(re.search(r"Tortilla|Pescado", r2["texto"]), "el widget trae las opciones de la cena del menu activo")
    r3 = c.preguntar("qué tiene que comer Marta y qué debo comer yo mañana")
    comprobar(r3["estado"] == "COMPLETED" and r3["texto"].strip() != "", "pregunta por varios")


@paso("Sin token: una pregunta de IA -> SecretRequest del token de Claude (parte secret_request, mensaje abierto)")
def p_pide_secreto(c: Contexto) -> None:
    usuario = c.decir("analiza mi alimentación de esta semana con inteligencia artificial y dame recomendaciones")

    def peticion():
        for id_ in c.seg.orden:
            m = c.seg.mensajes[id_]
            if m["servicio"] and m["reply_to"] == usuario.message_id and "secret_request" in m["partes"]:
                return m
        return None

    m = c.seg.esperar(peticion, 60, "la peticion de secreto")
    parte = next(p for p in m["msg"].content if p.WhichOneof("kind") == "secret_request")
    comprobar(parte.secret_request.name == "CLAUDE_CODE_OAUTH_TOKEN", parte.secret_request.name)
    comprobar(parte.secret_request.request_id != "", "request_id")
    c.mensaje_secreto = m["msg"].message_id
    c.peticion = parte.secret_request  # type: ignore[attr-defined]
    c.estado_peticion = m["estado"]  # type: ignore[attr-defined]


@paso("AnswerSecretRequest: se guarda cifrado, y la pregunta se vuelve a responder en modo Claude (deltas + razonamiento)")
def p_responde_secreto(c: Contexto) -> None:
    comprobar(c.mensaje_secreto, "depende del paso anterior")
    antes = len(c.seg.orden)
    c.chat.AnswerSecretRequest(
        pb.AnswerSecretRequestRequest(message_id=c.mensaje_secreto, request_id=c.peticion.request_id, value=SECRETO), timeout=20  # type: ignore[attr-defined]
    )
    comprobar(sql("select estado from chat_peticiones_secreto order by id desc limit 1") == "respondida", "la peticion queda respondida")

    def nueva():
        for id_ in c.seg.orden[antes:]:
            m = c.seg.mensajes[id_]
            if m["servicio"] and m["reply_to"] == c.mensaje_secreto:
                return m
        return None

    m = c.seg.esperar(nueva, 30, "la nueva respuesta tras el secreto")
    m = c.seg.esperar(lambda: m if m["estado"] in FINALES else None, 120, "que termine la respuesta de Claude")
    comprobar(m["estado"] == "COMPLETED", f"{m['estado']}: {m['error']}")
    comprobar("Leo la instantánea" in m["razon"] or "instantánea" in m["razon"], f"razonamiento: {m['razon'][:200]!r}")
    comprobar("La persona pregunta" in m["razon"], "el thinking del Claude falso va a reasoning")
    comprobar(m["deltas_razon"] >= 1, "el razonamiento llego en deltas (streaming)")
    comprobar(m["deltas_texto"] >= 1 or m["texto"] != "", "el texto llego (por deltas o completo)")
    comprobar("perfiles" in m["texto"] and "```keyboard" in m["texto"], f"texto de Claude con teclado: {m['texto'][:200]!r}")
    comprobar(SECRETO not in m["texto"] + m["razon"], "el secreto en la respuesta")
    c.respuesta_claude = m  # type: ignore[attr-defined]


@paso("Modo Claude: una accion registrar_comida propuesta se valida y se aplica (y la foto se enlaza)")
def p_accion(c: Contexto) -> None:
    antes = int(sql("select count(*) from registros_comida where descripcion like 'Lentejas con arroz%'"))
    r = c.preguntar("registra lo que he comido hoy")
    comprobar(r["estado"] == "COMPLETED", f"{r['estado']} {r['error']}")
    comprobar("Lentejas con arroz" in r["texto"] or "registr" in r["texto"].lower(), f"el resumen de lo aplicado: {r['texto'][-300:]!r}")
    despues = int(sql("select count(*) from registros_comida where descripcion like 'Lentejas con arroz%'"))
    comprobar(despues == antes + 1, f"la accion debia crear un registro ({antes} -> {despues})")
    comprobar(sql("select origen from registros_comida where descripcion like 'Lentejas con arroz%' order by id desc limit 1") != "", "origen")

    # Con foto: el Claude falso la menciona en la accion y Laravel la enlaza.
    datos = png(24, 24, (20, 160, 60))
    h = hash_de(datos)
    ref = pb.FileReference(hashes=[h], mime_type="image/png", size_bytes=len(datos), filename="otra.png")
    c.archivos.UploadFile(iter([pb.UploadFileRequest(file=ref), pb.UploadFileRequest(chunk=datos)]), timeout=20)
    imagen = pb.ContentPart(image=pb.ImageContent(file=ref))
    r2 = c.preguntar("registra esta foto", [imagen])
    comprobar(r2["estado"] == "COMPLETED", f"{r2['estado']} {r2['error']}")
    hex_ = hashlib.sha3_256(datos).hexdigest()
    comprobar(sql(f"select count(*) from registros_comida where foto_hash='{hex_}'") == "1", "la foto debe quedar enlazada por la accion de Claude")


@paso("Accion invalida: se rechaza con un mensaje y no se aplica")
def p_accion_invalida(c: Contexto) -> None:
    comprobar(CARPETA_AGENTE, "falta E2E_AGENTE_CARPETA")
    modo = os.path.join(CARPETA_AGENTE, "modo-falso")
    open(modo, "w").write("invalida")
    try:
        antes = int(sql("select count(*) from registros_comida"))
        r = c.preguntar("registra la cena")
        comprobar(r["estado"] == "COMPLETED", f"{r['estado']} {r['error']}")
        despues = int(sql("select count(*) from registros_comida"))
        comprobar(despues == antes + 1, f"solo la valida debia aplicarse ({antes} -> {despues})")
        comprobar(sql("select count(*) from registros_comida where perfil_id=999999") == "0", "la invalida no se aplica")
        comprobar(re.search(r"no se (han )?(pudo|ha podido|aplicad)|rechaz", r["texto"], re.I), f"debe decir que se rechazo algo: {r['texto'][-300:]!r}")
    finally:
        open(modo, "w").write("ok")


@paso("Cancelacion: CancelMessage en mitad de una respuesta de Claude -> CANCELLED y el agente se detiene")
def p_cancelar(c: Contexto) -> None:
    comprobar(CARPETA_AGENTE, "falta E2E_AGENTE_CARPETA")
    modo = os.path.join(CARPETA_AGENTE, "modo-falso")
    open(modo, "w").write("lento")
    try:
        usuario = c.decir("cuéntame qué debería cenar con calma")

        def abierta():
            for id_ in c.seg.orden:
                m = c.seg.mensajes[id_]
                if m["servicio"] and m["reply_to"] == usuario.message_id:
                    return m if m["estado"] == "IN_PROGRESS" and m["razon"] else None
            return None

        m = c.seg.esperar(abierta, 60, "la respuesta en curso con razonamiento")
        mensaje_id = next(i for i, x in c.seg.mensajes.items() if x is m)
        c.conexion.enviar(pb.ClientEvent(cancel_message=pb.CancelMessageRequest(message_id=mensaje_id)))  # type: ignore[union-attr]
        c.seg.esperar(lambda: m["estado"] == "CANCELLED", 30, "CANCELLED")
        # El agente debe morir: sin proceso del Claude falso en unos segundos.
        esperar(lambda: subprocess.run(["pgrep", "-f", "claude-falso.py"], capture_output=True).returncode != 0, 30, "que el Claude falso termine")
        texto_final = m["texto"]
        time.sleep(3)
        comprobar(m["estado"] == "CANCELLED" and m["texto"] == texto_final, "tras cancelar no debe llegar mas texto ni cambiar el estado")
        try:
            c.chat.SetMessagePinned(pb.SetMessagePinnedRequest(message_id=mensaje_id, pinned=True), timeout=10)   # sigue existiendo
        except grpc.RpcError as exc:
            raise AssertionError(f"el mensaje cancelado debe seguir existiendo: {exc.code()}") from exc
    finally:
        open(modo, "w").write("ok")


@paso("Fijar un mensaje: llega MessagePinnedChanged al flujo")
def p_fijar(c: Contexto) -> None:
    id_ = c.seg.orden[0]
    c.chat.SetMessagePinned(pb.SetMessagePinnedRequest(message_id=id_, pinned=True), timeout=10)
    c.seg.esperar(lambda: any(e.WhichOneof("event") == "message_pinned_changed" and e.message_pinned_changed.message_id == id_ for e in c.seg.eventos), 15, "MessagePinnedChanged")


@paso("Titulo de la conversacion: se fija con el primer mensaje")
def p_titulo(c: Contexto) -> None:
    conv = c.chat.GetConversation(pb.GetConversationRequest(), timeout=10)
    comprobar(conv.title == "hola", f"titulo {conv.title!r}")


@paso("Reconexion con replay: otro Connect desde la secuencia N recibe lo posterior, igual que lo que vio el primero")
def p_replay(c: Contexto) -> None:
    ultima = max(m["seq"] for m in c.seg.mensajes.values())
    corte = 6
    segunda = Conexion(c.chat, corte)
    try:
        listo = segunda.seguimiento.esperar(lambda: segunda.seguimiento.listo, 15, "SubscriptionReady del replay")
        comprobar(listo.latest_sequence == ultima, f"latest_sequence {listo.latest_sequence} != {ultima}")
        esperados = [i for i in c.seg.orden if c.seg.mensajes[i]["seq"] > corte]
        segunda.seguimiento.esperar(lambda: len(segunda.seguimiento.orden) >= len(esperados), 30, "los mensajes del replay")
        comprobar(segunda.seguimiento.orden == esperados, "el replay trae los mismos mensajes y en el mismo orden")
        for i in esperados:
            a, b = c.seg.mensajes[i], segunda.seguimiento.mensajes[i]
            comprobar(a["estado"] == b["estado"] and a["texto"] == b["texto"] and a["razon"] == b["razon"], f"mensaje {a['seq']} distinto tras el replay")
        # Y GetMessages coincide con lo que se vio en vivo.
        todos = list(c.chat.GetMessages(pb.GetMessagesRequest(), timeout=15))
        comprobar([m.sequence for m in todos] == list(range(1, ultima + 1)), "secuencias consecutivas 1..N")
        for m in todos:
            vivo = c.seg.mensajes[m.message_id]
            comprobar(texto_de(m) == vivo["texto"] and m.reasoning == vivo["razon"], f"GetMessages difiere del vivo en {m.sequence}")
    finally:
        segunda.cerrar()


@paso("Idempotencia: la misma idempotency_key no duplica el mensaje")
def p_idempotencia(c: Contexto) -> None:
    a = c.decir("ayuda", clave="e2e-clave-1")
    b = c.decir("ayuda", clave="e2e-clave-1")
    comprobar(a.message_id == b.message_id and a.sequence == b.sequence, "mismo mensaje")
    c.respuesta_a(a)


@paso("Interruptor chat_habilitado apagado -> UNAVAILABLE (sin chat-reason); encendido -> vuelve")
def p_interruptor(c: Contexto) -> None:
    sql("insert into ajustes (clave, valor, created_at, updated_at) values ('chat_habilitado', 'false'::jsonb, now(), now()) on conflict (clave) do update set valor='false'::jsonb")
    try:
        for nombre, llamada in (
            ("SendMessage", lambda: c.chat.SendMessage(pb.SendMessageRequest(content=[parte_texto("hola")]), timeout=10)),
            ("GetMessages", lambda: list(c.chat.GetMessages(pb.GetMessagesRequest(), timeout=10))),
            ("QueryFiles", lambda: list(c.archivos.QueryFiles(iter([pb.QueryFilesRequest(hash=hash_de(b"x"))]), timeout=10))),
        ):
            try:
                llamada()
                raise AssertionError(f"{nombre} debia fallar con el chat apagado")
            except grpc.RpcError as exc:
                comprobar(exc.code() == grpc.StatusCode.UNAVAILABLE, f"{nombre}: {exc.code()}")
                razones = [v for k, v in (exc.trailing_metadata() or ()) if k == "chat-reason"]
                comprobar(not razones, f"{nombre}: sin chat-reason, llego {razones}")
        # Un Connect nuevo tampoco se sostiene.
        nueva = Conexion(c.chat, 0)
        try:
            nueva.seguimiento.esperar(lambda: nueva.seguimiento.terminado is not None, 15, "que Connect termine")
            comprobar(nueva.seguimiento.terminado.code() == grpc.StatusCode.UNAVAILABLE, f"Connect: {nueva.seguimiento.terminado.code()}")  # type: ignore[union-attr]
        finally:
            nueva.cerrar()
    finally:
        sql("update ajustes set valor='true'::jsonb where clave='chat_habilitado'")
    esperar(lambda: _intenta(lambda: c.chat.GetConversation(pb.GetConversationRequest(), timeout=10)), 15, "que el chat vuelva")


def _intenta(f) -> bool:
    try:
        f()
        return True
    except grpc.RpcError:
        return False


@paso("Secretos: el valor del token no aparece en ninguna tabla, log, cola, mensaje ni en lo que ve el cliente")
def p_sin_secreto(c: Contexto) -> None:
    volcado = subprocess.run(PGDUMP, capture_output=True, check=True).stdout
    comprobar(volcado.count(b"INSERT") + volcado.count(b"COPY ") > 0, "el volcado de la base parece vacio")
    comprobar(SECRETO.encode() not in volcado, "el secreto aparece en el volcado de la base de datos")
    comprobar(SECRETO[10:].encode() not in volcado, "un trozo del secreto aparece en la base de datos")
    comprobar(sql("select count(*) from jobs") is not None, "tabla jobs")
    encontrados: list[str] = []

    def mirar(ruta: str) -> None:
        if os.path.isdir(ruta):
            for raiz, _, nombres in os.walk(ruta):
                for n in nombres:
                    mirar(os.path.join(raiz, n))
            return
        try:
            with open(ruta, "rb") as f:
                if SECRETO.encode() in f.read():
                    encontrados.append(ruta)
        except OSError:
            pass

    for ruta in BUSCAR:
        mirar(ruta)
    comprobar(not encontrados, f"el secreto aparece en: {encontrados}")
    for contenedor in CONTENEDORES:
        r = subprocess.run(["docker", "logs", contenedor], capture_output=True, check=False)
        comprobar(SECRETO.encode() not in r.stdout + r.stderr, f"el secreto aparece en los logs de {contenedor}")
    todo = json.dumps([{k: v for k, v in m.items() if k != "msg"} for m in c.seg.mensajes.values()], ensure_ascii=False)
    comprobar(SECRETO not in todo, "el secreto llego al cliente")
    # El token si debe estar guardado (cifrado) para que Claude funcione: lo prueba el paso del modo Claude.
    comprobar(not c.seg.errores, f"ProtocolError en el flujo: {[e.code for e in c.seg.errores]}")


def main() -> int:
    c = Contexto()
    fallos: list[str] = []
    for i, (nombre, f) in enumerate(PASOS, 1):
        t0 = time.time()
        try:
            f(c)  # type: ignore[operator]
            print(f"  OK   {i:2d}. {nombre} ({time.time() - t0:.1f}s)", flush=True)
        except Exception as exc:  # noqa: BLE001
            fallos.append(nombre)
            print(f"  FALLO {i:2d}. {nombre}: {type(exc).__name__}: {exc}", flush=True)
            if not isinstance(exc, AssertionError):
                traceback.print_exc()
    if c.conexion:
        c.conexion.cerrar()
    print(f"\n{len(PASOS) - len(fallos)}/{len(PASOS)} pasos bien", flush=True)
    return 1 if fallos else 0


if __name__ == "__main__":
    sys.exit(main())
