"""start.sh corre como init de un microVM minimo: no hay /dev/fd, asi que no puede usar sustitucion de procesos ni here-strings.

Un `read ... < <(comando)` hizo que el servicio terminara con «/dev/fd/63: No such file or directory» justo despues de encontrar la
base de datos: el init moria (kernel panic) y el nodo lo reiniciaba en bucle.
"""
import re
import unittest
from pathlib import Path

START = Path(__file__).resolve().parents[2] / "docker" / "servicio" / "start.sh"


class StartShTest(unittest.TestCase):
    def lineas(self):
        for n, linea in enumerate(START.read_text().splitlines(), 1):
            if not linea.lstrip().startswith("#"):
                yield n, linea

    def test_sin_sustitucion_de_procesos(self):
        malas = [n for n, l in self.lineas() if re.search(r"[<>]\(", l) and not re.search(r"\$\(\(", l)]
        self.assertEqual(malas, [], "usa $(...) o ficheros temporales, no <(...) ni >(...)")

    def test_sin_here_strings(self):
        self.assertEqual([n for n, l in self.lineas() if "<<<" in l], [])

    def test_sin_dev_fd(self):
        self.assertEqual([n for n, l in self.lineas() if "/dev/fd" in l], [])


if __name__ == "__main__":
    unittest.main()
