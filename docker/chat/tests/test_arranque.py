"""servidor.py como proceso: escucha solo tras /capacidades y no filtra secretos en sus logs."""

import os
import socket
import subprocess
import sys
import time
import unittest

import grpc
from falso import Fallo, LaravelFalso
from soporte import GENERADO, RAIZ, C, parte_texto, pb, pb_grpc

SECRETO_VALOR = "valor-ultra-secreto-9f3a"


def puerto_libre() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def acepta_conexiones(puerto: int) -> bool:
    try:
        with socket.create_connection(("127.0.0.1", puerto), timeout=0.3):
            return True
    except OSError:
        return False


class TestArranque(unittest.TestCase):
    def lanzar(self, falso, puerto, **entorno):
        env = {
            "PATH": os.environ.get("PATH", ""),
            "CHAT_PUERTO": str(puerto),
            "CHAT_URL_INTERNA": falso.url,
            "CHAT_SECRETO": falso.secreto,
            "CHAT_STUBS": GENERADO,
        }
        env.update(entorno)
        proceso = subprocess.Popen(
            [sys.executable, os.path.join(RAIZ, "servidor.py")],
            env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        self.addCleanup(lambda: proceso.poll() is None and proceso.kill())
        return proceso

    def test_escucha_solo_cuando_laravel_responde_y_no_filtra_el_secreto(self):
        falso = LaravelFalso().iniciar()
        self.addCleanup(falso.parar)
        falso.capacidades_listas.clear()
        puerto = puerto_libre()
        proceso = self.lanzar(falso, puerto)
        limite = time.time() + 2.0
        while time.time() < limite:
            self.assertFalse(acepta_conexiones(puerto), "no debe escuchar antes de /capacidades")
            time.sleep(0.2)
        self.assertIsNone(proceso.poll())
        falso.capacidades_listas.set()
        limite = time.time() + 15
        while not acepta_conexiones(puerto):
            self.assertLess(time.time(), limite, "no llego a escuchar")
            time.sleep(0.1)

        canal = grpc.insecure_channel(f"127.0.0.1:{puerto}")
        self.addCleanup(canal.close)
        chat = pb_grpc.ChatServiceStub(canal)
        caps = chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5)
        self.assertEqual(list(caps.accepted_mime_types), ["image/jpeg", "image/png"])

        chat.SendMessage(pb.SendMessageRequest(content=[parte_texto("cuerpo-que-no-debe-loguearse")]), timeout=5)
        chat.SendMessage(pb.SendMessageRequest(content=[parte_texto("!secreto ahora")]), timeout=5)
        deadline = time.time() + 8
        pedido = None
        while time.time() < deadline and pedido is None:
            for m in chat.GetMessages(pb.GetMessagesRequest(), timeout=5):
                if m.from_service and len(m.content) > 1:
                    pedido = m
            time.sleep(0.1)
        self.assertIsNotNone(pedido)
        rid = pedido.content[1].secret_request.request_id
        chat.AnswerSecretRequest(
            pb.AnswerSecretRequestRequest(message_id=pedido.message_id, request_id=rid, value=SECRETO_VALOR), timeout=5
        )
        self.assertEqual(falso.valores_secretos, [SECRETO_VALOR])
        with self.assertRaises(grpc.RpcError):  # error con el valor de por medio
            chat.AnswerSecretRequest(
                pb.AnswerSecretRequestRequest(message_id=pedido.message_id, request_id="zzz", value=SECRETO_VALOR + "\x00"),
                timeout=5,
            )
        def roto(d):
            raise Fallo("service_error", "fallo con " + d["valor"])

        falso.secreto_respuesta = roto  # Laravel falla y repite el valor en su mensaje
        with self.assertRaises(grpc.RpcError) as ctx:
            chat.AnswerSecretRequest(
                pb.AnswerSecretRequestRequest(message_id=pedido.message_id, request_id=rid, value=SECRETO_VALOR),
                timeout=5,
            )
        self.assertEqual(ctx.exception.code(), C.INTERNAL)
        self.assertNotIn(SECRETO_VALOR, ctx.exception.details())

        proceso.terminate()
        salida, errores = proceso.communicate(timeout=15)
        self.assertEqual(proceso.returncode, 0)
        for texto in (salida, errores):
            self.assertNotIn(SECRETO_VALOR, texto)
            self.assertNotIn("cuerpo-que-no-debe-loguearse", texto)
            self.assertNotIn(falso.secreto, texto)  # tampoco la cabecera Authorization
        self.assertIn("escuchando", errores)

    def test_sin_secreto_no_arranca(self):
        falso = LaravelFalso().iniciar()
        self.addCleanup(falso.parar)
        proceso = self.lanzar(falso, puerto_libre(), CHAT_SECRETO="")
        proceso.communicate(timeout=15)
        self.assertEqual(proceso.returncode, 2)
