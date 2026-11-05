"""Conformidad de chat.md 11.1 que depende del traductor: llamadas unarias y GetMessages.

Los numeros de prueba entre corchetes son los de la tabla de chat.md, seccion 11.1.
"""

import socket
import time

import grpc
from soporte import C, FINALES, Base, es, parte_texto, pb, razon_de, texto_de


class TestCapacidades(Base):
    def test_1_h2c_y_http1_no_tumba_el_servicio(self):
        pb_cap = self.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5)
        self.assertEqual(list(pb_cap.accepted_mime_types), ["image/jpeg", "image/png"])
        with socket.create_connection(("127.0.0.1", self.puerto), timeout=3) as s:
            s.sendall(b"GET / HTTP/1.1\r\nHost: x\r\n\r\n")
            s.settimeout(2)
            try:
                s.recv(1024)
            except OSError:
                pass
        self.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5)

    def test_2_capacidades_iguales_siempre(self):
        antes = self.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5)
        self.enviar("uno")
        medio = self.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5)
        # Aunque Laravel cambie o este apagado, la respuesta es la guardada.
        self.falso.capacidades["max_archivos"] = 1
        self.falso.deshabilitado = True
        despues = self.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5)
        self.assertEqual(antes, medio)
        self.assertEqual(antes, despues)
        for tipo in antes.accepted_mime_types:
            self.assertEqual(tipo, tipo.lower())
        self.assertEqual(antes.max_file_bytes, 4 * 1024 * 1024)
        self.assertEqual(antes.max_files_per_message, 4)
        self.assertEqual(antes.max_text_bytes, 16384)
        self.assertFalse(antes.supports_fork)
        self.assertTrue(antes.supports_cancel)


