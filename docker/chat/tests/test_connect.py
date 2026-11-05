"""Connect (chat.md 6.5): replay, deltas en vivo, errores de evento, reconexion."""

import time
from concurrent.futures import ThreadPoolExecutor

import grpc
from soporte import C, FINALES, Base, FinFlujo, Flujo, es, parte_texto, pb, razon_de, texto_de, tipo


def evento_envio(texto, clave, id_evento=None, **campos):
    ev = pb.ClientEvent(
        send_message=pb.SendMessageRequest(content=[parte_texto(texto)], idempotency_key=clave, **campos)
    )
    if id_evento is not None:
        ev.event_id = id_evento
    return ev


class TestSuscripcion(Base):
    def test_4_suscripcion_vacia(self):
        f = self.flujo()
        f.suscribir(0)
        vistos = f.hasta_listo()
        self.assertEqual([tipo(v) for v in vistos], ["subscription_ready"])
        self.assertEqual(vistos[0].subscription_ready.latest_sequence, 0)
        self.assertFalse(vistos[0].subscription_ready.HasField("title"))

    def test_24_primer_evento_no_es_subscribe(self):
        f = self.flujo()
        f.enviar(evento_envio("x", "k1", "e1"))
        fin = f.siguiente()
        self.assertIsInstance(fin, FinFlujo)
        self.assertEqual(fin.error.code(), C.FAILED_PRECONDITION)
        self.assertEqual(razon_de(fin.error), "bad_state")

    def test_25_segundo_subscribe(self):
        f = self.flujo_listo()
        f.enviar(pb.ClientEvent(subscribe=pb.SubscribeRequest(), event_id="s2"))
        e = f.siguiente()
        self.assertEqual(e.error.code, "bad_state")
        self.assertEqual(e.error.event_id, "s2")
        # sigue abierto
        f.enviar(evento_envio("x", "k25", "e25"))
        f.hasta(lambda e: es(e, "message_created"))

    def test_27i_event_id_largo_en_subscribe(self):
        f = self.flujo()
        f.suscribir(0, "e" * 65)
        fin = f.siguiente()
        self.assertEqual(fin.error.code(), C.INVALID_ARGUMENT)
        self.assertEqual(razon_de(fin.error), "bad_request")

    def test_27j_conversacion_ajena(self):
        f = self.flujo()
        f.enviar(pb.ClientEvent(subscribe=pb.SubscribeRequest(conversation_id="other")))
        fin = f.siguiente()
        self.assertEqual(fin.error.code(), C.NOT_FOUND)
        self.assertEqual(razon_de(fin.error), "not_found")

    def test_28_after_mayor_que_latest(self):
        f = self.flujo()
        f.suscribir(self.falso.ultima() + 1)
        fin = f.siguiente()
        self.assertEqual(fin.error.code(), C.INVALID_ARGUMENT)
        self.assertEqual(razon_de(fin.error), "bad_request")

    def test_cliente_cierra_y_servicio_termina_ok(self):
        f = self.flujo_listo()
        f.cerrar_cliente()
        fin = f.siguiente()
        self.assertIsInstance(fin, FinFlujo)
        self.assertIsNone(fin.error)


