"""AnswerSecretRequest (chat.md 5.6 y 6.9): pruebas 75 a 82 y que el valor no se filtre."""

import logging

import grpc
from soporte import C, Base, pb, razon_de, texto_de, tipo

VALOR = "test-value-0123456789"


class CapturaLogs(logging.Handler):
    def __init__(self):
        super().__init__(logging.DEBUG)
        self.lineas = []

    def emit(self, record):
        self.lineas.append(record.getMessage())
        if record.exc_info:
            self.lineas.append(repr(record.exc_info[1]))


class TestSecretos(Base):
    def setUp(self):
        super().setUp()
        self.logs = CapturaLogs()
        raiz = logging.getLogger()
        nivel = raiz.level
        raiz.addHandler(self.logs)
        raiz.setLevel(logging.DEBUG)
        self.addCleanup(lambda: (raiz.removeHandler(self.logs), raiz.setLevel(nivel)))

    def pedir(self):
        usuario = self.enviar("!secreto dame")
        asistente = self.esperar(lambda: [m for m in self.historial() if m.from_service] or None)[0]
        return usuario, asistente

    def responder(self, asistente, valor=None, omitir=None, request_id=None):
        req = pb.AnswerSecretRequestRequest(
            message_id=asistente.message_id, request_id=request_id or asistente.content[1].secret_request.request_id
        )
        if valor is not None:
            req.value = valor
        if omitir is not None:
            req.skipped = omitir
        return self.chat.AnswerSecretRequest(req, timeout=10)

    def test_75_peticion_en_mensaje_completado(self):
        _, asistente = self.pedir()
        self.assertEqual(asistente.status, pb.MESSAGE_STATUS_COMPLETED)
        peticion = asistente.content[1].secret_request
        self.assertEqual(peticion.name, "TEST_SECRET")
        self.assertTrue(peticion.request_id)
        self.assertEqual(peticion.prose, "Hace falta para probar.")
        self.assertEqual(texto_de(asistente), "Necesito un secreto.")

    def test_76_77_79_valor_continua_el_trabajo_y_no_se_filtra(self):
        _, asistente = self.pedir()
        self.assertEqual(self.responder(asistente, VALOR), pb.AnswerSecretRequestResponse())
        respuesta = self.esperar(
            lambda: [m for m in self.historial() if m.reply_to_message_id == asistente.message_id] or None
        )[0]
        self.assertEqual(texto_de(respuesta), "Gracias.")
        self.assertEqual(self.falso.valores_secretos, [VALOR])
        # 79: segunda respuesta, OK y sin cambios
        self.responder(asistente, "other-value")
        self.assertEqual(self.falso.valores_secretos, [VALOR])
        # 77: ni en la historia, ni en el replay de Connect, ni en los logs
        f = self.flujo()
        f.suscribir(0)
        vistos = f.hasta_listo()
        salida = "".join(str(v) for v in vistos) + "".join(str(m) for m in self.historial())
        self.assertNotIn(VALOR, salida)
        self.assertNotIn("other-value", salida)
        self.assertNotIn(VALOR, "\n".join(self.logs.lineas))
        self.assertNotIn("other-value", "\n".join(self.logs.lineas))

    def test_78_omitir(self):
        _, asistente = self.pedir()
        self.responder(asistente, omitir=True)
        respuesta = self.esperar(
            lambda: [m for m in self.historial() if m.reply_to_message_id == asistente.message_id] or None
        )[0]
        self.assertEqual(texto_de(respuesta), "Omitido.")
        self.assertEqual(self.falso.valores_secretos, [])

    def test_80_peticiones_no_validas(self):
        _, asistente = self.pedir()
        rid = asistente.content[1].secret_request.request_id
        with self.error(C.NOT_FOUND, "not_found"):
            self.responder(asistente, VALOR, request_id="desconocida")
        with self.error(C.NOT_FOUND, "not_found"):
            self.chat.AnswerSecretRequest(
                pb.AnswerSecretRequestRequest(message_id="no-existe", request_id=rid, value=VALOR), timeout=5
            )
        with self.error(C.INVALID_ARGUMENT, "bad_request"):  # sin respuesta
            self.chat.AnswerSecretRequest(
                pb.AnswerSecretRequestRequest(message_id=asistente.message_id, request_id=rid), timeout=5
            )
        for malo in ("", "con\x00nul"):
            with self.subTest(valor=malo), self.error(C.INVALID_ARGUMENT, "bad_request"):
                self.responder(asistente, malo)
        with self.error(C.INVALID_ARGUMENT, "bad_request"):  # skipped = false no es omitir
            self.responder(asistente, omitir=False)
        # not_found va antes que los errores de forma
        with self.error(C.NOT_FOUND, "not_found"):
            self.responder(asistente, "", request_id="otra")
        self.assertEqual(self.falso.valores_secretos, [])
        self.assertEqual(self.falso.secretos, {})

    def test_81_valor_de_8193_bytes(self):
        _, asistente = self.pedir()
        with self.error(C.INVALID_ARGUMENT, "too_large"):
            self.responder(asistente, "a" * 8193)
        self.responder(asistente, "é" * 4096)  # 8192 bytes justos
        self.assertEqual(len(self.falso.valores_secretos), 1)

    def test_80_valor_que_no_es_utf8_es_bad_request(self):
        """6.9 paso 3: bytes que no son UTF-8 no deben acabar en INTERNAL, y el not_found va antes."""
        _, asistente = self.pedir()
        llamar = self.canal.unary_unary("/ChatService/AnswerSecretRequest")

        def cuerpo(message_id, request_id):
            def campo(n, datos):
                return bytes([n << 3 | 2, len(datos)]) + datos

            return campo(1, message_id.encode()) + campo(2, request_id.encode()) + campo(3, b"ab\xff\xfecd")

        peticion = asistente.content[1].secret_request.request_id
        with self.assertRaises(grpc.RpcError) as ctx:
            llamar(cuerpo(asistente.message_id, peticion), timeout=5)
        self.assertEqual(ctx.exception.code(), C.INVALID_ARGUMENT)
        self.assertEqual(razon_de(ctx.exception), "bad_request")
        with self.assertRaises(grpc.RpcError) as ctx:
            llamar(cuerpo(asistente.message_id, "no-existe"), timeout=5)
        self.assertEqual(ctx.exception.code(), C.NOT_FOUND)
        self.assertEqual(razon_de(ctx.exception), "not_found")
        self.assertEqual(self.falso.valores_secretos, [])
        # y una respuesta valida sigue funcionando
        self.responder(asistente, VALOR)

    def test_82_con_una_respuesta_en_curso(self):
        _, asistente = self.pedir()
        self.enviar("!lento otra")
        self.responder(asistente, VALOR)
        self.falso.liberar()

    def test_error_de_laravel_que_repite_el_valor_no_sale_al_cliente(self):
        _, asistente = self.pedir()
        original = self.falso.secreto_respuesta

        def mal(d):
            from falso import Fallo

            raise Fallo("bad_request", "valor rechazado: " + d["valor"])

        self.falso.secreto_respuesta = mal
        with self.assertRaises(grpc.RpcError) as ctx:
            self.responder(asistente, VALOR)
        self.assertNotIn(VALOR, ctx.exception.details())
        self.assertNotIn(VALOR, "\n".join(self.logs.lineas))
        self.falso.secreto_respuesta = original

    def test_secreto_de_laravel_incorrecto_es_error_interno(self):
        self.laravel._secreto = "otro"
        with self.error(C.INTERNAL, "service_error"):
            self.enviar("x")
