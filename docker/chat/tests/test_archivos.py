"""FileService (chat.md 6.4) y envios con adjuntos (6.1, 5.1)."""

import hashlib
import os

from soporte import C, Base, parte_texto, pb, referencia, texto_de

SHA3_TIPO = hashlib.sha3_256(b"").digest()
LIMITE = 4 * 1024 * 1024
PNG = b"\x89PNG\r\n\x1a\n" + os.urandom(2000)


def hash_de(datos: bytes, tipo: bytes = SHA3_TIPO) -> pb.Hash:
    return pb.Hash(type=tipo, value=hashlib.sha3_256(datos).digest())


class Archivos(Base):
    def subir(self, ref, datos, trozo=65536, files=None, extra_file=False, primero_chunk=False):
        def gen():
            if primero_chunk:
                yield pb.UploadFileRequest(chunk=b"x")
            yield pb.UploadFileRequest(file=ref)
            if extra_file:
                yield pb.UploadFileRequest(file=ref)
            for i in range(0, len(datos), trozo):
                yield pb.UploadFileRequest(chunk=datos[i : i + trozo])

        return (files or self.files).UploadFile(gen(), timeout=60)

    def consultar(self, *hashes, files=None):
        pet = [pb.QueryFilesRequest(hash=h) for h in hashes]
        return list((files or self.files).QueryFiles(iter(pet), timeout=10))

    def disponible(self, h):
        return self.consultar(h)[0].available

    def bajar(self, h):
        return list(self.files.DownloadFile(pb.DownloadFileRequest(hash=h), timeout=30))


class TestConsulta(Archivos):
    def test_48_50_no_disponibles_y_tipo_desconocido(self):
        a, b = hash_de(b"a"), hash_de(b"b")
        respuestas = self.consultar(a, b)
        self.assertEqual([r.hash for r in respuestas], [a, b])
        self.assertEqual([r.available for r in respuestas], [False, False])
        self.assertEqual(respuestas[0].hash.type, SHA3_TIPO)
        raro = pb.Hash(type=b"\x01" * 16, value=b"\x02" * 7)
        r = self.consultar(raro)[0]
        self.assertFalse(r.available)
        self.assertEqual(r.hash, raro)

    def test_48a_tipo_de_64_bytes_ascii_es_desconocido(self):
        self.subir(referencia(PNG), PNG)
        texto = hash_de(PNG, SHA3_TIPO.hex().encode())
        self.assertEqual(len(texto.type), 64)
        self.assertFalse(self.consultar(texto)[0].available)
        self.assertTrue(self.consultar(hash_de(PNG))[0].available)

    def test_49_tipo_vacio(self):
        vacio = pb.Hash(value=b"\x00" * 32)
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.consultar(vacio)
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.consultar(hash_de(b"a"), vacio)

    def test_49a_valor_de_31_bytes(self):
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.consultar(pb.Hash(type=SHA3_TIPO, value=b"\x00" * 31))

    def test_consulta_vacia_termina(self):
        self.assertEqual(self.consultar(), [])