class TestEnvio(Base):
    def test_3_historial_vacio(self):
        self.assertEqual(self.historial(), [])

    def test_5_after_mayor_que_latest(self):
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.historial(after_sequence=1)

    def test_6_7_envio_y_respuesta(self):
        m = self.enviar("one", "t6")
        self.assertEqual(m.sequence, 1)
        self.assertFalse(m.from_service)
        self.assertEqual(m.status, pb.MESSAGE_STATUS_COMPLETED)
        self.assertEqual(texto_de(m), "one")
        self.assertEqual(m.idempotency_key, "t6")
        self.assertTrue(m.message_id)
        r = self.respuesta_a(m)
        self.assertTrue(r.from_service)
        self.assertGreater(r.sequence, 1)
        self.assertIn(r.status, (pb.MESSAGE_STATUS_COMPLETED, pb.MESSAGE_STATUS_FAILED))
        self.assertEqual(texto_de(r), "eco: one ✓")
        self.assertEqual(r.reasoning, "Pienso. ")
        self.assertEqual(list(r.suggestions), ["Si", "No"])

    def test_8_idempotencia(self):
        a = self.enviar("one", "t6")
        self.respuesta_a(a)
        antes = len(self.historial())
        b = self.enviar("one", "t6")
        self.assertEqual((a.message_id, a.sequence), (b.message_id, b.sequence))
        time.sleep(0.3)
        self.assertEqual(len(self.historial()), antes)

    def test_9_dos_envios_simultaneos(self):
        from concurrent.futures import ThreadPoolExecutor

        with ThreadPoolExecutor(2) as pool:
            a, b = list(pool.map(lambda k: self.enviar("x" + k, "k" + k), ["1", "2"]))
        self.assertNotEqual(a.sequence, b.sequence)
        self.respuesta_a(a)
        self.respuesta_a(b)
        historial = self.historial()
        self.assertEqual(len(historial), 4)
        self.assertEqual(sum(1 for m in historial if m.from_service), 2)

    def test_10_envio_con_respuesta_en_curso_no_espera(self):
        lento = self.enviar("!lento uno")
        inicio = time.time()
        otro = self.enviar("dos")
        self.assertLess(time.time() - inicio, 2)
        self.assertGreater(otro.sequence, lento.sequence)
        self.falso.liberar()
        self.respuesta_a(lento)

    def test_11_12_paginacion(self):
        for i in range(3):
            self.respuesta_a(self.enviar("m%d" % i))
        total = self.falso.ultima()
        self.assertGreaterEqual(total, 6)
        solo = self.historial(after_sequence=1, limit=1)
        self.assertEqual([m.sequence for m in solo], [2])
        self.assertEqual(self.historial(after_sequence=total), [])  # 12
        self.assertEqual([m.sequence for m in self.historial(last=2)], [total - 1, total])  # 12a
        self.assertEqual([m.sequence for m in self.historial(before_sequence=3)], [1, 2])  # 12b
        with self.error(C.INVALID_ARGUMENT, "bad_request"):  # 12c
            self.historial(limit=1, last=1)
        self.assertEqual(self.historial(before_sequence=2, after_sequence=5), [])

    def test_13_envios_no_validos(self):
        casos = [
            pb.SendMessageRequest(),
            pb.SendMessageRequest(content=[parte_texto("")]),
            pb.SendMessageRequest(content=[pb.ContentPart()]),
            pb.SendMessageRequest(content=[parte_texto("x")], idempotency_key="a b"),
            pb.SendMessageRequest(content=[parte_texto("x")], idempotency_key="a" * 65),
            pb.SendMessageRequest(content=[parte_texto("x")], idempotency_key="a" * 63 + "!"),
            pb.SendMessageRequest(content=[pb.ContentPart(structured=pb.StructuredContent(media_type="a/b", data=b"x"))]),
        ]
        for req in casos:
            with self.subTest(req=str(req)), self.error(C.INVALID_ARGUMENT, "bad_request"):
                self.chat.SendMessage(req, timeout=5)
        self.assertEqual(self.falso.ultima(), 0)

    def test_13a_cadenas_vacias_son_ausentes(self):
        req = pb.SendMessageRequest(content=[parte_texto("x")], idempotency_key="", reply_to_message_id="")
        a = self.chat.SendMessage(req, timeout=5)
        self.assertFalse(a.HasField("idempotency_key"))
        self.assertFalse(a.HasField("reply_to_message_id"))
        b = self.chat.SendMessage(req, timeout=5)
        self.assertNotEqual(a.message_id, b.message_id)

    def test_14_texto_sin_cambios(self):
        m = self.enviar("  x  é ✓")
        self.assertEqual(texto_de(m), "  x  é ✓")

    def test_15_texto_de_16384_bytes(self):
        self.assertEqual(texto_de(self.enviar("a" * 16384)), "a" * 16384)
        self.assertEqual(texto_de(self.enviar("\n" * 16384)), "\n" * 16384)
        req = pb.SendMessageRequest(content=[parte_texto("a" * 8192), parte_texto("b" * 8192)])
        m = self.chat.SendMessage(req, timeout=5)
        self.assertEqual(texto_de(m), "a" * 8192 + "b" * 8192)
        with self.error(C.INVALID_ARGUMENT, "too_large"):
            self.enviar("a" * 16385)

    def test_16_campo_desconocido(self):
        crudo = pb.SendMessageRequest(content=[parte_texto("campo nuevo")]).SerializeToString()
        crudo += b"\xf8\x06\x01"  # campo 111, varint 1
        llamada = self.canal.unary_unary("/ChatService/SendMessage", None, None)
        respuesta = llamada(crudo, timeout=5)
        self.assertEqual(texto_de(pb.Message.FromString(respuesta)), "campo nuevo")

    def test_17_reply_to_inexistente(self):
        with self.error(C.NOT_FOUND, "not_found"):
            self.enviar("x", reply_to_message_id="no-existe")
        self.assertEqual(self.falso.ultima(), 0)

    def test_18_conversacion_ajena(self):
        with self.error(C.NOT_FOUND, "not_found"):
            self.enviar("x", conversation_id="other")
        with self.error(C.NOT_FOUND, "not_found"):
            self.historial(conversation_id="other")
        with self.error(C.NOT_FOUND, "not_found"):
            self.chat.GetConversation(pb.GetConversationRequest(conversation_id="other"), timeout=5)
        self.assertEqual(self.falso.ultima(), 0)

    def test_19_mensaje_final_no_cambia(self):
        r = self.respuesta_a(self.enviar("x"))
        a = [m for m in self.historial() if m.message_id == r.message_id][0]
        b = [m for m in self.historial() if m.message_id == r.message_id][0]
        self.assertEqual(a, b)

    def test_29_respuesta_fallida_con_error(self):
        m = self.enviar("!falla x")
        r = self.respuesta_a(m)
        self.assertEqual(r.status, pb.MESSAGE_STATUS_FAILED)
        self.assertEqual(r.error, "No se pudo responder.")
        self.assertEqual(list(r.suggestions), [])
        self.assertFalse(self.respuesta_a(self.enviar("otra")).HasField("error"))

    def test_30_fallo_al_guardar(self):
        self.falso.fallo_al_guardar = True
        with self.error(C.INTERNAL, "service_error"):
            self.enviar("x")
        self.assertEqual(self.falso.ultima(), 0)

    def test_31_historial_lleno(self):
        self.falso.historial_max = 0
        with self.error(C.RESOURCE_EXHAUSTED, "history_full"):
            self.enviar("x")

    def test_32_ocupado(self):
        self.falso.forzar_busy = True
        with self.error(C.UNAVAILABLE, "busy"):
            self.enviar("x")

    def test_chat_deshabilitado_es_unavailable_sin_razon(self):
        self.falso.deshabilitado = True
        with self.error(C.UNAVAILABLE, None):
            self.enviar("x")
        with self.error(C.UNAVAILABLE, None):
            self.historial()

    def test_laravel_caido_es_unavailable_sin_razon(self):
        self.falso.parar()
        with self.error(C.UNAVAILABLE, None):
            self.enviar("x")

    def test_tipos_de_envio_con_corrector(self):  # 89 y 90
        corrector = pb.CorrectorState(enabled=True, corrections=[pb.Correction(original="teh", replacement="the")])
        m = self.chat.SendMessage(
            pb.SendMessageRequest(content=[parte_texto("the cat")], corrector=corrector), timeout=5
        )
        self.assertEqual(texto_de(m), "the cat")
        self.assertEqual(m.corrector, corrector)
        muchas = pb.CorrectorState(
            enabled=True, corrections=[pb.Correction(original="a%d" % i, replacement="zz") for i in range(40)]
        )
        m2 = self.chat.SendMessage(pb.SendMessageRequest(content=[parte_texto("hi")], corrector=muchas), timeout=5)
        self.assertEqual(texto_de(m2), "hi")
        sin = self.enviar("sin corrector")
        self.assertFalse(sin.HasField("corrector"))


