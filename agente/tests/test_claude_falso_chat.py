"""Modo chat del Claude falso (docker/pruebas/claude-falso.py): lee la instantanea y emite stream-json valido.
Ejecutar desde agente/:  python3 -m unittest discover -s tests"""
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

FALSO = Path(__file__).resolve().parents[2] / "docker" / "pruebas" / "claude-falso.py"


class ChatFalsoTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.raiz = Path(self.tmp.name)
        (self.raiz / "workspace").mkdir()
        self.dir = self.raiz / "encargos" / "chat-7"
        self.dir.mkdir(parents=True)
        (self.dir / "LEEME.json").write_text(json.dumps({"hoy": "2026-11-05"}))
        (self.dir / "perfiles.json").write_text(json.dumps([
            {"id": 3, "nombre": "Marta", "activo": True}, {"id": 4, "nombre": "Javier", "activo": True}, {"id": 5, "nombre": "Baja", "activo": False}]))

    def tearDown(self):
        self.tmp.cleanup()

    def conversacion(self, texto, fotos=()):
        (self.dir / "conversacion.json").write_text(json.dumps({"mensajes": [{"sequence": 1, "de": "persona", "texto": texto, "es_la_pregunta": True, "fotos": list(fotos)}]}))

    def ejecutar(self, modo=None, prompt="Turno de chat 7. Usa la skill chat-nutricion"):
        modo and (self.raiz / "modo-falso").write_text(modo)
        return subprocess.run([sys.executable, str(FALSO), "-p", prompt, "--output-format", "stream-json", "--verbose"],
                              env={**os.environ, "AGENTE_CARPETA": str(self.raiz)}, capture_output=True, text=True, timeout=30)

    def eventos(self, r):
        return [json.loads(l) for l in r.stdout.splitlines() if l.strip()]

    def test_respuesta_normal_con_razonamiento_texto_y_formulario(self):
        self.conversacion("¿Qué ceno?")
        r = self.ejecutar()
        self.assertEqual(r.returncode, 0, r.stderr)
        ev = self.eventos(r)
        bloques = [b for e in ev if e["type"] == "assistant" for b in e["message"]["content"]]
        self.assertTrue(any(b["type"] == "thinking" for b in bloques))
        self.assertTrue(any(b["type"] == "tool_use" and b["name"] == "Read" for b in bloques))
        texto_final = [b["text"] for b in bloques if b["type"] == "text"][-1]
        self.assertIn("2 perfiles (Marta, Javier)", texto_final)
        self.assertEqual(texto_final.count("```keyboard"), 1)
        self.assertEqual(texto_final.count("data-say"), 2)
        self.assertEqual(ev[-1]["type"], "result")
        self.assertFalse((self.raiz / "workspace" / "chat-acciones-7.json").exists(), "sin pedirlo no propone acciones")

    def test_si_piden_registrar_con_foto_propone_la_accion_con_el_hash(self):
        self.conversacion("registra esto", [{"foto_hash": "a" * 64, "archivo": "fotos/a.png", "mime": "image/png", "nota": None}])
        r = self.ejecutar()
        self.assertEqual(r.returncode, 0, r.stderr)
        acciones = json.loads((self.raiz / "workspace" / "chat-acciones-7.json").read_text())["acciones"]
        self.assertEqual(len(acciones), 1)
        self.assertEqual({k: acciones[0][k] for k in ("tipo", "perfil", "fecha", "momento", "foto_hash")},
                         {"tipo": "registrar_comida", "perfil": 3, "fecha": "2026-11-05", "momento": "comida", "foto_hash": "a" * 64})
        leidas = [b["input"]["file_path"] for e in self.eventos(r) if e["type"] == "assistant" for b in e["message"]["content"] if b["type"] == "tool_use"]
        self.assertIn("encargos/chat-7/fotos/a.png", leidas, "mira la foto con Read")

    def test_modo_invalida_anade_una_accion_imposible(self):
        self.conversacion("registra lentejas")
        r = self.ejecutar("invalida")
        acciones = json.loads((self.raiz / "workspace" / "chat-acciones-7.json").read_text())["acciones"]
        self.assertEqual([a["perfil"] for a in acciones], [3, "00000000-0000-7000-8000-000000000000"])

    def test_modo_limite_y_error(self):
        self.conversacion("hola")
        r = self.ejecutar("limite")
        self.assertEqual(r.returncode, 1)
        self.assertIn("hit your session limit", r.stdout)
        r = self.ejecutar("error")
        self.assertEqual(r.returncode, 1)
        self.assertIn("Error interno", r.stderr)

    def test_un_prompt_que_no_es_de_chat_ni_de_encargo_no_se_confunde(self):
        r = self.ejecutar(prompt="Hola")
        self.assertEqual(r.returncode, 0)
        self.assertIn("Solo atiendo", r.stdout)


if __name__ == "__main__":
    unittest.main()
