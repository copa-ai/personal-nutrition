"""Pruebas de scripts/menu.py. Ejecutar desde agente/:  python3 -m unittest discover -s tests"""
import copy
import importlib
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parent.parent / "scripts"
FECHAS = [f"2026-11-0{d}" for d in range(2, 9)]


def encargo_base() -> dict:
    return {
        "formato": "encargo-menu", "version": 1, "encargo_id": 7, "salida": "workspace/resultado-7.json",
        "semana": {"inicio": FECHAS[0], "dias": 7, "fechas": FECHAS},
        "categorias_validas": ["verdura", "pescado", "cereal"],
        "opciones_por_comida": {"min": 2, "max": 3},
        "hogar": {"nombre": "Casa", "tiempos": [
            {"fecha": f, "dia_semana": i + 1, "minutos_comida": 30, "minutos_cena": 45} for i, f in enumerate(FECHAS)]},
        "perfiles": [{"id": 1, "nombre": "Marta"}, {"id": 2, "nombre": "Javier"}],
    }


def opcion(titulo: str, minutos: int) -> dict:
    return {
        "titulo": titulo, "categorias": ["verdura"], "ingredientes": [{"nombre": "tomate", "cantidad": 200, "unidad": "g"}],
        "duracion_previa_min": 0, "duracion_momento_min": minutos,
        "adaptaciones": [{"perfil_id": 1, "cambios": "Sin cambios."}, {"perfil_id": 2, "cambios": "Doble ración."}],
    }


def borrador_valido(encargo: dict) -> dict:
    dias = []
    for t in encargo["hogar"]["tiempos"]:
        dias.append({
            "fecha": t["fecha"],
            "comida": {"opciones": [] if t["minutos_comida"] is None else [opcion("Ensalada", 30), opcion("Crema", 10)]},
            "cena": {"opciones": [] if t["minutos_cena"] is None else [opcion("Tortilla", 20), opcion("Pescado", 45)]},
        })
    return {"dias": dias}


class ValidarEntrega(unittest.TestCase):
    def setUp(self):
        os.environ["CLAUDE_PROJECT_DIR"] = tempfile.mkdtemp()
        sys.path.insert(0, str(SCRIPTS))
        self.menu = importlib.reload(importlib.import_module("menu"))
        self.encargo = encargo_base()
        self.datos = borrador_valido(self.encargo)

    def test_una_entrega_valida_no_da_errores(self):
        self.assertEqual([], self.menu.validar_entrega(self.datos, self.encargo))

    def test_sin_lista_de_dias(self):
        self.assertEqual(["Falta la lista «dias» en la entrega."], self.menu.validar_entrega({}, self.encargo))

    def test_las_fechas_tienen_que_ser_las_de_la_semana(self):
        self.datos["dias"][6]["fecha"] = "2026-11-09"
        self.assertIn("no coinciden con la semana", self.menu.validar_entrega(self.datos, self.encargo)[0])

    def test_una_adaptacion_por_perfil(self):
        self.datos["dias"][2]["cena"]["opciones"][1]["adaptaciones"] = []
        errores = self.menu.validar_entrega(self.datos, self.encargo)
        self.assertEqual(1, len(errores))
        self.assertIn("2026-11-04 · cena · opción 2", errores[0])

    def test_adaptacion_duplicada_o_sin_cambios(self):
        o = self.datos["dias"][0]["comida"]["opciones"][0]
        o["adaptaciones"] = [{"perfil_id": 1, "cambios": "x"}, {"perfil_id": 1, "cambios": "y"}]
        self.assertIn("una adaptación por perfil", self.menu.validar_entrega(self.datos, self.encargo)[0])
        o["adaptaciones"] = [{"perfil_id": 1, "cambios": "  "}, {"perfil_id": 2, "cambios": "ok"}]
        self.assertIn("necesita «cambios»", self.menu.validar_entrega(self.datos, self.encargo)[0])

    def test_no_puede_tardar_mas_que_el_tiempo_del_hogar(self):
        self.datos["dias"][0]["comida"]["opciones"][0]["duracion_momento_min"] = 31
        errores = self.menu.validar_entrega(self.datos, self.encargo)
        self.assertEqual(1, len(errores))
        self.assertIn("tarda 31 min", errores[0])

    def test_numero_de_opciones(self):
        uno = copy.deepcopy(self.datos)
        uno["dias"][0]["comida"]["opciones"] = uno["dias"][0]["comida"]["opciones"][:1]
        self.assertIn("hay 1 opciones", self.menu.validar_entrega(uno, self.encargo)[0])
        cuatro = copy.deepcopy(self.datos)
        o = cuatro["dias"][0]["cena"]["opciones"]
        cuatro["dias"][0]["cena"]["opciones"] = o + o
        self.assertIn("hay 4 opciones", self.menu.validar_entrega(cuatro, self.encargo)[0])

    def test_si_no_se_cocina_no_hay_opciones(self):
        self.encargo["hogar"]["tiempos"][2]["minutos_cena"] = None
        self.datos["dias"][2]["cena"]["opciones"] = []
        self.assertEqual([], self.menu.validar_entrega(self.datos, self.encargo))
        self.datos["dias"][2]["cena"]["opciones"] = [opcion("A", 10), opcion("B", 10)]
        self.assertIn("no cocina en ese momento", self.menu.validar_entrega(self.datos, self.encargo)[0])

    def test_categorias_e_ingredientes(self):
        o = self.datos["dias"][0]["comida"]["opciones"][0]
        o["categorias"] = ["marciano"]
        o["ingredientes"] = []
        self.assertEqual(2, len(self.menu.validar_entrega(self.datos, self.encargo)))

    def test_duraciones_enteras_y_no_negativas(self):
        o = self.datos["dias"][0]["comida"]["opciones"][0]
        o["duracion_previa_min"] = -5
        o["duracion_momento_min"] = "20"
        self.assertEqual(2, len(self.menu.validar_entrega(self.datos, self.encargo)))

    def test_una_opcion_que_no_es_objeto_no_rompe(self):
        self.datos["dias"][0]["comida"]["opciones"][0] = "Ensalada"
        self.assertIn("tiene que ser un objeto", self.menu.validar_entrega(self.datos, self.encargo)[0])


