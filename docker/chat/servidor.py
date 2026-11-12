#!/usr/bin/env python3
"""Traductor gRPC del chat de Celaut (chat.md, revision 6) a la API interna de Laravel.

No guarda datos: habla por HTTP con Laravel (docs/chat-api-interna.md). Variables de entorno:
  CHAT_PUERTO       puerto gRPC (por defecto 50051)
  CARDS_PUERTO      puerto gRPC del slot «cards» de la lista de la compra (por defecto 50052; cartas.py)
  CHAT_URL_INTERNA  base de Laravel (por defecto http://127.0.0.1:8001)
  CHAT_SECRETO      secreto Bearer compartido con Laravel
  CHAT_STUBS        directorio de los stubs (por defecto ./generado; ver generar.sh)

Escucha solo cuando /capacidades ya respondio: hasta entonces el cliente ve "no conecta".
"""

from __future__ import annotations

import logging
import os
import signal
import sys
import threading
import time
from concurrent import futures

AQUI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.environ.get("CHAT_STUBS") or os.path.join(AQUI, "generado"))
sys.path.insert(0, AQUI)

import cards_pb2_grpc as cards_grpc  # noqa: E402
import chat_pb2_grpc as pb_grpc  # noqa: E402
import grpc  # noqa: E402
from laravel import ErrorChat, Laravel  # noqa: E402
from cartas import CardsService, LaravelCards  # noqa: E402
from cartas import FileService as FileServiceCards  # noqa: E402
from servicios import Capacidades, ChatService, FileService, LectorTolerante  # noqa: E402

log = logging.getLogger("chat")

MIB = 1024 * 1024
CINCO_MIN_MS = 5 * 60 * 1000

OPCIONES = [
    ("grpc.max_receive_message_length", 4 * MIB),
    ("grpc.max_send_message_length", 1 * MIB),
    ("grpc.max_concurrent_streams", 100),
    ("grpc.max_connection_idle_ms", CINCO_MIN_MS),
    # El cliente no manda PING mas de una vez cada 5 minutos: se aceptan a ese ritmo.
    ("grpc.http2.min_ping_interval_without_data_ms", CINCO_MIN_MS),
    ("grpc.http2.max_ping_strikes", 0),
    ("grpc.keepalive_permit_without_calls", 1),
]


def leer_capacidades(laravel: Laravel, parar: threading.Event | None = None) -> Capacidades:
    """Lee /capacidades con reintentos. Se guarda para siempre."""
    espera = 0.5
    while parar is None or not parar.is_set():
        try:
            return Capacidades.de_json(laravel.capacidades())
        except ErrorChat as exc:
            log.info("Laravel aun no responde a /capacidades (%s)", exc.razon or "no disponible")
        except Exception as exc:  # noqa: BLE001
            log.warning("/capacidades: respuesta inesperada (%s)", type(exc).__name__)
        if parar is not None:
            parar.wait(espera)
        else:
            time.sleep(espera)
        espera = min(espera * 1.5, 5.0)
    raise KeyboardInterrupt


def crear_servidor(
    laravel: Laravel,
    capacidades: Capacidades,
    puerto: int,
    host: str = "[::]",
    intervalo_sondeo: float = 0.3,
    umbral_cola: int = 5000,
) -> tuple[grpc.Server, int]:
    servidor = grpc.server(
        futures.ThreadPoolExecutor(max_workers=64, thread_name_prefix="grpc"),
        options=OPCIONES,
        interceptors=[LectorTolerante()],
    )
    pb_grpc.add_ChatServiceServicer_to_server(
        ChatService(laravel, capacidades, intervalo_sondeo, umbral_cola), servidor
    )
    pb_grpc.add_FileServiceServicer_to_server(FileService(laravel, capacidades), servidor)
    try:
        enlazado = servidor.add_insecure_port(f"{host}:{puerto}")
    except RuntimeError:
        enlazado = 0
    if enlazado == 0 and host == "[::]":
        enlazado = servidor.add_insecure_port(f"0.0.0.0:{puerto}")
    if enlazado == 0:
        raise RuntimeError(f"No se pudo escuchar en el puerto {puerto}")
    return servidor, enlazado


def crear_servidor_cards(
    laravel: LaravelCards, puerto: int, host: str = "[::]", intervalo_sondeo: float = 1.0
) -> tuple[grpc.Server, int]:
    """Servidor gRPC del slot «cards» (cards.md): CardsService y FileService.DownloadFile."""
    servidor = grpc.server(
        futures.ThreadPoolExecutor(max_workers=32, thread_name_prefix="cards"),
        options=OPCIONES,
    )
    cards_grpc.add_CardsServiceServicer_to_server(CardsService(laravel, intervalo_sondeo), servidor)
    pb_grpc.add_FileServiceServicer_to_server(FileServiceCards(laravel), servidor)
    try:
        enlazado = servidor.add_insecure_port(f"{host}:{puerto}")
    except RuntimeError:
        enlazado = 0
    if enlazado == 0 and host == "[::]":
        enlazado = servidor.add_insecure_port(f"0.0.0.0:{puerto}")
    if enlazado == 0:
        raise RuntimeError(f"No se pudo escuchar en el puerto {puerto}")
    return servidor, enlazado


def main() -> int:
    logging.basicConfig(
        stream=sys.stderr, level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s"
    )
    puerto = int(os.environ.get("CHAT_PUERTO") or 50051)
    url = os.environ.get("CHAT_URL_INTERNA") or "http://127.0.0.1:8001"
    secreto = os.environ.get("CHAT_SECRETO") or ""
    if secreto == "":
        log.error("Falta CHAT_SECRETO")
        return 2

    parar = threading.Event()
    for senal in (signal.SIGINT, signal.SIGTERM):
        signal.signal(senal, lambda *_: parar.set())

    laravel = Laravel(url, secreto)
    try:
        capacidades = leer_capacidades(laravel, parar)
    except KeyboardInterrupt:
        return 0
    servidor, enlazado = crear_servidor(laravel, capacidades, puerto)
    servidor.start()
    log.info("Chat gRPC escuchando en el puerto %d", enlazado)
    puerto_cards = int(os.environ.get("CARDS_PUERTO") or 50052)
    servidor_cards, enlazado_cards = crear_servidor_cards(LaravelCards(url, secreto), puerto_cards)
    servidor_cards.start()
    log.info("Cards gRPC escuchando en el puerto %d", enlazado_cards)
    parar.wait()
    servidor_cards.stop(grace=5)
    servidor.stop(grace=5).wait()
    return 0


if __name__ == "__main__":
    sys.exit(main())