class TestSubida(Archivos):
    def test_51_a_53_y_60_subir_consultar_bajar(self):
        ref = referencia(PNG, nombre="comida.png")
        respuesta = self.subir(ref, PNG)
        self.assertEqual(respuesta.file.hashes[0], hash_de(PNG))
        self.assertEqual(respuesta.file.size_bytes, len(PNG))
        self.assertEqual(respuesta.file.mime_type, "image/png")
        self.assertEqual(respuesta.file.filename, "comida.png")
        self.assertTrue(self.disponible(hash_de(PNG)))
        trozos = self.bajar(hash_de(PNG))
        self.assertTrue(all(len(t.chunk) <= 65536 for t in trozos))
        self.assertEqual(b"".join(t.chunk for t in trozos), PNG)
        # 60: repetir no es un error y da la misma referencia
        self.assertEqual(self.subir(ref, PNG).file, respuesta.file)

    def test_3_mib_con_hash_en_trozos(self):
        datos = os.urandom(3 * 1024 * 1024)
        self.subir(referencia(datos), datos, trozo=48 * 1024)
        trozos = self.bajar(hash_de(datos))
        self.assertTrue(all(0 < len(t.chunk) <= 65536 for t in trozos))
        self.assertGreater(len(trozos), 40)
        bajado = b"".join(t.chunk for t in trozos)
        self.assertEqual(hashlib.sha3_256(bajado).digest(), hash_de(datos).value)
        self.assertEqual(self.falso.archivos[hash_de(datos).value.hex()]["datos"], datos)

    def test_54_hash_que_no_es_el_de_los_bytes(self):
        ref = referencia(PNG)
        ref.hashes[0].value = hashlib.sha3_256(b"otro").digest()
        with self.error(C.INVALID_ARGUMENT, "hash_mismatch"):
            self.subir(ref, PNG)
        self.assertFalse(self.disponible(ref.hashes[0]))

    def test_55_tamano_declarado_distinto(self):
        for declarado in (len(PNG) + 1, len(PNG) - 1):
            with self.subTest(declarado=declarado), self.error(C.INVALID_ARGUMENT, "hash_mismatch"):
                self.subir(referencia(PNG, tamano=declarado), PNG)
        self.assertFalse(self.disponible(hash_de(PNG)))

    def test_55a_tamano_cero(self):
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.subir(referencia(b"", ), b"")
        self.assertFalse(self.disponible(hash_de(b"")))

    def test_55b_55f_hash_sin_tipo_o_valor_corto(self):
        ref = referencia(PNG)
        ref.hashes[0].type = b""
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.subir(ref, PNG)
        ref = referencia(PNG)
        ref.hashes[0].value = ref.hashes[0].value[:31]
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.subir(ref, PNG)

    def test_55c_tamano_antes_que_tipo_y_antes_que_segundo_file(self):
        grande = referencia(b"x", mime="application/pdf", tamano=LIMITE + 1)
        with self.error(C.INVALID_ARGUMENT, "too_large"):
            self.subir(grande, b"x")
        with self.error(C.INVALID_ARGUMENT, "too_large"):
            self.subir(referencia(b"x", tamano=LIMITE + 1), b"x", extra_file=True)

    def test_55d_almacen_lleno(self):
        self.falso.almacen_lleno = True
        with self.error(C.RESOURCE_EXHAUSTED, "storage_full"):
            self.subir(referencia(PNG), PNG)
        self.assertFalse(self.disponible(hash_de(PNG)))
        self.falso.almacen_lleno = False
        self.enviar("despues")  # no es history_full

    def test_55e_segundo_file(self):
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.subir(referencia(PNG), PNG, extra_file=True)
        self.assertFalse(self.disponible(hash_de(PNG)))

    def test_56_primer_mensaje_chunk(self):
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.subir(referencia(PNG), PNG, primero_chunk=True)
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.files.UploadFile(iter([]), timeout=5)

    def test_57_tipo_no_aceptado(self):
        with self.error(C.INVALID_ARGUMENT, "unsupported_media"):
            self.subir(referencia(PNG, mime="application/pdf"), PNG)
        self.assertFalse(self.disponible(hash_de(PNG)))

    def test_58_59_limite_de_4_mib(self):
        exacto = os.urandom(LIMITE)
        self.subir(referencia(exacto), exacto)
        self.assertTrue(self.disponible(hash_de(exacto)))
        de_mas = os.urandom(LIMITE + 1)
        with self.error(C.INVALID_ARGUMENT, "too_large"):
            self.subir(referencia(de_mas), de_mas)
        self.assertFalse(self.disponible(hash_de(de_mas)))

    def test_mime_con_parametros_y_mayusculas(self):
        r = self.subir(referencia(PNG, mime="IMAGE/PNG; q=1"), PNG)
        self.assertEqual(r.file.mime_type, "image/png")

    def test_hash_de_tipo_desconocido_se_descarta(self):
        ref = referencia(PNG)
        ref.hashes.append(pb.Hash(type=b"\x07" * 8, value=b"\x01"))
        self.assertEqual(self.subir(ref, PNG).file.hashes[0], hash_de(PNG))
        solo_desconocido = pb.FileReference(
            hashes=[pb.Hash(type=b"\x07" * 8, value=b"\x01")], mime_type="image/png", size_bytes=3
        )
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.subir(solo_desconocido, b"abc")

    def test_sin_tipos_aceptados_nada_se_sube(self):
        chat, files = self.con_capacidades(tipos_mime=[])
        with self.error(C.INVALID_ARGUMENT, "unsupported_media"):
            self.subir(referencia(PNG), PNG, files=files)
        self.assertFalse(self.consultar(hash_de(PNG), files=files)[0].available)


class TestDescarga(Archivos):
    def test_71_no_encontrado_sin_tipo_y_tipo_desconocido(self):
        with self.error(C.NOT_FOUND, "not_found"):
            self.bajar(hash_de(b"nada"))
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.bajar(pb.Hash(value=b"\x00" * 32))
        with self.error(C.NOT_FOUND, "not_found"):
            self.bajar(pb.Hash(type=b"\x09" * 5, value=b"\x01"))
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.bajar(pb.Hash(type=SHA3_TIPO, value=b"\x00" * 31))