class TestFijado(Base):
    def test_33_a_38_fijar(self):
        usuario = self.enviar("x")
        r = self.respuesta_a(usuario)
        f = self.flujo_listo(self.falso.ultima())
        a = self.chat.SetMessagePinned(pb.SetMessagePinnedRequest(message_id=r.message_id, pinned=True), timeout=5)
        self.assertTrue(a.pinned)
        self.assertGreaterEqual(a.updated_at, r.updated_at)
        evento = f.hasta(lambda e: es(e, "message_pinned_changed"))[-1]
        self.assertEqual(evento.message_pinned_changed.message_id, r.message_id)
        self.assertTrue(evento.message_pinned_changed.pinned)
        # 35: repetir no cambia updated_at ni genera evento.
        b = self.chat.SetMessagePinned(pb.SetMessagePinnedRequest(message_id=r.message_id, pinned=True), timeout=5)
        self.assertEqual(b.updated_at, a.updated_at)
        self.assertEqual(f.silencio(0.4), [])
        # 34: un mensaje de usuario.
        u = self.chat.SetMessagePinned(pb.SetMessagePinnedRequest(message_id=usuario.message_id, pinned=True), timeout=5)
        self.assertTrue(u.pinned)
        self.assertEqual(u.content, usuario.content)
        self.assertEqual(u.status, usuario.status)
        # 36
        c = self.chat.SetMessagePinned(pb.SetMessagePinnedRequest(message_id=r.message_id, pinned=False), timeout=5)
        self.assertFalse(c.pinned)
        self.assertGreaterEqual(c.updated_at, a.updated_at)
        self.assertFalse([m for m in self.historial() if m.message_id == r.message_id][0].pinned)

    def test_37_fijar_inexistente(self):
        with self.error(C.NOT_FOUND, "not_found"):
            self.chat.SetMessagePinned(pb.SetMessagePinnedRequest(message_id="nada", pinned=True), timeout=5)

    def test_38_fijar_con_mensaje_abierto(self):
        usuario = self.enviar("!lento x")
        asistente = self.esperar(lambda: [m for m in self.historial() if m.from_service] or None)[0]
        self.assertNotIn(asistente.status, FINALES)
        f = self.flujo_listo(self.falso.ultima())
        self.chat.SetMessagePinned(pb.SetMessagePinnedRequest(message_id=asistente.message_id, pinned=True), timeout=5)
        f.hasta(lambda e: es(e, "message_pinned_changed"))
        self.falso.liberar()
        final = self.respuesta_a(usuario)
        self.assertTrue(final.pinned)


