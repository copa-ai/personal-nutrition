"""ChatService y FileService de chat.md (revision 6) como traductor de la API
interna de Laravel (docs/chat-api-interna.md).

El traductor no guarda datos de la conversacion. Solo guarda, por cada stream
Connect, lo que ya envio al cliente (para pedir a /sincronizar lo que falta) y
la lista de capacidades, que lee una vez al arrancar.
"""

from __future__ import annotations

import functools
import hashlib
import logging
import queue
import threading
from dataclasses import dataclass
from typing import Any, Iterator

import chat_pb2 as pb
import chat_pb2_grpc as pb_grpc
import grpc
from conversion import (
    ABIERTOS,
    ESTADO_A_PB,
    ID_EVENTO,
    ahora_ms,
    cuerpo_envio,
    exigir_principal,
    hash_conocido,
    hash_de_referencia,
    hash_pb,
    longitudes,
    mala_peticion,
    mensaje_a_pb,
)
from laravel import ErrorChat, Laravel

log = logging.getLogger("chat.servicios")

MAX_ARCHIVO_DEFECTO = 4 * 1024 * 1024
TROZO_DESCARGA = 64 * 1024
MAX_SECRETO_BYTES = 8192


# -- capacidades ----------------------------------------------------------


@dataclass(frozen=True)
class Capacidades:
    tipos_mime: tuple[str, ...]
    max_archivo_bytes: int
    max_archivos: int
    max_texto_bytes: int
    soporta_cancelar: bool

    @classmethod
    def de_json(cls, d: dict) -> "Capacidades":
        return cls(
            tipos_mime=tuple(str(t).lower() for t in d.get("tipos_mime") or []),
            max_archivo_bytes=int(d.get("max_archivo_bytes") or 0),
            max_archivos=int(d.get("max_archivos") or 0),
            max_texto_bytes=int(d.get("max_texto_bytes") or 0),
            soporta_cancelar=bool(d.get("soporta_cancelar")),
        )

    def a_pb(self) -> pb.Capabilities:
        # supports_fork es siempre false: ForkConversation no se implementa.
        return pb.Capabilities(
            accepted_mime_types=list(self.tipos_mime),
            max_file_bytes=self.max_archivo_bytes,
            max_files_per_message=self.max_archivos,
            max_text_bytes=self.max_texto_bytes,
            supports_fork=False,
            supports_cancel=self.soporta_cancelar,
        )

    @property
    def limite_archivo(self) -> int:
        return self.max_archivo_bytes or MAX_ARCHIVO_DEFECTO


# -- manejo de errores ----------------------------------------------------


def _abortar(context: grpc.ServicerContext, error: ErrorChat) -> None:
    if error.razon:
        context.set_trailing_metadata((("chat-reason", error.razon),))
    context.abort(error.estado, error.mensaje)


def _fallo_interno(nombre: str, exc: Exception) -> ErrorChat:
    # Solo la clase de la excepcion: su texto podria llevar datos de la peticion.
    log.error("%s: fallo inesperado (%s)", nombre, type(exc).__name__)
    return ErrorChat("service_error", "Error interno del servicio.")


def unaria(f):
    @functools.wraps(f)
    def envuelta(self, request, context):
        try:
            return f(self, request, context)
        except ErrorChat as exc:
            fallo = exc
        except Exception as exc:  # noqa: BLE001
            fallo = _fallo_interno(f.__name__, exc)
        _abortar(context, fallo)

    return envuelta


def flujo(f):
    @functools.wraps(f)
    def envuelta(self, request, context):
        try:
            yield from f(self, request, context)
            return
        except ErrorChat as exc:
            fallo = exc
        except grpc.RpcError:
            return  # el cliente cancelo
        except Exception as exc:  # noqa: BLE001
            fallo = _fallo_interno(f.__name__, exc)
        _abortar(context, fallo)

    return envuelta


