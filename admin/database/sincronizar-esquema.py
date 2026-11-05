#!/usr/bin/env python3
"""Copia database/esquema.json al `formal` de la red «postgres» de los dos service.json.

    python3 admin/database/sincronizar-esquema.py            # reescribe los dos service.json
    python3 admin/database/sincronizar-esquema.py --comprobar  # no escribe; sale con 1 si alguno no esta al dia

El formal resultante es el de config/nutrition.php (db.engine, db.schema y las db.table.* de esquema.json) mas
db.cluster=${NUTRITION_DB_CLUSTER}. Solo se tocan `tags` y `formal` de la entrada de red de la base de datos: el resto del
fichero (prose, protocol_stack, formato) se conserva tal cual. El formal tiene que ser identico byte a byte en los dos.
"""
import json
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
ESQUEMA = RAIZ / "admin" / "database" / "esquema.json"
SERVICIOS = [
    RAIZ / ".service" / "service.json",
    RAIZ / "dependencies" / "postgres" / ".service" / "service.json",
]
TAG = "postgres"
FIJAS = {"db.engine": "postgresql", "db.schema": "personal-nutrition"}
CLUSTER = {"db.cluster": "${NUTRITION_DB_CLUSTER}"}

# tags (el antiguo «postgres:personal-nutrition» tambien) + prose + el objeto formal, hasta su llave de cierre
BLOQUE = re.compile(
    r'(?P<tags>"tags": \["postgres(?::personal-nutrition)?"\],\n)'
    r'(?P<prose>\s*"prose": "(?:[^"\\]|\\.)*",\n)'
    r'(?P<sangria>[ \t]*)"formal": \{\n.*?\n(?P=sangria)\}',
    re.S,
)


def formal():
    esquema = json.loads(ESQUEMA.read_text(encoding="utf-8"))
    if not esquema or not all(k.startswith("db.table.") and isinstance(v, str) for k, v in esquema.items()):
        sys.exit(f"{ESQUEMA}: tiene que ser un objeto {{'db.table.x': '...'}}")
    for k, v in esquema.items():
        if "\n" in v or "${" in v:
            sys.exit(f"{k}: un valor del formal no puede llevar saltos de linea ni '${{'")
    return dict(sorted({**FIJAS, **esquema, **CLUSTER}.items()))


def reescribir(texto, datos):
    def cambia(m):
        s = m.group("sangria")
        lineas = ",\n".join(f"{s}    {json.dumps(k)}: {json.dumps(v, ensure_ascii=False)}" for k, v in datos.items())
        return f'"tags": ["{TAG}"],\n{m.group("prose")}{s}"formal": {{\n{lineas}\n{s}}}'

    nuevo, n = BLOQUE.subn(cambia, texto)
    if n != 1:
        sys.exit("no se encontro exactamente una entrada de red de la base de datos (tags postgres + prose + formal)")
    return nuevo


def main():
    comprobar = "--comprobar" in sys.argv[1:]
    datos = formal()
    desfasados = 0
    for ruta in SERVICIOS:
        if not ruta.is_file():
            print(f"{ruta}: no existe, se omite")
            continue
        texto = ruta.read_text(encoding="utf-8")
        nuevo = reescribir(texto, datos)
        json.loads(nuevo)  # tiene que seguir siendo JSON valido
        if nuevo == texto:
            print(f"{ruta}: al dia")
        elif comprobar:
            print(f"{ruta}: DESFASADO")
            desfasados += 1
        else:
            ruta.write_text(nuevo, encoding="utf-8")
            print(f"{ruta}: actualizado")
    sys.exit(1 if desfasados else 0)


if __name__ == "__main__":
    main()
