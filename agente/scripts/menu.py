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

Reglas del contrato (version 1):
  - `dias`: una entrada por fecha de encargo.semana.fechas, en orden, con `comida` y `cena`.
  - `comida`/`cena`: `opciones` con entre `min` y `max` recetas; vacio si el hogar no cocina ese momento (minutos = null).
  - cada opcion: `titulo`, `categorias` (de encargo.categorias_validas), `ingredientes` (con `nombre`), `duracion_previa_min`,
    `duracion_momento_min` (no mas que los minutos del hogar ese dia) y `adaptaciones`: una por cada perfil del encargo,
    con `perfil_id` y `cambios`.
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


def _texto(valor) -> str:
    return valor.strip() if isinstance(valor, str) else ""


def _entero(valor) -> bool:
    return isinstance(valor, int) and not isinstance(valor, bool)


def errores_de_opcion(o, donde: str, minutos, perfiles: list[int], categorias: list[str]) -> list[str]:
    if not isinstance(o, dict):
        return [f"{donde}: la opción tiene que ser un objeto."]
    errores = []
    if not _texto(o.get("titulo")):
        errores.append(f"{donde}: falta el título.")
    cats = o.get("categorias")
    if not isinstance(cats, list) or not cats or any(c not in categorias for c in cats):
        errores.append(f"{donde}: «categorias» debe ser una lista no vacía de categorías válidas.")
    ingredientes = o.get("ingredientes")
    if (not isinstance(ingredientes, list) or not ingredientes
            or any(not isinstance(i, dict) or not _texto(i.get("nombre")) for i in ingredientes)):
        errores.append(f"{donde}: «ingredientes» debe ser una lista no vacía y cada uno con «nombre».")
    for campo in ("duracion_previa_min", "duracion_momento_min"):
        if not _entero(o.get(campo)) or o[campo] < 0:
            errores.append(f"{donde}: «{campo}» debe ser un número entero de minutos (0 o más).")
    if minutos is not None and _entero(o.get("duracion_momento_min")) and o["duracion_momento_min"] > minutos:
        errores.append(f"{donde}: tarda {o['duracion_momento_min']} min al momento y el hogar solo tiene {minutos}.")

    adaptaciones = o.get("adaptaciones")
    adaptaciones = adaptaciones if isinstance(adaptaciones, list) else []
    adaptados = sorted(int(a.get("perfil_id")) for a in adaptaciones if isinstance(a, dict) and str(a.get("perfil_id", "")).lstrip("-").isdigit())
    if adaptados != sorted(perfiles):
        errores.append(f"{donde}: tiene que haber exactamente una adaptación por perfil (perfiles {', '.join(map(str, sorted(perfiles)))}).")
    if any(not isinstance(a, dict) or not _texto(a.get("cambios")) for a in adaptaciones):
        errores.append(f"{donde}: cada adaptación necesita «cambios» (puede ser «Sin cambios»).")
    return errores


def validar_entrega(datos, encargo: dict) -> list[str]:
    fechas = encargo.get("semana", {}).get("fechas", [])
    opciones_por_comida = encargo.get("opciones_por_comida", {})
    minimo, maximo = int(opciones_por_comida.get("min", 2)), int(opciones_por_comida.get("max", 3))
    perfiles = [int(p["id"]) for p in encargo.get("perfiles", [])]
    categorias = list(encargo.get("categorias_validas", []))
    tiempos = {t["fecha"]: t for t in encargo.get("hogar", {}).get("tiempos", [])}

    dias = datos.get("dias") if isinstance(datos, dict) else None
    if not isinstance(dias, list):
        return ["Falta la lista «dias» en la entrega."]
    errores = []
    if [d.get("fecha") if isinstance(d, dict) else None for d in dias] != fechas:
        errores.append(f"Las fechas de «dias» no coinciden con la semana del encargo ({', '.join(fechas)}).")

    for d in dias:
        d = d if isinstance(d, dict) else {}
        fecha = d.get("fecha", "?")
        for momento in MOMENTOS:
            donde = f"{fecha} · {momento}"
            bloque = d.get(momento)
            opciones = bloque.get("opciones") if isinstance(bloque, dict) else None
            minutos = tiempos.get(fecha, {}).get(f"minutos_{momento}")
            if not isinstance(opciones, list):
                errores.append(f"{donde}: falta la lista «opciones».")
                continue
            if minutos is None and opciones:
                errores.append(f"{donde}: el hogar no cocina en ese momento, «opciones» debe ir vacío.")
            elif minutos is not None and not minimo <= len(opciones) <= maximo:
                errores.append(f"{donde}: hay {len(opciones)} opciones y tienen que ser entre {minimo} y {maximo}.")
            for i, o in enumerate(opciones, 1):
                errores += errores_de_opcion(o, f"{donde} · opción {i}", minutos, perfiles, categorias)
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