class TestConversacion(Base):
    def test_40_conversacion_principal(self):
        c = self.chat.GetConversation(pb.GetConversationRequest(), timeout=5)
        self.assertEqual(c.conversation_id, "")
        self.assertGreater(c.created_at, 0)
        self.assertFalse(c.archived)
        self.assertFalse(c.HasField("title"))

    def test_41_fork_no_implementado(self):
        with self.error(C.UNIMPLEMENTED, "unimplemented"):
            self.chat.ForkConversation(pb.ForkConversationRequest(forked_from_message_id="m1"), timeout=5)

    def test_84_titulo_en_conversacion(self):
        self.respuesta_a(self.enviar("x"))
        c = self.chat.GetConversation(pb.GetConversationRequest(), timeout=5)
        self.assertEqual(c.title, "Titulo de prueba")

    def test_74_metodo_desconocido(self):
        llamada = self.canal.unary_unary("/ChatService/NoExiste", None, None)
        with self.assertRaises(grpc.RpcError) as ctx:
            llamada(b"", timeout=5)
        self.assertEqual(ctx.exception.code(), C.UNIMPLEMENTED)

    def test_73a_mensaje_sobre_el_limite_de_recepcion(self):
        grande = pb.SendMessageRequest(content=[parte_texto("a" * (4 * 1024 * 1024 + 1))])
        with self.assertRaises(grpc.RpcError) as ctx:
            self.chat.SendMessage(grande, timeout=20)
        self.assertEqual(ctx.exception.code(), C.RESOURCE_EXHAUSTED)
        self.assertIsNone(razon_de(ctx.exception))
        self.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5)  # el servicio sigue vivo


class TestTextoQueNoEsUtf8(Base):
    def test_texto_que_no_es_utf8_es_bad_request_y_no_cambia_nada(self):
        """5.1: una parte de texto que no es UTF-8 valido no debe acabar en INTERNAL ni crear un mensaje."""
        llamar = self.canal.unary_unary("/ChatService/SendMessage")
        texto = b"\x0a\x05ab\xff\xfec"  # TextContent.text = bytes no UTF-8
        parte = b"\x0a" + bytes([len(texto)]) + texto  # ContentPart.text
        cuerpo = b"\x12" + bytes([len(parte)]) + parte  # SendMessageRequest.content
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            llamar(cuerpo, timeout=5)
        self.assertEqual(self.latest(), 0)
        self.enviar("sigue funcionando")
