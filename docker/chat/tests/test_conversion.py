"""Conversion de hashes y partes (chat.md 6.4, 5.1)."""

import hashlib
import unittest

from soporte import pb  # noqa: F401  (prepara el sys.path)

import conversion
from laravel import ErrorChat


class TestHash(unittest.TestCase):
    def test_type_es_el_digest_sha3_de_la_entrada_vacia_en_bytes_crudos(self):
        self.assertEqual(len(conversion.SHA3_TIPO), 32)
        self.assertEqual(conversion.SHA3_TIPO.hex(), "a7ffc6f8bf1ed76651c14756a061d662f580ff4de43b49fa82d80a4b80f8434a")
        self.assertNotEqual(conversion.SHA3_TIPO, hashlib.sha256(b"").digest())

    def test_hash_hex_y_vuelta(self):
        h = conversion.hash_pb("ab" * 32)
        self.assertEqual(conversion.hash_conocido(h), "ab" * 32)

    def test_errores_de_cable(self):
        with self.assertRaises(ErrorChat) as c:
            conversion.hash_conocido(pb.Hash(value=b"\x00" * 32))
        self.assertEqual(c.exception.razon, "bad_request")
        with self.assertRaises(ErrorChat):
            conversion.hash_conocido(pb.Hash(type=conversion.SHA3_TIPO, value=b"\x00" * 33))
        self.assertIsNone(conversion.hash_conocido(pb.Hash(type=b"zz", value=b"1")))


class TestPartes(unittest.TestCase):
    def test_parte_sin_kind_es_bad_request(self):
        with self.assertRaises(ErrorChat) as c:
            conversion.partes_a_json([pb.ContentPart()])
        self.assertEqual(c.exception.razon, "bad_request")

    def test_texto_a_json(self):
        partes = conversion.partes_a_json([pb.ContentPart(text=pb.TextContent(text="a"))])
        self.assertEqual(partes, [{"tipo": "texto", "texto": "a"}])

    def test_estados_pb(self):
        m = conversion.mensaje_a_pb(
            {"message_id": "a", "sequence": 2, "status": "FAILED", "error": "x", "reply_to": "u",
             "metadata": {"k": "v"}, "conversation_id": None, "content": []}
        )
        self.assertEqual(m.status, pb.MESSAGE_STATUS_FAILED)
        self.assertEqual(m.error, "x")
        self.assertEqual(m.reply_to_message_id, "u")
        self.assertEqual(dict(m.metadata), {"k": "v"})
        self.assertFalse(m.HasField("conversation_id"))
        self.assertFalse(m.HasField("idempotency_key"))


if __name__ == "__main__":
    unittest.main()


class TestCorrector(unittest.TestCase):
    def test_se_pasan_como_mucho_32_correcciones(self):
        req = pb.SendMessageRequest(
            content=[pb.ContentPart(text=pb.TextContent(text="the cat"))],
            corrector=pb.CorrectorState(
                enabled=True,
                corrections=[pb.Correction(original=f"teh{i}", replacement=f"the{i}") for i in range(40)],
            ),
        )
        cuerpo = conversion.cuerpo_envio(req)
        self.assertTrue(cuerpo["corrector"]["enabled"])
        self.assertEqual(len(cuerpo["corrector"]["corrections"]), 32)
        self.assertEqual(cuerpo["corrector"]["corrections"][31], {"original": "teh31", "replacement": "the31"})
