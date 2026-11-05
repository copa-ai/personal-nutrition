#!/usr/bin/env python3
"""Recuento de consumo (tokens y peticiones web) por ejecucion y fase.

El agente NO cuenta sus propios tokens: los cuenta este script leyendo el registro .jsonl que Claude Code guarda de
cada sesion (~/.claude/projects/<proyecto>/<sesion>.jsonl), donde cada respuesta del modelo lleva su `usage`.
Lo llama un hook de .claude/settings.json (Stop y SubagentStop) al terminar cada respuesta y cada subagente.

Uso:
  python scripts/consumos.py --hook                       (lo usa el hook; lee stdin)
  python scripts/consumos.py --archivo REGISTRO.jsonl
  python scripts/consumos.py resumen [--ejecucion ID]      (JSON: por_fase + totales)
  python scripts/consumos.py fase NOMBRE [--ejecucion ID]  (fija la fase actual: lo que se lea desde ahora se apunta a ella)

Reglas de conteo (no cambiarlas: son las que hacen que las cifras cuadren):
  - Una misma respuesta aparece en VARIAS lineas del registro (una por bloque): se agrupa por message.id y se queda
    el MAXIMO de cada contador.
  - Cada linea se lee una sola vez: la posicion leida de cada registro se guarda en base/consumos-lectura.json.
  - Solo se leen lineas completas (hasta el ultimo salto de linea).
  - Se suman tambien los registros de los subagentes (<sesion>/subagents/*.jsonl).
  - Busquedas/lecturas web: max(server_tool_use.web_search_requests, nº de tool_use WebSearch).
  - Insercion idempotente por mensaje_id (ON CONFLICT ... MAX): releer no duplica.
  - En modo --hook NUNCA falla ni escribe en pantalla: un error del contador no puede interrumpir el trabajo.

Donde apunta cada fila: a la ejecucion y fase de base/estado-actual.json ({"ejecucion_id": ID, "fase": "..."}).
Laravel (o el propio agente, via `fase`) lo escribe antes de empezar; sin el, la fila queda «sin ejecucion».

Adaptacion (ver SKILL/04-FILAMENT-REGISTRO-Y-COSTES.md): al final del trabajo, el agente copia `resumen` dentro del
JSON de entrega bajo la clave `consumo` ({"por_fase": [...], "totales": {...}, "filas": [...]}). `filas` trae cada respuesta con su
modelo: Laravel la guarda (consumos_menu) con la tarifa y el coste VIGENTES al registrarla y la pestaña «Consumo» suma esos
costes congelados. El agente nunca calcula dinero.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sqlite3
import sys
from pathlib import Path

RAIZ = Path(os.environ.get("CLAUDE_PROJECT_DIR") or Path(__file__).resolve().parent.parent)
BASE = RAIZ / "base"
DB = BASE / "consumos.sqlite"
LECTURA = BASE / "consumos-lectura.json"
ESTADO_ACTUAL = BASE / "estado-actual.json"

CAMPOS = ("input_tokens", "output_tokens", "cache_read", "cache_creation", "thinking_tokens", "web_search", "web_fetch")

ESQUEMA = """
CREATE TABLE IF NOT EXISTS consumos (
  id              INTEGER PRIMARY KEY,
  mensaje_id      TEXT NOT NULL UNIQUE,
  ejecucion_id    INTEGER,
  fase            TEXT,
  sesion          TEXT,
  agente          TEXT NOT NULL DEFAULT 'principal',
  modelo          TEXT,
  fecha           TEXT,
  input_tokens    INTEGER NOT NULL DEFAULT 0,
  output_tokens   INTEGER NOT NULL DEFAULT 0,
  cache_read      INTEGER NOT NULL DEFAULT 0,
  cache_creation  INTEGER NOT NULL DEFAULT 0,
  thinking_tokens INTEGER NOT NULL DEFAULT 0,
  web_search      INTEGER NOT NULL DEFAULT 0,
  web_fetch       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_cons_ejec ON consumos(ejecucion_id);
"""


def conectar() -> sqlite3.Connection:
    BASE.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.executescript(ESQUEMA)
    return conn


def leer_estado() -> dict:
    try:
        return json.loads(ESTADO_ACTUAL.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def _ejecucion(valor: str) -> int | str:
    """La ejecucion es el id del encargo: un UUID en texto (un entero, si es una anotacion antigua)."""
    return int(valor) if valor.isdigit() else valor


def fijar_fase(fase: str, ejecucion: int | str | None = None) -> dict:
    estado = leer_estado()
    estado["fase"] = fase
    if ejecucion is not None:
        estado["ejecucion_id"] = ejecucion
    BASE.mkdir(parents=True, exist_ok=True)
    ESTADO_ACTUAL.write_text(json.dumps(estado, ensure_ascii=False), encoding="utf-8")
    return estado


def _leer_posiciones() -> dict:
    try:
        return json.loads(LECTURA.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def archivos_de_sesion(transcript: str | None, extra: str | None = None) -> list[Path]:
    """El registro principal, los de sus subagentes y el que indique el hook."""
    salida = []
    if transcript:
        t = Path(transcript)
        salida.append(t)
        carpeta = t.with_suffix("") / "subagents"
        if carpeta.is_dir():
            salida += sorted(carpeta.glob("*.jsonl"))
    if extra:
        salida.append(Path(extra))
    vistos, unicos = set(), []
    for p in salida:
        if p.exists() and str(p.resolve()) not in vistos:
            vistos.add(str(p.resolve()))
            unicos.append(p)
    return unicos


def leer_nuevo(archivo: Path, posiciones: dict) -> list[dict]:
    """Respuestas del modelo escritas desde la ultima lectura (solo lineas completas), agrupadas por respuesta."""
    clave = str(archivo.resolve())
    inicio = posiciones.get(clave, 0)
    if archivo.stat().st_size < inicio:        # el registro se reescribio: desde el principio
        inicio = 0
    with open(archivo, "rb") as f:
        f.seek(inicio)
        datos = f.read()
    corte = datos.rfind(b"\n") + 1
    posiciones[clave] = inicio + corte
    agente = "principal" if archivo.parent.name != "subagents" else f"subagente:{archivo.stem}"
    respuestas: dict[str, dict] = {}
    for linea in datos[:corte].splitlines():
        try:
            d = json.loads(linea)
        except ValueError:
            continue
        if d.get("type") != "assistant":
            continue
        m = d.get("message") or {}
        mid = m.get("id") or d.get("requestId")
        uso = m.get("usage")
        if not mid or not uso:
            continue
        r = respuestas.setdefault(mid, {
            "mensaje_id": mid, "sesion": d.get("sessionId"), "agente": agente,
            "modelo": m.get("model"), "fecha": d.get("timestamp"),
            **{k: 0 for k in CAMPOS}, "_busquedas": set(), "_lecturas": set()})
        servidor = uso.get("server_tool_use") or {}
        detalle = uso.get("output_tokens_details") or {}
        for campo, valor in (("input_tokens", uso.get("input_tokens")),
                             ("output_tokens", uso.get("output_tokens")),
                             ("cache_read", uso.get("cache_read_input_tokens")),
                             ("cache_creation", uso.get("cache_creation_input_tokens")),
                             ("thinking_tokens", detalle.get("thinking_tokens")),
                             ("web_search", servidor.get("web_search_requests")),
                             ("web_fetch", servidor.get("web_fetch_requests"))):
            r[campo] = max(r[campo], int(valor or 0))
        for bloque in m.get("content") or []:
            if isinstance(bloque, dict) and bloque.get("type") == "tool_use":
                if bloque.get("name") == "WebSearch":
                    r["_busquedas"].add(bloque.get("id"))
                elif bloque.get("name") == "WebFetch":
                    r["_lecturas"].add(bloque.get("id"))
    for r in respuestas.values():
        r["web_search"] = max(r["web_search"], len(r.pop("_busquedas")))
        r["web_fetch"] = max(r["web_fetch"], len(r.pop("_lecturas")))
    return list(respuestas.values())


def registrar(conn: sqlite3.Connection, filas: list[dict]) -> int:
    for f in filas:
        conn.execute(
            "INSERT INTO consumos (mensaje_id, ejecucion_id, fase, sesion, agente, modelo, fecha, "
            + ", ".join(CAMPOS) + ") VALUES (?,?,?,?,?,?,?" + ",?" * len(CAMPOS) + ") "
            "ON CONFLICT(mensaje_id) DO UPDATE SET " + ", ".join(f"{k}=MAX({k}, excluded.{k})" for k in CAMPOS),
            (f["mensaje_id"], f.get("ejecucion_id"), f.get("fase"), f.get("sesion"), f.get("agente"),
             f.get("modelo"), f.get("fecha"), *[f[k] for k in CAMPOS]))
    conn.commit()
    return len(filas)


def procesar(archivos: list[Path]) -> dict:
    estado = leer_estado()
    posiciones = _leer_posiciones()
    filas = []
    for archivo in archivos:
        for r in leer_nuevo(archivo, posiciones):
            r["ejecucion_id"] = estado.get("ejecucion_id")
            r["fase"] = estado.get("fase")
            filas.append(r)
    conn = conectar()
    try:
        registrar(conn, filas)
    finally:
        conn.close()
    LECTURA.parent.mkdir(parents=True, exist_ok=True)
    LECTURA.write_text(json.dumps(posiciones, ensure_ascii=False), encoding="utf-8")
    return {"apuntadas": len(filas), "ejecucion_id": estado.get("ejecucion_id"), "fase": estado.get("fase")}


def sesion_actual() -> Path | None:
    """El registro mas reciente de este proyecto en Claude Code (la sesion en curso)."""
    carpeta = Path.home() / ".claude" / "projects" / re.sub(r"[^A-Za-z0-9]", "-", str(RAIZ))
    registros = sorted(carpeta.glob("*.jsonl"), key=lambda p: p.stat().st_mtime) if carpeta.is_dir() else []
    return registros[-1] if registros else None


def volcar() -> None:
    """Lee AHORA el consumo de la sesion en curso, sin esperar al hook. Llamarlo ANTES de cambiar de fase y al cerrar:
    asi lo gastado en una fase se apunta a esa fase aunque todo ocurra en una sola respuesta larga. Nunca falla."""
    try:
        sesion = sesion_actual()
        if sesion:
            procesar(archivos_de_sesion(str(sesion)))
    except Exception:
        pass


def filas(ejecucion: int | str | None) -> list[dict]:
    """Una fila por respuesta del modelo (mensaje_id) con el MODELO que respondio. Laravel las guarda con la tarifa y el coste
    del momento en que las registra (consumos_menu); el agente no conoce precios."""
    conn = conectar()
    donde, args = (" WHERE ejecucion_id=?", (ejecucion,)) if ejecucion is not None else ("", ())
    salida = [dict(r) for r in conn.execute(
        "SELECT mensaje_id, ejecucion_id, COALESCE(fase, 'sin fase') AS fase, agente, modelo, fecha, "
        + ", ".join(CAMPOS) + " FROM consumos" + donde + " ORDER BY fecha, id", args)]
    conn.close()
    return salida


def resumen(ejecucion: int | str | None) -> dict:
    conn = conectar()
    donde, args = (" WHERE ejecucion_id=?", (ejecucion,)) if ejecucion is not None else ("", ())
    por_fase = [dict(r) for r in conn.execute(
        "SELECT ejecucion_id, COALESCE(fase,'sin fase') AS fase, agente, COUNT(*) AS respuestas, "
        + ", ".join(f"SUM({k}) AS {k}" for k in CAMPOS) + " FROM consumos" + donde
        + " GROUP BY ejecucion_id, fase, agente ORDER BY ejecucion_id, fase", args)]
    totales = {"respuestas": sum(f["respuestas"] or 0 for f in por_fase),
               **{k: sum(f[k] or 0 for f in por_fase) for k in CAMPOS}}
    conn.close()
    return {"por_fase": por_fase, "totales": totales, "filas": filas(ejecucion)}


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("orden", nargs="?", choices=["resumen", "fase"])
    p.add_argument("nombre", nargs="?")
    p.add_argument("--hook", action="store_true")
    p.add_argument("--archivo")
    p.add_argument("--ejecucion", type=_ejecucion)
    a = p.parse_args()
    if a.hook:
        try:
            entrada = json.loads(sys.stdin.buffer.read().decode("utf-8") or "{}")
            procesar(archivos_de_sesion(entrada.get("transcript_path"), entrada.get("agent_transcript_path")))
        except Exception:
            pass
        sys.exit(0)
    if a.archivo:
        print(json.dumps({"ok": True, **procesar(archivos_de_sesion(None, a.archivo))}, ensure_ascii=False))
    elif a.orden == "fase":
        volcar()   # primero se aparta lo gastado en la fase que acaba...
        print(json.dumps({"ok": True, "estado": fijar_fase(a.nombre, a.ejecucion)}, ensure_ascii=False))
    else:
        print(json.dumps({"ok": True, **resumen(a.ejecucion)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
