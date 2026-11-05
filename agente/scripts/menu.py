#!/usr/bin/env python3
"""Ayudante del agente para el menú semanal: comprueba el borrador y entrega el resultado.

Uso (desde la carpeta agente/):
  python scripts/menu.py validar  workspace/borrador-N.json --encargo encargos/encargo-N.json
  python scripts/menu.py entregar workspace/borrador-N.json --encargo encargos/encargo-N.json

`validar` imprime {"ok": true|false, "errores": [...]} y sale con 1 si hay errores. Es una autocomprobacion: la administracion
(Laravel) vuelve a validar la entrega con las mismas reglas (App\\Models\\Menu\\ValidaEntrega) y si no las cumple el menú queda
«fallido». Si cambias una regla, cambia las dos.

`entregar` valida; si todo esta bien, vuelca el consumo (consumos.volcar), anade al borrador el bloque `consumo`
({por_fase, totales, filas}) y `entrega.completo = true`, y lo escribe en el campo `salida` del encargo (siempre dentro de workspace/).
El agente no calcula dinero ni copia cifras de consumo: lo hace este script.

Reglas del contrato (version 3):
  - `entrega.version` = 3 (Laravel sigue leyendo las entregas v2 ya guardadas; las nuevas se validan como v3).
  - `dias`: una entrada por fecha de encargo.semana.fechas, en orden, con `comida`, `cena` e `individuales`.
  - `comida`/`cena`: `categorias` (lista no vacia de categorias validas) y `opciones` con entre `min` y `max` recetas; las dos van
    vacias si el hogar no cocina ese momento (minutos = null).
  - cada opcion: `titulo`, `categorias` (el mismo conjunto que la toma, sin importar el orden), `ingredientes` (con `nombre`),
    `duracion_previa_min` y `duracion_momento_min` (no mas que los minutos del hogar ese dia).
    Opcional: `ajustes`, lista de `{perfil_id, texto}` con un perfil del encargo (sin repetir) y texto no vacio.
  - `individuales`: una entrada por perfil del encargo (`perfil_id`) con `desayuno`, `almuerzo` y `merienda`. Cada toma es una sola
    version: `titulo`, `ingredientes` (con `nombre`), `duracion_previa_min` y `duracion_momento_min` (no mas que los minutos del
    hogar ese dia si hay dato; con null no se limita).
  - `explicaciones`: una por perfil del encargo, con `perfil_id` y `texto`.

Tareas (`encargo.tarea`, por defecto `generar`): `editar` entrega el menu completo, con las mismas reglas que `generar`.
`intercambiar` no lleva `dias` ni `explicaciones`, sino `cambios`: un objeto «AAAA-MM-DD/comida|cena» -> toma (`categorias` y
`opciones`, mismas reglas que una toma del menu) para tomas de la semana en las que el hogar cocina; puede ir vacio.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

RAIZ = Path(os.environ.get("CLAUDE_PROJECT_DIR") or Path(__file__).resolve().parent.parent)
sys.path.insert(0, str(Path(__file__).resolve().parent))

MOMENTOS = ("comida", "cena")
TOMAS_INDIVIDUALES = ("desayuno", "almuerzo", "merienda")


def _texto(valor) -> str:
    # Mismos caracteres que trim() de PHP (no recorta espacios Unicode como U+00A0).
    return valor.strip(" \t\n\r\0\x0b") if isinstance(valor, str) else ""


def _entero(valor) -> bool:
    return isinstance(valor, int) and not isinstance(valor, bool)


def _ids_de_perfil(entradas) -> list[str]:
    """`perfil_id` (UUID en texto) de cada entrada, ordenados; cadena vacia si falta o no es texto."""
    entradas = entradas if isinstance(entradas, list) else []
    ids = []
    for e in entradas:
        valor = e.get("perfil_id") if isinstance(e, dict) else None
        ids.append(valor if isinstance(valor, str) else "")
    return sorted(ids)


def errores_de_receta(o: dict, donde: str, minutos) -> list[str]:
    """Reglas comunes a una opcion de comida/cena y a una toma individual."""
    errores = []
    if not _texto(o.get("titulo")):
        errores.append(f"{donde}: falta el título.")
    ingredientes = o.get("ingredientes")
    if (not isinstance(ingredientes, list) or not ingredientes
            or any(not isinstance(i, dict) or not _texto(i.get("nombre")) for i in ingredientes)):
        errores.append(f"{donde}: «ingredientes» debe ser una lista no vacía y cada uno con «nombre».")
    for campo in ("duracion_previa_min", "duracion_momento_min"):
        if not _entero(o.get(campo)) or o[campo] < 0:
            errores.append(f"{donde}: «{campo}» debe ser un número entero de minutos (0 o más).")
    if minutos is not None and _entero(o.get("duracion_momento_min")) and o["duracion_momento_min"] > minutos:
        errores.append(f"{donde}: tarda {o['duracion_momento_min']} min al momento y el hogar solo tiene {minutos}.")
    return errores


def errores_de_ajustes(ajustes, donde: str, perfiles: list[str]) -> list[str]:
    """`ajustes` es opcional (None = no hay): lista de `{perfil_id, texto}` con perfiles del encargo, sin repetir y con texto."""
    if ajustes is None:
        return []
    if not isinstance(ajustes, list):
        return [f"{donde}: «ajustes» debe ser una lista de objetos con «perfil_id» y «texto»."]
    errores, vistos = [], []
    for a in ajustes:
        id_ = a.get("perfil_id") if isinstance(a, dict) else None
        if not isinstance(id_, str) or id_ not in perfiles:
            errores.append(f"{donde}: cada ajuste necesita un «perfil_id» que sea un perfil del encargo (perfiles {', '.join(map(str, sorted(perfiles)))}).")
        elif id_ in vistos:
            errores.append(f"{donde}: el perfil {id_} tiene más de un ajuste.")
        else:
            vistos.append(id_)
        if not isinstance(a, dict) or not _texto(a.get("texto")):
            errores.append(f"{donde}: cada ajuste necesita «texto».")
    return errores


def errores_de_opcion(o, donde: str, minutos, categorias: list[str], cats_toma, perfiles: list[str] | None = None) -> list[str]:
    if not isinstance(o, dict):
        return [f"{donde}: la opción tiene que ser un objeto."]
    errores = errores_de_receta(o, donde, minutos)
    cats = o.get("categorias")
    if not isinstance(cats, list) or not cats or any(c not in categorias for c in cats):
        errores.append(f"{donde}: «categorias» debe ser una lista no vacía de categorías válidas.")
    elif cats_toma is not None and set(cats) != set(cats_toma):
        errores.append(f"{donde}: «categorias» debe tener las mismas categorías que la toma ({', '.join(cats_toma)}).")
    errores += errores_de_ajustes(o.get("ajustes"), donde, perfiles or [])
    return errores


def errores_de_individuales(individuales, fecha: str, tiempo: dict, perfiles: list[str]) -> list[str]:
    if not isinstance(individuales, list):
        return [f"{fecha} · individuales: falta la lista «individuales»."]
    errores = []
    if _ids_de_perfil(individuales) != sorted(perfiles):
        errores.append(f"{fecha} · individuales: tiene que haber exactamente una entrada por perfil (perfiles {', '.join(map(str, sorted(perfiles)))}).")
    for ind in individuales:
        if not isinstance(ind, dict):
            errores.append(f"{fecha} · individuales: cada entrada tiene que ser un objeto.")
            continue
        for momento in TOMAS_INDIVIDUALES:
            donde = f"{fecha} · perfil {ind.get('perfil_id', '?')} · {momento}"
            toma = ind.get(momento)
            if not isinstance(toma, dict):
                errores.append(f"{donde}: falta la toma.")
                continue
            errores += errores_de_receta(toma, donde, tiempo.get(f"minutos_{momento}"))
    return errores


def errores_de_toma(toma, donde: str, minutos, categorias: list[str], minimo: int, maximo: int, perfiles: list[str]) -> list[str]:
    """Una toma de comida o cena: `categorias` y entre `minimo` y `maximo` `opciones` (vacias las dos si el hogar no cocina: minutos = None).
    La usan la entrega completa (generar y editar) y cada toma de un intercambio."""
    opciones = toma.get("opciones") if isinstance(toma, dict) else None
    if not isinstance(opciones, list):
        return [f"{donde}: falta la lista «opciones»."]
    errores = []
    cats_toma = toma.get("categorias")
    if not isinstance(cats_toma, list):
        errores.append(f"{donde}: falta la lista «categorias».")
        cats_toma = None
    elif minutos is None and cats_toma:
        errores.append(f"{donde}: el hogar no cocina en ese momento, «categorias» debe ir vacío.")
    elif minutos is not None and (not cats_toma or any(c not in categorias for c in cats_toma)):
        errores.append(f"{donde}: «categorias» debe ser una lista no vacía de categorías válidas.")
    if minutos is None and opciones:
        errores.append(f"{donde}: el hogar no cocina en ese momento, «opciones» debe ir vacío.")
    elif minutos is not None and not minimo <= len(opciones) <= maximo:
        errores.append(f"{donde}: hay {len(opciones)} opciones y tienen que ser entre {minimo} y {maximo}.")
    for i, o in enumerate(opciones, 1):
        errores += errores_de_opcion(o, f"{donde} · opción {i}", minutos, categorias, cats_toma, perfiles)
    return errores


def errores_de_intercambio(datos, encargo: dict) -> list[str]:
    """Entrega de un intercambio: `cambios` es un objeto «fecha/momento» (comida o cena de una fecha de la semana en la que el hogar
    cocina) -> toma completa, con las mismas reglas que una toma del menu. Puede ir vacio (nada que cambiar). No lleva `dias`."""
    fechas = encargo.get("semana", {}).get("fechas", [])
    opciones_por_comida = encargo.get("opciones_por_comida", {})
    minimo, maximo = int(opciones_por_comida.get("min", 2)), int(opciones_por_comida.get("max", 3))
    perfiles = [str(p["id"]) for p in encargo.get("perfiles", [])]
    categorias = list(encargo.get("categorias_validas", []))
    tiempos = {t["fecha"]: t for t in encargo.get("hogar", {}).get("tiempos", [])}

    errores = []
    entrega = datos.get("entrega") if isinstance(datos, dict) else None
    if not (isinstance(entrega, dict) and entrega.get("version") == 3 and _entero(entrega.get("version"))):
        errores.append("La entrega tiene que ser de la versión 3 («entrega.version»: 3).")
    cambios = datos.get("cambios") if isinstance(datos, dict) else None
    if cambios == []:  # el JSON de PHP serializa un objeto vacio como []
        cambios = {}
    if not isinstance(cambios, dict):
        return errores + ["Falta el objeto «cambios» (clave «AAAA-MM-DD/comida» o «AAAA-MM-DD/cena»; vacío si no hay nada que cambiar)."]
    for clave, toma in cambios.items():
        fecha, _, momento = str(clave).partition("/")
        if fecha not in fechas or momento not in MOMENTOS:
            errores.append(f"{clave}: la clave tiene que ser «fecha/comida» o «fecha/cena» con una fecha de la semana del encargo.")
            continue
        minutos = tiempos.get(fecha, {}).get(f"minutos_{momento}")
        if minutos is None:
            errores.append(f"{clave}: el hogar no cocina en ese momento, no se puede cambiar.")
            continue
        errores += errores_de_toma(toma, clave, minutos, categorias, minimo, maximo, perfiles)
    return errores


def validar_entrega(datos, encargo: dict) -> list[str]:
    if encargo.get("tarea", "generar") == "intercambiar":
        return errores_de_intercambio(datos, encargo)
    fechas = encargo.get("semana", {}).get("fechas", [])
    opciones_por_comida = encargo.get("opciones_por_comida", {})
    minimo, maximo = int(opciones_por_comida.get("min", 2)), int(opciones_por_comida.get("max", 3))
    perfiles = [str(p["id"]) for p in encargo.get("perfiles", [])]
    categorias = list(encargo.get("categorias_validas", []))
    tiempos = {t["fecha"]: t for t in encargo.get("hogar", {}).get("tiempos", [])}

    dias = datos.get("dias") if isinstance(datos, dict) else None
    if not isinstance(dias, list):
        return ["Falta la lista «dias» en la entrega."]
    errores = []
    entrega = datos.get("entrega")
    if not (isinstance(entrega, dict) and entrega.get("version") == 3 and _entero(entrega.get("version"))):
        errores.append("La entrega tiene que ser de la versión 3 («entrega.version»: 3).")
    if [d.get("fecha") if isinstance(d, dict) else None for d in dias] != fechas:
        errores.append(f"Las fechas de «dias» no coinciden con la semana del encargo ({', '.join(fechas)}).")

    for d in dias:
        d = d if isinstance(d, dict) else {}
        fecha = d.get("fecha", "?")
        for momento in MOMENTOS:
            errores += errores_de_toma(d.get(momento), f"{fecha} · {momento}", tiempos.get(fecha, {}).get(f"minutos_{momento}"), categorias, minimo, maximo, perfiles)
        errores += errores_de_individuales(d.get("individuales"), fecha, tiempos.get(fecha, {}), perfiles)

    explicaciones = datos.get("explicaciones")
    if _ids_de_perfil(explicaciones) != sorted(perfiles):
        errores.append(f"Tiene que haber exactamente una explicación por perfil en «explicaciones» (perfiles {', '.join(map(str, sorted(perfiles)))}).")
    if any(not isinstance(x, dict) or not _texto(x.get("texto")) for x in (explicaciones if isinstance(explicaciones, list) else [])):
        errores.append("Cada explicación necesita «texto».")
    return errores


def _leer_json(ruta: Path, que: str):
    try:
        return json.loads(ruta.read_text(encoding="utf-8"))
    except OSError as e:
        sys.exit(_fallo([f"No se puede leer {que} ({ruta}): {e.strerror}."]))
    except ValueError as e:
        sys.exit(_fallo([f"{que} ({ruta}) no es un JSON válido: {e}."]))


def _fallo(errores: list[str]) -> int:
    print(json.dumps({"ok": False, "errores": errores}, ensure_ascii=False))
    return 1


def _ruta(texto: str) -> Path:
    p = Path(texto)
    return p if p.is_absolute() else RAIZ / p


def salida_segura(encargo: dict) -> Path:
    """La ruta `salida` del encargo, comprobando que cae dentro de workspace/."""
    destino = _ruta(str(encargo.get("salida", ""))).resolve()
    if not encargo.get("salida") or (RAIZ / "workspace").resolve() not in destino.parents:
        sys.exit(_fallo([f"La ruta de salida del encargo ({encargo.get('salida')!r}) no está dentro de workspace/."]))
    return destino


def entregar(borrador: dict, encargo: dict) -> Path:
    import consumos

    destino = salida_segura(encargo)
    encargo_id = encargo.get("encargo_id")
    consumos.volcar()  # lo gastado hasta ahora se queda en su fase
    consumos.fijar_fase("terminada", encargo_id)
    conn = consumos.conectar()
    try:  # respuestas anteriores a que el agente fijara la ejecucion: son de este encargo
        conn.execute("UPDATE consumos SET ejecucion_id=? WHERE ejecucion_id IS NULL", (encargo_id,))
        conn.commit()
    finally:
        conn.close()

    final = dict(borrador)
    final["entrega"] = {**(borrador.get("entrega") or {}), "completo": True}
    final["consumo"] = consumos.resumen(encargo_id)
    destino.parent.mkdir(parents=True, exist_ok=True)
    temporal = destino.with_suffix(destino.suffix + ".tmp")
    temporal.write_text(json.dumps(final, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(temporal, destino)  # escritura atomica: Laravel nunca ve un fichero a medias
    return destino


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("orden", choices=["validar", "entregar"])
    p.add_argument("borrador")
    p.add_argument("--encargo", required=True)
    a = p.parse_args()

    encargo = _leer_json(_ruta(a.encargo), "el encargo")
    borrador = _leer_json(_ruta(a.borrador), "el borrador")
    errores = validar_entrega(borrador, encargo)
    if errores:
        return _fallo(errores)
    if a.orden == "validar":
        print(json.dumps({"ok": True, "errores": []}, ensure_ascii=False))
        return 0
    destino = entregar(borrador, encargo)
    print(json.dumps({"ok": True, "entregado": str(destino.relative_to(RAIZ))}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