def _opcional_texto(req: Any, campo: str) -> str | None:
    if req.HasField(campo):
        valor = getattr(req, campo)
        return valor or None
    return None


# -- protobuf que no se puede leer --------------------------------------------
#
# protobuf rechaza una cadena que no es UTF-8 valido al leer el mensaje, y gRPC lo convierte en INTERNAL sin chat-reason. chat.md
# pide bad_request (6.1 paso 1 y 6.9 paso 3), asi que estas dos llamadas leen con tolerancia y dejan que los pasos de siempre
# decidan: SendMessage sin contenido (bad_request) y AnswerSecretRequest con un valor vacio (not_found si la peticion no existe,
# si no bad_request).


def _campos_basicos(datos: bytes) -> dict[int, bytes]:
    """Campos 1 y 2 (cadenas) de un mensaje serializado, sin comprobar UTF-8. Lo que no se entiende se ignora."""
    campos: dict[int, bytes] = {}
    i = 0
    n = len(datos)

    def varint() -> int | None:
        nonlocal i
        valor = 0
        for desplazamiento in range(0, 70, 7):
            if i >= n:
                return None
            byte = datos[i]
            i += 1
            valor |= (byte & 0x7F) << desplazamiento
            if not byte & 0x80:
                return valor
        return None

    while i < n:
        etiqueta = varint()
        if etiqueta is None:
            break
        numero, tipo = etiqueta >> 3, etiqueta & 7
        if tipo == 0:
            if varint() is None:
                break
        elif tipo == 1:
            i += 8
        elif tipo == 5:
            i += 4
        elif tipo == 2:
            largo = varint()
            if largo is None or i + largo > n:
                break
            if numero in (1, 2):
                campos[numero] = datos[i : i + largo]
            i += largo
        else:
            break
    return campos


def leer_envio(datos: bytes) -> pb.SendMessageRequest:
    try:
        return pb.SendMessageRequest.FromString(datos)
    except Exception:  # noqa: BLE001  (DecodeError: texto que no es UTF-8, mensaje cortado...)
        return pb.SendMessageRequest()


def leer_respuesta_secreto(datos: bytes) -> pb.AnswerSecretRequestRequest:
    try:
        return pb.AnswerSecretRequestRequest.FromString(datos)
    except Exception:  # noqa: BLE001
        campos = _campos_basicos(datos)
        req = pb.AnswerSecretRequestRequest(value="")
        req.message_id = campos.get(1, b"").decode("utf-8", errors="replace")
        req.request_id = campos.get(2, b"").decode("utf-8", errors="replace")
        return req


LECTORES = {
    "/ChatService/SendMessage": leer_envio,
    "/ChatService/AnswerSecretRequest": leer_respuesta_secreto,
}


class LectorTolerante(grpc.ServerInterceptor):
    def intercept_service(self, continuation, handler_call_details):
        manejador = continuation(handler_call_details)
        lector = LECTORES.get(handler_call_details.method)
        if manejador is None or lector is None:
            return manejador
        return manejador._replace(request_deserializer=lector)


# -- Connect --------------------------------------------------------------


class Fin:
    """Marca el final del stream: error=None es un cierre limpio (OK)."""

    def __init__(self, error: ErrorChat | None = None) -> None:
        self.error = error


def _error_protocolo(codigo: str, mensaje: str, id_evento: str | None) -> pb.ServerEvent:
    error = pb.ProtocolError(code=codigo, message=mensaje)
    if id_evento:
        error.event_id = id_evento
    return pb.ServerEvent(error=error)


