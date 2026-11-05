"""Utilidades comunes de las pruebas: stubs, servidor de prueba y cliente gRPC."""

from __future__ import annotations

import contextlib
import os
import queue
import subprocess
import sys
import threading
import time
import unittest

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.dirname(AQUI)
GENERADO = os.environ.get("CHAT_STUBS") or os.path.join(RAIZ, "generado")

if not os.path.exists(os.path.join(GENERADO, "chat_pb2.py")):
    subprocess.run([os.path.join(RAIZ, "generar.sh"), GENERADO], check=True)
for ruta in (GENERADO, RAIZ, AQUI):
    if ruta not in sys.path:
        sys.path.insert(0, ruta)

import chat_pb2 as pb  # noqa: E402
import chat_pb2_grpc as pb_grpc  # noqa: E402
import grpc  # noqa: E402
from falso import LaravelFalso  # noqa: E402
from laravel import Laravel  # noqa: E402
from servidor import crear_servidor, leer_capacidades  # noqa: E402

FINALES = (pb.MESSAGE_STATUS_COMPLETED, pb.MESSAGE_STATUS_FAILED, pb.MESSAGE_STATUS_CANCELLED)
C = grpc.StatusCode


def razon_de(error: grpc.RpcError) -> str | None:
    for clave, valor in error.trailing_metadata() or ():
        if clave == "chat-reason":
            return valor
    return None


def parte_texto(texto: str) -> pb.ContentPart:
    return pb.ContentPart(text=pb.TextContent(text=texto))


def texto_de(m: pb.Message) -> str:
    return "".join(p.text.text for p in m.content if p.WhichOneof("kind") == "text")


def referencia(datos: bytes, mime: str = "image/png", nombre: str | None = None, tamano: int | None = None):
    import hashlib

    ref = pb.FileReference(
        hashes=[pb.Hash(type=hashlib.sha3_256(b"").digest(), value=hashlib.sha3_256(datos).digest())],
        mime_type=mime,
        size_bytes=len(datos) if tamano is None else tamano,
    )
    if nombre:
        ref.filename = nombre
    return ref


class FinFlujo:
    def __init__(self, error: grpc.RpcError | None) -> None:
        self.error = error


class Flujo:
    """Un Connect abierto: eventos de salida en una cola."""

    def __init__(self, stub: pb_grpc.ChatServiceStub) -> None:
        self._entrada: queue.Queue = queue.Queue()
        self.salida: queue.Queue = queue.Queue()
        self.llamada = stub.Connect(self._generar())
        threading.Thread(target=self._leer, daemon=True).start()

    def _generar(self):
        while True:
            evento = self._entrada.get()
            if evento is None:
                return
            yield evento

    def _leer(self) -> None:
        try:
            for evento in self.llamada:
                self.salida.put(evento)
            self.salida.put(FinFlujo(None))
        except grpc.RpcError as exc:
            self.salida.put(FinFlujo(exc))

    def enviar(self, evento: pb.ClientEvent) -> None:
        self._entrada.put(evento)

    def suscribir(self, despues: int = 0, id_evento: str | None = None) -> None:
        evento = pb.ClientEvent(subscribe=pb.SubscribeRequest(after_sequence=despues))
        if id_evento is not None:
            evento.event_id = id_evento
        self.enviar(evento)

    def cerrar_cliente(self) -> None:
        self._entrada.put(None)

    def cancelar(self) -> None:
        self.llamada.cancel()

    def siguiente(self, tiempo: float = 5.0):
        return self.salida.get(timeout=tiempo)

    def hasta(self, predicado, tiempo: float = 8.0) -> list:
        """Eventos recibidos hasta el primero que cumpla predicado (incluido)."""
        limite = time.time() + tiempo
        vistos = []
        while True:
            restante = limite - time.time()
            if restante <= 0:
                raise AssertionError("Tiempo agotado esperando un evento. Vistos: %r" % [tipo(v) for v in vistos])
            evento = self.salida.get(timeout=restante)
            vistos.append(evento)
            if predicado(evento):
                return vistos

    def hasta_listo(self) -> list:
        return self.hasta(lambda e: not isinstance(e, FinFlujo) and e.WhichOneof("event") == "subscription_ready")

    def hasta_estado(self, estado: int, id_mensaje: str | None = None) -> list:
        def pred(e):
            if isinstance(e, FinFlujo):
                raise AssertionError("El stream termino antes de lo esperado")
            return (
                e.WhichOneof("event") == "message_status_changed"
                and e.message_status_changed.status == estado
                and (id_mensaje is None or e.message_status_changed.message_id == id_mensaje)
            )

        return self.hasta(pred)

    def silencio(self, tiempo: float = 0.4) -> list:
        """Devuelve los eventos que lleguen en `tiempo` (para comprobar que no hay ninguno)."""
        vistos = []
        limite = time.time() + tiempo
        while time.time() < limite:
            try:
                vistos.append(self.salida.get(timeout=max(0.01, limite - time.time())))
            except queue.Empty:
                break
        return vistos