class Entregar(unittest.TestCase):
    def setUp(self):
        self.raiz = Path(tempfile.mkdtemp())
        os.environ["CLAUDE_PROJECT_DIR"] = str(self.raiz)
        sys.path.insert(0, str(SCRIPTS))
        self.consumos = importlib.reload(importlib.import_module("consumos"))
        self.menu = importlib.reload(importlib.import_module("menu"))
        self.encargo = encargo_base()
        (self.raiz / "encargos").mkdir()
        (self.raiz / "workspace").mkdir()
        (self.raiz / "encargos/encargo-7.json").write_text(json.dumps(self.encargo), encoding="utf-8")
        (self.raiz / "workspace/borrador-7.json").write_text(json.dumps(borrador_valido(self.encargo)), encoding="utf-8")

    def apuntar(self, mensaje: str, fase, ejecucion):
        conn = self.consumos.conectar()
        conn.execute("INSERT INTO consumos (mensaje_id, ejecucion_id, fase, agente, modelo, fecha, input_tokens, output_tokens)"
                     " VALUES (?,?,?,?,?,?,?,?)", (mensaje, ejecucion, fase, "principal", "claude-sonnet-5-5", "2026-11-01T10:00:00Z", 100, 50))
        conn.commit()
        conn.close()

    def entregar(self, borrador="workspace/borrador-7.json") -> int:
        sys.argv = ["menu.py", "entregar", borrador, "--encargo", "encargos/encargo-7.json"]
        return self.menu.main()

    def test_entrega_con_consumo_y_completo(self):
        self.apuntar("m1", "planificacion", 7)
        self.apuntar("m2", None, None)      # respuesta anterior a fijar la ejecucion: se atribuye a este encargo
        self.apuntar("m3", "planificacion", 99)  # otro encargo: no entra
        self.assertEqual(0, self.entregar())

        salida = json.loads((self.raiz / "workspace/resultado-7.json").read_text(encoding="utf-8"))
        self.assertEqual({"completo": True}, salida["entrega"])
        self.assertEqual(7, len(salida["dias"]))
        self.assertEqual(sorted(["m1", "m2"]), sorted(f["mensaje_id"] for f in salida["consumo"]["filas"]))
        self.assertEqual(2, salida["consumo"]["totales"]["respuestas"])
        self.assertEqual(200, salida["consumo"]["totales"]["input_tokens"])
        self.assertFalse((self.raiz / "workspace/resultado-7.json.tmp").exists())

    def test_una_entrega_invalida_no_escribe_nada(self):
        mal = borrador_valido(self.encargo)
        mal["dias"][0]["comida"]["opciones"][0]["adaptaciones"] = []
        (self.raiz / "workspace/borrador-7.json").write_text(json.dumps(mal), encoding="utf-8")
        self.assertEqual(1, self.entregar())
        self.assertFalse((self.raiz / "workspace/resultado-7.json").exists())

    def test_la_salida_tiene_que_estar_en_workspace(self):
        self.encargo["salida"] = "../fuera.json"
        (self.raiz / "encargos/encargo-7.json").write_text(json.dumps(self.encargo), encoding="utf-8")
        with self.assertRaises(SystemExit) as e:
            self.entregar()
        self.assertEqual(1, e.exception.code)
        self.assertFalse((self.raiz.parent / "fuera.json").exists())

    def test_un_borrador_ilegible_da_un_error_claro(self):
        (self.raiz / "workspace/borrador-7.json").write_text("{no es json", encoding="utf-8")
        with self.assertRaises(SystemExit) as e:
            self.entregar()
        self.assertEqual(1, e.exception.code)


if __name__ == "__main__":
    unittest.main()