class FlujoConnect:
    """Estado de un stream Connect: lo ya enviado y la cola de eventos de salida."""

    def __init__(self, laravel: Laravel, capacidades: Capacidades, despues: int) -> None:
        self.laravel = laravel
        self.capacidades = capacidades
        self.cola: queue.Queue = queue.Queue()
        self.cerrojo = threading.RLock()
        self.despues = despues
        self.abiertos: dict[str, dict] = {}
        self.titulo: str | None = None
        self.ultimo_titulo_ms = 0
        self.ids_evento: set[str] = set()
        self.omitir: set[str] = set()
        self.ack = 0
        self.parar = threading.Event()

    def poner(self, evento: Any) -> None:
        self.cola.put(evento)

    # Un ciclo de /sincronizar. Debe llamarse con el cerrojo tomado.
    def ciclo(self, repeticion: bool = False) -> tuple[set[str], int]:
        abiertos = [
            {
                "id": id_mensaje,
                "texto": e["texto"],
                "razonamiento": e["razonamiento"],
                "estado": e["estado"],
                "fijado": e["fijado"],
            }
            for id_mensaje, e in self.abiertos.items()
        ]
        resp = self.laravel.sincronizar(self.despues, abiertos, self.titulo)
        emitidos: set[str] = set()
        nuevos = 0

        for d in resp.get("nuevos") or []:
            secuencia = int(d["sequence"])
            if secuencia <= self.despues:
                continue
            self.despues = secuencia
            nuevos += 1
            if d["message_id"] in self.omitir:
                self.omitir.discard(d["message_id"])
            else:
                self.poner(pb.ServerEvent(message_created=pb.MessageCreated(message=mensaje_a_pb(d))))
                emitidos.add(d["message_id"])
            if d.get("status") in ABIERTOS:
                texto, razonamiento = longitudes(d)
                self.abiertos[d["message_id"]] = {
                    "texto": texto,
                    "razonamiento": razonamiento,
                    "estado": d["status"],
                    "fijado": bool(d.get("pinned")),
                }

        for dl in resp.get("deltas") or []:
            e = self.abiertos.get(dl["id"])
            if e is None:
                continue
            texto = dl.get("texto_anadido") or ""
            razonamiento = dl.get("razonamiento_anadido") or ""
            if not texto and not razonamiento:
                continue
            e["texto"] += len(texto.encode("utf-8"))
            e["razonamiento"] += len(razonamiento.encode("utf-8"))
            self.poner(
                pb.ServerEvent(
                    message_delta=pb.MessageDelta(
                        message_id=dl["id"], text_append=texto, reasoning_append=razonamiento
                    )
                )
            )

        finales: list[str] = []
        for est in resp.get("estados") or []:
            e = self.abiertos.get(est["id"])
            if e is None:
                continue
            nombre = est["estado"]
            cambio = pb.MessageStatusChanged(
                message_id=est["id"],
                status=ESTADO_A_PB.get(nombre, pb.MESSAGE_STATUS_UNSPECIFIED),
                updated_at=int(est.get("actualizado_ms") or ahora_ms()),
            )
            if nombre == "FAILED" and est.get("error"):
                cambio.error = est["error"]
            if nombre == "COMPLETED":
                cambio.suggestions.extend(est.get("sugerencias") or [])
            self.poner(pb.ServerEvent(message_status_changed=cambio))
            if nombre in ABIERTOS:
                e["estado"] = nombre
            else:
                finales.append(est["id"])

        for fj in resp.get("fijados") or []:
            e = self.abiertos.get(fj["id"])
            if e is None or e["fijado"] == bool(fj["fijado"]):
                continue
            e["fijado"] = bool(fj["fijado"])
            self.poner(
                pb.ServerEvent(
                    message_pinned_changed=pb.MessagePinnedChanged(
                        message_id=fj["id"],
                        pinned=e["fijado"],
                        updated_at=int(fj.get("actualizado_ms") or ahora_ms()),
                    )
                )
            )

        for id_mensaje in finales:
            self.abiertos.pop(id_mensaje, None)

        titulo = resp.get("titulo") or {}
        if titulo.get("cambio"):
            self.titulo = titulo.get("valor") or None
            if not repeticion:
                # La hora del cambio la da Laravel (la misma para todos los streams, chat.md 6.10 y prueba 86); sin ella, la local.
                self.ultimo_titulo_ms = max(
                    self.ultimo_titulo_ms, int(titulo.get("actualizado_ms") or 0) or ahora_ms()
                )
                cambiado = pb.ConversationTitleChanged(updated_at=self.ultimo_titulo_ms)
                if self.titulo:
                    cambiado.title = self.titulo
                self.poner(pb.ServerEvent(conversation_title_changed=cambiado))
        return emitidos, nuevos

    def reproducir(self) -> None:
        """Replay: ciclos hasta que ya no haya nada nuevo. El titulo no genera evento."""
        with self.cerrojo:
            for _ in range(10_000):
                _, nuevos = self.ciclo(repeticion=True)
                if nuevos == 0:
                    return
            raise ErrorChat("service_error", "El historial no termina de reproducirse.")

    def aplicar_fijado(self, id_mensaje: str, secuencia: int, fijado: bool, actualizado_ms: int) -> None:
        with self.cerrojo:
            if secuencia > self.despues:
                return  # aun no se lo hemos enviado: llegara ya fijado en su MessageCreated
            e = self.abiertos.get(id_mensaje)
            if e is not None:
                if e["fijado"] == fijado:
                    return
                e["fijado"] = fijado
            self.poner(
                pb.ServerEvent(
                    message_pinned_changed=pb.MessagePinnedChanged(
                        message_id=id_mensaje, pinned=fijado, updated_at=actualizado_ms
                    )
                )
            )

    # -- hilos ---------------------------------------------------------

    def sondear(self, intervalo: float, umbral_cola: int) -> None:
        while not self.parar.wait(intervalo):
            try:
                with self.cerrojo:
                    self.ciclo()
                if self.cola.qsize() > umbral_cola:
                    self.poner(Fin(ErrorChat("busy", "El cliente no lee sus eventos.")))
                    return
            except ErrorChat as exc:
                self.poner(Fin(exc))
                return
            except Exception as exc:  # noqa: BLE001
                self.poner(Fin(_fallo_interno("sondeo", exc)))
                return

    def leer(self, eventos: Iterator[pb.ClientEvent]) -> None:
        try:
            for evento in eventos:
                self.procesar(evento)
        except ErrorChat as exc:
            self.poner(Fin(exc))
            return
        except grpc.RpcError:
            self.parar.set()
            return
        except Exception as exc:  # noqa: BLE001
            self.poner(Fin(_fallo_interno("lectura", exc)))
            return
        self.poner(Fin(None))

    def procesar(self, evento: pb.ClientEvent) -> None:
        bruto = evento.event_id if evento.HasField("event_id") else ""
        id_evento: str | None = None
        if bruto != "":
            if not ID_EVENTO.match(bruto) or bruto in self.ids_evento:
                # Un event_id malo no se copia: el cliente no sabria a cual se refiere.
                self.poner(_error_protocolo("bad_request", "event_id no valido o repetido.", None))
                return
            self.ids_evento.add(bruto)
            id_evento = bruto
        tipo = evento.WhichOneof("event")
        try:
            if tipo == "subscribe":
                raise ErrorChat("bad_state", "Ya hay una suscripcion en este stream.")
            if tipo == "send_message":
                self._enviar(evento.send_message)
            elif tipo == "cancel_message":
                self._cancelar(evento.cancel_message)
            elif tipo == "acknowledge":
                self._confirmar(evento.acknowledge.last_sequence)
            # Un evento sin tipo es de una revision nueva: se ignora.
        except ErrorChat as exc:
            if exc.razon is None:
                raise  # instancia no disponible: el stream termina
            self.poner(_error_protocolo(exc.razon, exc.mensaje, id_evento))
        except grpc.RpcError:
            raise
        except Exception as exc:  # noqa: BLE001
            error = _fallo_interno("evento", exc)
            self.poner(_error_protocolo(error.razon or "service_error", error.mensaje, id_evento))

    def _enviar(self, req: pb.SendMessageRequest) -> None:
        cuerpo = cuerpo_envio(req)
        with self.cerrojo:
            d = self.laravel.enviar(cuerpo)
            emitidos, _ = self.ciclo()
            if d["message_id"] not in emitidos:
                # Envio repetido (misma idempotency_key): ya estaba entregado, se reenvia.
                if int(d["sequence"]) > self.despues:
                    self.omitir.add(d["message_id"])
                self.poner(pb.ServerEvent(message_created=pb.MessageCreated(message=mensaje_a_pb(d))))

    def _cancelar(self, req: pb.CancelMessageRequest) -> None:
        if not self.capacidades.soporta_cancelar:
            raise ErrorChat("unimplemented", "Este servicio no permite cancelar.")
        if req.HasField("conversation_id"):
            exigir_principal(req.conversation_id)
        if not req.message_id:
            raise ErrorChat("not_found", "El mensaje no existe.")
        with self.cerrojo:
            self.laravel.cancelar(req.message_id)
            self.ciclo()

    def _confirmar(self, ultima: int) -> None:
        if ultima > int(self.laravel.conversacion()["ultima_secuencia"]):
            raise mala_peticion("La secuencia es mayor que la ultima del servicio.")
        if ultima > self.ack:
            self.ack = ultima


