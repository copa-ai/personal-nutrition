"""consumos.py: la ejecucion es el id del encargo, que es un UUID (antes un entero)."""
import importlib.util
import unittest
from pathlib import Path

RUTA = Path(__file__).resolve().parent.parent / "scripts" / "consumos.py"
UUID = "0192f3c4-7a10-7b3e-9c1d-5e2f8a4b6d10"


def cargar():
    especificacion = importlib.util.spec_from_file_location("consumos_bajo_prueba", RUTA)
    modulo = importlib.util.module_from_spec(especificacion)
    especificacion.loader.exec_module(modulo)
    return modulo


class EjecucionTest(unittest.TestCase):
    def test_un_uuid_se_deja_como_texto_y_un_entero_antiguo_sigue_siendo_entero(self):
        consumos = cargar()

        self.assertEqual(consumos._ejecucion(UUID), UUID)
        self.assertEqual(consumos._ejecucion("7"), 7)

    def test_la_linea_de_ordenes_acepta_el_uuid_del_encargo(self):
        consumos = cargar()
        ayuda = consumos.__doc__ or ""

        self.assertIn("--ejecucion ID", ayuda)
        analizador = consumos.argparse.ArgumentParser()
        analizador.add_argument("--ejecucion", type=consumos._ejecucion)
        self.assertEqual(analizador.parse_args(["--ejecucion", UUID]).ejecucion, UUID)


if __name__ == "__main__":
    unittest.main()