class TestEventosEnVivo(Base):
    def test_20_26_27c_envio_por_stream(self):
        f = self.flujo_listo()
        f.enviar(evento_envio("hola", "k26", "e1"))
        vistos = f.hasta_estado(pb.MESSAGE_STATUS_COMPLETED)
        tipos = [tipo(v) for v in vistos]
        self.assertEqual(tipos[0], "message_created")
        usuario = vistos[0].message_created.message
        self.assertEqual(usuario.idempotency_key, "k26")
        self.assertFalse(usuario.from_service)
        self.assertEqual(tipos[1], "message_created")
        asistente = vistos[1].message_created.message
        self.assertTrue(asistente.from_service)
        self.assertIn(asistente.status, (pb.MESSAGE_STATUS_PENDING, pb.MESSAGE_STATUS_IN_PROGRESS))
        self.assertNotIn("error", tipos)
        # 20 y 21: texto y razonamiento = creado + deltas; el razonamiento no va en TextContent.
        texto = texto_de(asistente)
        razonamiento = asistente.reasoning
        for v in vistos:
            if tipo(v) == "message_delta":
                texto += v.message_delta.text_append
                razonamiento += v.message_delta.reasoning_append
        final = [m for m in self.historial() if m.message_id == asistente.message_id][0]
        self.assertEqual(texto, texto_de(final))
        self.assertEqual(razonamiento, final.reasoning)
        self.assertEqual(texto, "eco: hola ✓")
        self.assertEqual(razonamiento, "Pienso. ")
        cambio = vistos[-1].message_status_changed
        self.assertEqual(list(cambio.suggestions), ["Si", "No"])
        self.assertEqual(list(final.suggestions), ["Si", "No"])  # 88
        # 27c: misma clave con otro event_id => mismo MessageCreated y nada nuevo.
        total = self.falso.ultima()
        f.enviar(evento_envio("hola", "k26", "e2"))
        otra = f.hasta(lambda e: es(e, "message_created"))[-1].message_created.message
        self.assertEqual((otra.message_id, otra.sequence), (usuario.message_id, usuario.sequence))
        self.assertEqual(f.silencio(0.4), [])
        self.assertEqual(self.falso.ultima(), total)

    def test_envio_por_stream_llega_a_otro_stream(self):
        a = self.flujo_listo()
        b = self.flujo_listo()
        self.enviar("por unary", "u1")
        for f in (a, b):
            m = f.hasta(lambda e: es(e, "message_created"))[-1].message_created.message
            self.assertEqual(m.idempotency_key, "u1")

    def test_27_a_27l_errores_de_evento(self):
        f = self.flujo_listo()
        f.enviar(pb.ClientEvent(send_message=pb.SendMessageRequest(), event_id="e2"))
        e = f.siguiente()
        self.assertEqual((e.error.code, e.error.event_id), ("bad_request", "e2"))
        f.enviar(evento_envio("ok", "k26", "e1"))
        f.hasta_estado(pb.MESSAGE_STATUS_COMPLETED)
        total = self.falso.ultima()
        f.enviar(evento_envio("otra", "otra", "e1"))  # 27a
        e = f.hasta(lambda e: es(e, "error"))[-1]
        self.assertEqual(e.error.code, "bad_request")
        self.assertFalse(e.error.HasField("event_id"))
        f.enviar(evento_envio("x", "k27b", "e" * 65))  # 27b
        e = f.hasta(lambda e: es(e, "error"))[-1]
        self.assertFalse(e.error.HasField("event_id"))
        self.assertEqual(self.falso.ultima(), total)  # 27a y 27b no anaden nada
        adjunto = pb.SendMessageRequest(
            content=[pb.ContentPart(image=pb.ImageContent(file=_ref_pdf()))], idempotency_key="k27d"
        )
        f.enviar(pb.ClientEvent(send_message=adjunto, event_id="e3"))  # 27d
        e = f.hasta(lambda e: es(e, "error"))[-1]
        self.assertEqual((e.error.code, e.error.event_id), ("unsupported_media", "e3"))
        f.enviar(pb.ClientEvent(send_message=pb.SendMessageRequest(content=[parte_texto("x")],
                 conversation_id="otra"), event_id="e9"))
        e = f.hasta(lambda e: es(e, "error"))[-1]
        self.assertEqual((e.error.code, e.error.event_id), ("not_found", "e9"))
        # sigue vivo
        f.enviar(evento_envio("vivo", "k-vivo", "e10"))
        f.hasta(lambda e: es(e, "message_created"))

    def test_27h_event_id_del_subscribe_cuenta(self):
        f = self.flujo()
        f.suscribir(0, "s1")
        f.hasta_listo()
        f.enviar(evento_envio("x", "k27h", "s1"))
        e = f.siguiente()
        self.assertEqual(e.error.code, "bad_request")
        self.assertFalse(e.error.HasField("event_id"))
        self.assertEqual(self.falso.ultima(), 0)

    def test_27e_27f_27g_27l_acknowledge(self):
        usuario = self.enviar("x")
        self.respuesta_a(usuario)
        total = self.falso.ultima()
        f = self.flujo_listo(total)
        f.enviar(pb.ClientEvent(acknowledge=pb.AcknowledgeEvent(last_sequence=total)))
        f.enviar(pb.ClientEvent(acknowledge=pb.AcknowledgeEvent(last_sequence=1)))  # 27g
        self.assertEqual(f.silencio(0.4), [])
        f.enviar(pb.ClientEvent(acknowledge=pb.AcknowledgeEvent(last_sequence=total + 1), event_id="e4"))
        e = f.siguiente()
        self.assertEqual((e.error.code, e.error.event_id), ("bad_request", "e4"))
        f.enviar(pb.ClientEvent(acknowledge=pb.AcknowledgeEvent(last_sequence=1), event_id="e" * 65))
        e = f.siguiente()
        self.assertEqual(e.error.code, "bad_request")
        self.assertFalse(e.error.HasField("event_id"))
        self.assertEqual(self.falso.ultima(), total)

    def test_43_44_45_cancelar(self):
        f = self.flujo_listo()
        f.enviar(evento_envio("!lento x", "kc", "e1"))
        asistente = f.hasta(lambda e: es(e, "message_created") and e.message_created.message.from_service)[-1]
        id_asistente = asistente.message_created.message.message_id
        f.enviar(pb.ClientEvent(cancel_message=pb.CancelMessageRequest(message_id=id_asistente), event_id="e5"))
        f.hasta_estado(pb.MESSAGE_STATUS_CANCELLED, id_asistente)
        f.enviar(pb.ClientEvent(cancel_message=pb.CancelMessageRequest(message_id=id_asistente), event_id="e6"))
        e = f.hasta(lambda e: es(e, "error"))[-1]
        self.assertEqual((e.error.code, e.error.event_id), ("bad_state", "e6"))
        self.falso.liberar()
        f.enviar(pb.ClientEvent(cancel_message=pb.CancelMessageRequest(message_id="nada"), event_id="e7"))
        e = f.hasta(lambda e: es(e, "error"))[-1]
        self.assertEqual((e.error.code, e.error.event_id), ("not_found", "e7"))

    def test_45_cancelar_sin_soporte(self):
        self.servidor.stop(0).wait()
        self.setUp_sin_cancelar()
        f = self.flujo_listo()
        f.enviar(pb.ClientEvent(cancel_message=pb.CancelMessageRequest(message_id="m1"), event_id="e6"))
        e = f.siguiente()
        self.assertEqual((e.error.code, e.error.event_id), ("unimplemented", "e6"))

    def setUp_sin_cancelar(self):
        import grpc as g
        from servicios import Capacidades
        from servidor import crear_servidor
        import chat_pb2_grpc

        capacidades = Capacidades.de_json({**self.falso.capacidades, "soporta_cancelar": False})
        self.servidor, puerto = crear_servidor(self.laravel, capacidades, 0, host="127.0.0.1", intervalo_sondeo=0.05)
        self.servidor.start()
        self.addCleanup(lambda: self.servidor.stop(0).wait())
        canal = g.insecure_channel(f"127.0.0.1:{puerto}")
        self.addCleanup(canal.close)
        self.chat = chat_pb2_grpc.ChatServiceStub(canal)
        self.assertFalse(self.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5).supports_cancel)

    def test_envio_con_laravel_caido_termina_el_stream(self):
        f = self.flujo_listo()
        self.falso.deshabilitado = True
        fin = f.hasta(lambda e: isinstance(e, FinFlujo))[-1]
        self.assertEqual(fin.error.code(), C.UNAVAILABLE)
        self.assertIsNone(razon_de(fin.error))