class Registro:
    """Streams Connect abiertos, para avisar de un cambio de fijado hecho por SetMessagePinned."""

    def __init__(self) -> None:
        self._cerrojo = threading.Lock()
        self._flujos: set[FlujoConnect] = set()

    def alta(self, f: FlujoConnect) -> None:
        with self._cerrojo:
            self._flujos.add(f)

    def baja(self, f: FlujoConnect) -> None:
        with self._cerrojo:
            self._flujos.discard(f)

    def fijado(self, id_mensaje: str, secuencia: int, fijado: bool, actualizado_ms: int) -> None:
        with self._cerrojo:
            flujos = list(self._flujos)
        for f in flujos:
            f.aplicar_fijado(id_mensaje, secuencia, fijado, actualizado_ms)


# -- ChatService ----------------------------------------------------------


class ChatService(pb_grpc.ChatServiceServicer):
    def __init__(
        self,
        laravel: Laravel,
        capacidades: Capacidades,
        intervalo_sondeo: float = 0.3,
        umbral_cola: int = 5000,
    ) -> None:
        self.laravel = laravel
        self.capacidades = capacidades
        self.intervalo_sondeo = intervalo_sondeo
        self.umbral_cola = umbral_cola
        self.registro = Registro()

    @unaria
    def GetCapabilities(self, request, context):
        return self.capacidades.a_pb()

    @unaria
    def SendMessage(self, request, context):
        cuerpo = cuerpo_envio(request)
        return mensaje_a_pb(self.laravel.enviar(cuerpo))

    @flujo
    def GetMessages(self, request, context):
        if request.HasField("conversation_id"):
            exigir_principal(request.conversation_id)
        if request.limit > 0 and request.last > 0:
            raise mala_peticion("limit y last no se pueden combinar.")
        mensajes = self.laravel.mensajes(
            request.after_sequence, request.before_sequence, request.limit, request.last
        )
        for m in mensajes:
            yield mensaje_a_pb(m)

    @unaria
    def GetConversation(self, request, context):
        if request.HasField("conversation_id"):
            exigir_principal(request.conversation_id)
        d = self.laravel.conversacion()
        conversacion = pb.Conversation(conversation_id="", created_at=int(d.get("creada_ms") or 0), archived=False)
        if d.get("titulo"):
            conversacion.title = d["titulo"]
        return conversacion

    @unaria
    def ForkConversation(self, request, context):
        raise ErrorChat("unimplemented", "Este servicio no tiene ramas.")

    @unaria
    def SetMessagePinned(self, request, context):
        if request.HasField("conversation_id"):
            exigir_principal(request.conversation_id)
        if not request.message_id:
            raise ErrorChat("not_found", "El mensaje no existe.")
        antes = self.laravel.mensaje(request.message_id)
        despues = self.laravel.fijar(request.message_id, request.pinned)
        if bool(antes.get("pinned")) != bool(despues.get("pinned")):
            self.registro.fijado(
                despues["message_id"],
                int(despues["sequence"]),
                bool(despues["pinned"]),
                int(despues.get("updated_ms") or ahora_ms()),
            )
        return mensaje_a_pb(despues)

    @unaria
    def AnswerSecretRequest(self, request, context):
        # Ni el valor ni la peticion se registran ni entran en una excepcion.
        eleccion = request.WhichOneof("answer")
        valor: str | None = None
        omitido = False
        fallo: ErrorChat | None = None
        if eleccion is None or (eleccion == "skipped" and not request.skipped):
            fallo = mala_peticion("Hay que dar un valor o omitir la peticion.")
        elif eleccion == "value":
            texto = request.value
            if texto == "" or "\x00" in texto:
                fallo = mala_peticion("El valor no es valido.")
            elif len(texto.encode("utf-8")) > MAX_SECRETO_BYTES:
                fallo = ErrorChat("too_large", "El valor es demasiado largo.")
            else:
                valor = texto
        else:
            omitido = True
        if fallo is not None:
            # not_found (paso 1) va antes que los errores de forma (pasos 2 a 4).
            self._comprobar_peticion(request.message_id, request.request_id)
            raise fallo
        try:
            self.laravel.responder_secreto(request.message_id, request.request_id, valor, omitido)
        except ErrorChat as exc:
            if valor and valor in exc.mensaje:
                raise ErrorChat(exc.razon, "No se pudo guardar el valor.") from None
            raise
        return pb.AnswerSecretRequestResponse()

    def _comprobar_peticion(self, id_mensaje: str, id_peticion: str) -> None:
        if not id_mensaje or not id_peticion:
            raise ErrorChat("not_found", "La peticion no existe.")
        mensaje = self.laravel.mensaje(id_mensaje)
        for parte in mensaje.get("content") or []:
            if parte.get("tipo") == "peticion_secreto" and parte.get("request_id") == id_peticion:
                return
        raise ErrorChat("not_found", "La peticion no existe.")

    @flujo
    def Connect(self, request_iterator, context):
        eventos = iter(request_iterator)
        try:
            primero = next(eventos)
        except StopIteration:
            return
        bruto = primero.event_id if primero.HasField("event_id") else ""
        if primero.WhichOneof("event") != "subscribe":
            raise ErrorChat("bad_state", "El primer evento debe ser subscribe.")
        if bruto != "" and not ID_EVENTO.match(bruto):
            raise mala_peticion("event_id no valido.")
        suscripcion = primero.subscribe
        if suscripcion.HasField("conversation_id"):
            exigir_principal(suscripcion.conversation_id)
        if suscripcion.after_sequence > int(self.laravel.conversacion()["ultima_secuencia"]):
            raise mala_peticion("after_sequence es mayor que la ultima secuencia.")

        f = FlujoConnect(self.laravel, self.capacidades, suscripcion.after_sequence)
        if bruto != "":
            f.ids_evento.add(bruto)
        context.add_callback(f.parar.set)
        f.reproducir()
        listo = pb.SubscriptionReady(latest_sequence=f.despues)
        if f.titulo:
            listo.title = f.titulo
        f.poner(pb.ServerEvent(subscription_ready=listo))

        self.registro.alta(f)
        hilos = [
            threading.Thread(target=f.leer, args=(eventos,), daemon=True, name="chat-leer"),
            threading.Thread(
                target=f.sondear, args=(self.intervalo_sondeo, self.umbral_cola), daemon=True, name="chat-sondeo"
            ),
        ]
        for h in hilos:
            h.start()
        try:
            while True:
                try:
                    item = f.cola.get(timeout=0.5)
                except queue.Empty:
                    if f.parar.is_set() or not context.is_active():
                        return
                    continue
                if isinstance(item, Fin):
                    if item.error is not None:
                        raise item.error
                    return
                yield item
        finally:
            f.parar.set()
            self.registro.baja(f)