class TestAdjuntos(Archivos):
    def imagen(self, ref):
        return pb.ContentPart(image=pb.ImageContent(file=ref, alt_text="plato"))

    def enviar_partes(self, partes, clave=None, chat=None):
        req = pb.SendMessageRequest(content=partes)
        if clave:
            req.idempotency_key = clave
        return (chat or self.chat).SendMessage(req, timeout=10)

    def test_61_62_68_imagen_sin_texto(self):
        self.subir(referencia(PNG), PNG)
        m = self.enviar_partes([self.imagen(referencia(PNG, mime="IMAGE/PNG", nombre="a.png"))])
        parte = m.content[0].image
        self.assertEqual(parte.file.hashes[0], hash_de(PNG))
        self.assertEqual(parte.file.mime_type, "image/png")
        self.assertEqual(parte.file.size_bytes, len(PNG))
        self.assertEqual(parte.file.filename, "a.png")
        self.assertEqual(parte.alt_text, "plato")
        self.assertEqual(b"".join(t.chunk for t in self.bajar(parte.file.hashes[0])), PNG)
        # el mensaje no lleva los bytes y los lee de vuelta con GetMessages
        leido = self.historial()[0]
        self.assertEqual(leido.content[0].image.file.hashes[0], hash_de(PNG))

    def test_texto_mas_imagen_en_orden(self):
        self.subir(referencia(PNG), PNG)
        m = self.enviar_partes([parte_texto("mi cena"), self.imagen(referencia(PNG))])
        self.assertEqual([p.WhichOneof("kind") for p in m.content], ["text", "image"])
        self.assertEqual(texto_de(m), "mi cena")

    def test_63_archivo_no_subido(self):
        with self.error(C.FAILED_PRECONDITION, "file_missing"):
            self.enviar_partes([self.imagen(referencia(b"nunca subido"))])
        self.assertEqual(self.falso.ultima(), 0)

    def test_64_tipo_y_clase(self):
        with self.error(C.INVALID_ARGUMENT, "unsupported_media"):
            self.enviar_partes([self.imagen(referencia(PNG, mime="application/pdf"))])
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.enviar_partes([self.imagen(referencia(PNG, mime="audio/wav"))])
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.enviar_partes([pb.ContentPart(audio=pb.AudioContent(file=referencia(PNG, mime="image/png")))])
        self.assertEqual(self.falso.ultima(), 0)

    def test_64a_hash_sin_tipo_y_tamano_cero(self):
        sin_tipo = referencia(PNG)
        sin_tipo.hashes[0].type = b""
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.enviar_partes([self.imagen(sin_tipo)])
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.enviar_partes([self.imagen(referencia(PNG, tamano=0))])
        self.assertEqual(self.falso.ultima(), 0)

    def test_65_cinco_adjuntos(self):
        self.subir(referencia(PNG), PNG)
        with self.error(C.INVALID_ARGUMENT, "bad_request"):
            self.enviar_partes([self.imagen(referencia(PNG))] * 5)
        self.assertEqual(self.falso.ultima(), 0)

    def test_66_67_sin_tipos_o_tipo_no_aceptado(self):
        chat, _ = self.con_capacidades(tipos_mime=[])
        with self.error(C.INVALID_ARGUMENT, "unsupported_media"):
            self.enviar_partes([self.imagen(referencia(PNG))], chat=chat)
        audio = pb.ContentPart(audio=pb.AudioContent(file=referencia(b"abc", mime="audio/mpeg")))
        with self.error(C.INVALID_ARGUMENT, "unsupported_media"):
            self.enviar_partes([audio])  # solo imagenes aceptadas
        self.assertEqual(self.falso.ultima(), 0)

    def test_69_cuatro_adjuntos_de_4_mib(self):
        ficheros = [os.urandom(LIMITE) for _ in range(4)]
        for datos in ficheros:
            self.subir(referencia(datos), datos)
        m = self.enviar_partes([self.imagen(referencia(d)) for d in ficheros])
        self.assertEqual(len(m.content), 4)

    def test_70_audio_wav_con_duracion(self):
        chat, files = self.con_capacidades(tipos_mime=["image/jpeg", "image/png", "audio/mpeg", "audio/wav"])
        wav = b"RIFF" + os.urandom(500)
        self.subir(referencia(wav, mime="audio/wav"), wav, files=files)
        audio = pb.AudioContent(file=referencia(wav, mime="audio/wav"), duration_ms=1234, transcript="hola")
        m = self.enviar_partes([pb.ContentPart(audio=audio)], chat=chat)
        self.assertEqual(m.content[0].audio.duration_ms, 1234)
        self.assertEqual(m.content[0].audio.transcript, "hola")

    def test_archivo_de_asistente_y_peticion_de_secreto_se_convierten(self):
        from conversion import mensaje_a_pb

        d = {
            "message_id": "x", "sequence": 3, "status": "COMPLETED", "from_service": True,
            "content": [
                {"tipo": "archivo", "hash": hash_de(PNG).value.hex(), "mime": "image/png", "tamano": 5, "nombre": "n.png"},
                {"tipo": "futuro"},
                {"tipo": "peticion_secreto", "request_id": "r1", "nombre": "TOKEN", "prosa": ""},
            ],
        }
        m = mensaje_a_pb(d)
        self.assertEqual([p.WhichOneof("kind") for p in m.content], ["file", "secret_request"])
        self.assertEqual(m.content[0].file.hashes[0].type, SHA3_TIPO)
        self.assertFalse(m.content[1].secret_request.HasField("prose"))