def _ref_pdf():
    from soporte import referencia

    return referencia(b"%PDF-1.4 x", "application/pdf")


class TestReplay(Base):
    def test_22_replay_completo(self):
        a = self.respuesta_a(self.enviar("uno"))
        total = self.falso.ultima()
        f = self.flujo()
        f.suscribir(0)
        vistos = f.hasta_listo()
        mensajes = [v.message_created.message for v in vistos if tipo(v) == "message_created"]
        self.assertEqual([m.sequence for m in mensajes], list(range(1, total + 1)))
        self.assertTrue(all(m.status in FINALES for m in mensajes))
        self.assertEqual(vistos[-1].subscription_ready.latest_sequence, total)
        self.assertEqual(vistos[-1].subscription_ready.title, "Titulo de prueba")  # 85
        self.assertEqual(mensajes[1].message_id, a.message_id)
        # replay parcial
        g = self.flujo()
        g.suscribir(total - 1)
        vistos = g.hasta_listo()
        self.assertEqual(len(vistos), 2)

    def test_23_replay_con_mensaje_abierto_y_deltas(self):
        usuario = self.enviar("!lento hola")
        asistente = self.esperar(lambda: [m for m in self.historial() if m.from_service] or None)[0]
        self.assertEqual(asistente.status, pb.MESSAGE_STATUS_IN_PROGRESS)
        f = self.flujo()
        f.suscribir(0)
        previos = f.hasta_listo()
        abierto = [v.message_created.message for v in previos if tipo(v) == "message_created"][-1]
        self.assertEqual(abierto.message_id, asistente.message_id)
        self.assertEqual(abierto.status, pb.MESSAGE_STATUS_IN_PROGRESS)
        texto, razon = texto_de(abierto), abierto.reasoning
        self.falso.liberar()
        vistos = f.hasta_estado(pb.MESSAGE_STATUS_COMPLETED)
        for v in vistos:
            if tipo(v) == "message_delta":
                texto += v.message_delta.text_append
                razon += v.message_delta.reasoning_append
        final = self.respuesta_a(usuario)
        self.assertEqual(texto, texto_de(final))
        self.assertEqual(razon, final.reasoning)
        self.assertEqual(texto, "eco: !lento hola ✓")

    def test_deltas_con_utf8_multibyte(self):
        f = self.flujo_listo()
        f.enviar(evento_envio("!lento \u00e9\u4e2d\U0001f600", "ku", "e1"))
        f.hasta(lambda e: es(e, "message_created") and e.message_created.message.from_service)
        self.falso.paso = 0.0
        self.falso.liberar()
        vistos = f.hasta_estado(pb.MESSAGE_STATUS_COMPLETED)
        texto = "".join(v.message_delta.text_append for v in vistos if tipo(v) == "message_delta")
        self.assertEqual(texto, "eco: !lento \u00e9\u4e2d\U0001f600 \u2713")

    def test_reconexion_sin_perdida_ni_duplicados(self):
        f = self.flujo_listo()
        f.enviar(evento_envio("uno", "r1", "e1"))
        f.hasta_estado(pb.MESSAGE_STATUS_COMPLETED)
        ultimo = self.falso.ultima()
        f.cancelar()
        self.enviar("durante la caida", "r2")  # llega mientras no hay stream
        g = self.flujo()
        g.suscribir(ultimo)
        vistos = g.hasta_listo()
        nuevos = [v.message_created.message for v in vistos if tipo(v) == "message_created"]
        self.assertEqual(texto_de(nuevos[0]), "durante la caida")
        self.assertEqual([m.sequence for m in nuevos][0], ultimo + 1)
        self.assertEqual(len({m.message_id for m in nuevos}), len(nuevos))
        # y sigue en vivo hasta el final de la respuesta
        g.hasta_estado(pb.MESSAGE_STATUS_COMPLETED)

    def test_86_dos_streams_y_titulo_en_vivo(self):
        a, b = self.flujo_listo(), self.flujo_listo()
        self.enviar("x")
        cambios = []
        for f in (a, b):
            e = f.hasta(lambda e: es(e, "conversation_title_changed"))[-1]
            cambios.append(e.conversation_title_changed)
        self.assertEqual([c.title for c in cambios], ["Titulo de prueba"] * 2)
        self.assertGreater(cambios[0].updated_at, 0)
        self.assertEqual(cambios[0].updated_at, cambios[1].updated_at)  # la hora la fija Laravel, no cada stream
        self.assertEqual(cambios[0].updated_at, self.falso.titulo_ms)
        self.assertEqual(f.silencio(0.3), [])  # no se repite

    def test_cola_llena_termina_con_busy(self):
        self.servidor.stop(0).wait()
        from servidor import crear_servidor
        import chat_pb2_grpc
        import threading

        self.servidor, puerto = crear_servidor(
            self.laravel, self.capacidades, 0, host="127.0.0.1", intervalo_sondeo=0.02, umbral_cola=5
        )
        self.servidor.start()
        self.addCleanup(lambda: self.servidor.stop(0).wait())
        canal = grpc.insecure_channel(f"127.0.0.1:{puerto}")
        self.addCleanup(canal.close)
        chat = chat_pb2_grpc.ChatServiceStub(canal)
        abierto = threading.Event()

        def peticiones():
            yield pb.ClientEvent(subscribe=pb.SubscribeRequest())
            abierto.wait(30)

        self.addCleanup(abierto.set)
        llamada = chat.Connect(peticiones())  # no se lee: el servidor acumula eventos
        time.sleep(0.3)
        for i in range(300):
            self.falso._nuevo(content=[{"tipo": "texto", "texto": "x" * 4096}])
        time.sleep(1.0)
        with self.assertRaises(grpc.RpcError) as ctx:
            for _ in llamada:
                pass
        self.assertEqual(ctx.exception.code(), C.UNAVAILABLE)
        self.assertEqual(razon_de(ctx.exception), "busy")
        g = Flujo(chat)  # una suscripcion nueva funciona
        self.addCleanup(g.cancelar)
        g.suscribir(0)
        g.hasta_listo()


def texto_de_creado(creados):
    return texto_de(creados[-1].message_created.message)


class TestConcurrencia(Base):
    def test_46_47_un_stream_y_siete_unarias_a_la_vez(self):
        f = self.flujo_listo()
        self.falso.paso = 0.2
        self.falso._respuestas = 0

        def unaria(i):
            return self.chat.GetCapabilities(pb.GetCapabilitiesRequest(), timeout=5)

        with ThreadPoolExecutor(7) as pool:
            resultados = list(pool.map(unaria, range(7)))
        self.assertEqual(len(resultados), 7)
        # el stream sigue vivo y sirviendo
        self.enviar("a", "c1")
        f.hasta(lambda e: es(e, "message_created"))

    def test_ocho_streams_a_la_vez(self):
        flujos = [self.flujo_listo() for _ in range(8)]
        self.enviar("para todos", "ocho")
        for f in flujos:
            f.hasta(lambda e: es(e, "message_created"))