# -- FileService ----------------------------------------------------------


def _mime_normalizado(mime: str) -> str:
    return mime.split(";", 1)[0].strip().lower()


class FileService(pb_grpc.FileServiceServicer):
    def __init__(self, laravel: Laravel, capacidades: Capacidades) -> None:
        self.laravel = laravel
        self.capacidades = capacidades

    @flujo
    def QueryFiles(self, request_iterator, context):
        for req in request_iterator:
            hex_hash = hash_conocido(req.hash)
            disponible = False if hex_hash is None else self.laravel.consultar_archivos([hex_hash])[0]
            yield pb.QueryFilesResponse(hash=pb.Hash(type=req.hash.type, value=req.hash.value), available=disponible)

    @unaria
    def UploadFile(self, request_iterator, context):
        eventos = iter(request_iterator)
        try:
            primero = next(eventos)
        except StopIteration:
            raise mala_peticion("Falta el primer mensaje con el archivo.") from None
        # Paso 1: estructura.
        if primero.WhichOneof("part") != "file":
            raise mala_peticion("El primer mensaje debe llevar file.")
        ref = primero.file
        hex_hash = hash_de_referencia(ref)
        mime = _mime_normalizado(ref.mime_type) if ref.HasField("mime_type") else ""
        tamano = ref.size_bytes if ref.HasField("size_bytes") else 0
        if mime == "" or tamano < 1:
            raise mala_peticion("Faltan mime_type o size_bytes.")
        # Paso 2: tamano, antes de leer trozos. Paso 3: tipo.
        if tamano > self.capacidades.limite_archivo:
            raise ErrorChat("too_large", "El archivo es demasiado grande.")
        if mime not in self.capacidades.tipos_mime:
            raise ErrorChat("unsupported_media", "Tipo de archivo no admitido.")
        datos = bytearray()
        for msg in eventos:
            if msg.WhichOneof("part") == "file":
                raise mala_peticion("Solo el primer mensaje puede llevar file.")
            datos += msg.chunk
            if len(datos) > tamano:
                raise ErrorChat("hash_mismatch", "El archivo es mas grande de lo declarado.")
        # Paso 5 (el 4, almacen lleno, lo decide Laravel al guardar).
        if len(datos) != tamano:
            raise ErrorChat("hash_mismatch", "El tamano no coincide con el declarado.")
        if hashlib.sha3_256(datos).hexdigest() != hex_hash:
            raise ErrorChat("hash_mismatch", "El hash no coincide con el contenido.")
        nombre = ref.filename if ref.HasField("filename") and ref.filename else None
        resp = self.laravel.subir_archivo(hex_hash, bytes(datos), mime, tamano, nombre)
        confirmada = pb.FileReference(
            hashes=[hash_pb(resp.get("hash") or hex_hash)],
            mime_type=resp.get("mime") or mime,
            size_bytes=int(resp.get("tamano") or tamano),
        )
        if resp.get("nombre"):
            confirmada.filename = resp["nombre"]
        return pb.UploadFileResponse(file=confirmada)

    @flujo
    def DownloadFile(self, request, context):
        hex_hash = hash_conocido(request.hash)
        if hex_hash is None:
            raise ErrorChat("not_found", "El archivo no esta disponible.")
        datos = self.laravel.descargar_archivo(hex_hash)
        for i in range(0, len(datos), TROZO_DESCARGA):
            yield pb.DownloadFileResponse(chunk=datos[i : i + TROZO_DESCARGA])