def es(evento, nombre: str) -> bool:
    return not isinstance(evento, FinFlujo) and evento.WhichOneof("event") == nombre


def tipo(evento) -> str:
    if isinstance(evento, FinFlujo):
        return "fin"
    return evento.WhichOneof("event")


class Base(unittest.TestCase):
    umbral_cola = 5000

    def setUp(self) -> None:
        self.falso = LaravelFalso().iniciar()
        self.addCleanup(self.falso.parar)
        self.laravel = Laravel(self.falso.url, self.falso.secreto)
        self.capacidades = leer_capacidades(self.laravel)
        self.servidor, self.puerto = crear_servidor(
            self.laravel, self.capacidades, 0, host="127.0.0.1", intervalo_sondeo=0.05, umbral_cola=self.umbral_cola
        )
        self.servidor.start()
        self.addCleanup(lambda: self.servidor.stop(0).wait())
        self.canal = grpc.insecure_channel(f"127.0.0.1:{self.puerto}")
        self.addCleanup(self.canal.close)
        self.chat = pb_grpc.ChatServiceStub(self.canal)
        self.files = pb_grpc.FileServiceStub(self.canal)

    # -- ayudas --------------------------------------------------------

    def enviar(self, texto: str = "hola", clave: str | None = None, **campos) -> pb.Message:
        req = pb.SendMessageRequest(content=[parte_texto(texto)], **campos)
        if clave is not None:
            req.idempotency_key = clave
        return self.chat.SendMessage(req, timeout=10)

    def historial(self, **campos) -> list[pb.Message]:
        return list(self.chat.GetMessages(pb.GetMessagesRequest(**campos), timeout=10))

    def esperar(self, condicion, tiempo: float = 8.0):
        limite = time.time() + tiempo
        while time.time() < limite:
            valor = condicion()
            if valor:
                return valor
            time.sleep(0.05)
        raise AssertionError("Condicion no cumplida a tiempo")

    def respuesta_a(self, usuario: pb.Message) -> pb.Message:
        def buscar():
            for m in self.historial(after_sequence=usuario.sequence):
                if m.from_service and m.reply_to_message_id == usuario.message_id and m.status in FINALES:
                    return m
            return None

        return self.esperar(buscar)

    def latest(self) -> int:
        return self.falso.ultima()

    @contextlib.contextmanager
    def error(self, codigo: grpc.StatusCode, razon: str | None):
        with self.assertRaises(grpc.RpcError) as ctx:
            yield
        self.assertEqual(ctx.exception.code(), codigo, ctx.exception.details())
        self.assertEqual(razon_de(ctx.exception), razon)

    def con_capacidades(self, **cambios):
        """Levanta otro servidor con otras capacidades de Laravel. Devuelve (chat, files)."""
        self.falso.capacidades.update(cambios)
        capacidades = leer_capacidades(self.laravel)
        servidor, puerto = crear_servidor(self.laravel, capacidades, 0, host="127.0.0.1", intervalo_sondeo=0.05)
        servidor.start()
        self.addCleanup(lambda: servidor.stop(0).wait())
        canal = grpc.insecure_channel(f"127.0.0.1:{puerto}")
        self.addCleanup(canal.close)
        return pb_grpc.ChatServiceStub(canal), pb_grpc.FileServiceStub(canal)

    def flujo(self) -> Flujo:
        f = Flujo(self.chat)
        self.addCleanup(f.cancelar)
        return f

    def flujo_listo(self, despues: int = 0) -> Flujo:
        f = self.flujo()
        f.suscribir(despues)
        f.hasta_listo()
        return f
