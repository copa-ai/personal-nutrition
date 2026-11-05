#!/usr/bin/env python3
"""«Claude» de mentira para probar la cadena completa sin el modelo (solo para pruebas; ver docker-compose.pruebas.yml).

Imita lo justo de `claude -p PROMPT --output-format stream-json --verbose`: lee el encargo, marca las fases con el consumos.py real,
escribe un menu valido (o, segun encargo.tarea, el menu editado o los cambios de un intercambio) y lo entrega con el menu.py real. Emite los mismos eventos stream-json que el Claude de verdad.

El modo se lee de /app/agente/modo-falso (se cambia sin recrear el contenedor):
  ok (por defecto)  entrega un menu valido
  lento             como ok, pero se queda 120 s en la fase de planificacion (para probar reinicios y paradas)
  limite            imprime el aviso de limite de uso del plan y sale con 1, sin entregar
  sinresultado      termina bien pero sin dejar resultado (dice por que)
  invalido          deja un resultado que incumple el contrato

Modo chat: si el prompt empieza por «Turno de chat N.» (ver App\\Services\\Chat\\Claude\\RespondedorClaude), lee la instantanea de
encargos/chat-N/ (LEEME.json, perfiles.json, conversacion.json) y responde como la skill chat-nutricion: razonamiento (thinking), una frase
mientras usa una herramienta, la respuesta con un formulario (bloque keyboard) y, si la pregunta pide registrar algo o trae una foto, un archivo
de acciones en workspace/chat-acciones-N.json. El modo se lee del mismo modo-falso:
  ok (por defecto)  respuesta normal
  limite            aviso de limite de uso del plan y salida 1
  error             falla (mensaje en stderr y salida 1)
  invalida          propone una accion invalida (perfil inexistente) ademas de una valida
  lento             espera 60 s tras el primer evento (para probar cancelacion)
"""
import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path

RAIZ = Path(os.environ.get("AGENTE_CARPETA") or "/app/agente")
sys.path.insert(0, str(RAIZ / "scripts"))
MODELO = "claude-sonnet-5-5"
T0 = time.time()


def emitir(evento: dict) -> None:
    print(json.dumps(evento, ensure_ascii=False), flush=True)


def texto(t: str) -> None:
    emitir({"type": "assistant", "message": {"content": [{"type": "text", "text": t}]}})


def herramienta(nombre: str, entrada: dict, resultado: str = "ok") -> None:
    emitir({"type": "assistant", "message": {"content": [{"type": "tool_use", "name": nombre, "input": entrada}]}})
    time.sleep(0.6)
    emitir({"type": "user", "message": {"content": [{"type": "tool_result", "content": resultado}]}})


def bash(comando: str) -> str:
    r = subprocess.run(comando, shell=True, cwd=RAIZ, capture_output=True, text=True)
    herramienta("Bash", {"command": comando}, (r.stdout + r.stderr).strip() or "ok")
    return r.stdout


def apuntar_consumo(n: int, fase: str, entrada: int, salida: int) -> None:
    """Lo que en el Claude real hace el hook Stop: una fila por respuesta del modelo."""
    import consumos
    c = consumos.conectar()
    c.execute("INSERT OR IGNORE INTO consumos (mensaje_id, ejecucion_id, fase, agente, modelo, fecha, input_tokens, output_tokens) VALUES (?,?,?,?,?,?,?,?)",
              (f"msg_falso_{n}_{fase}", n, fase, "principal", MODELO, time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), entrada, salida))
    c.commit()
    c.close()


def chat(turno: int) -> int:
    """Un turno de chat: lee la instantanea (solo lectura) y responde con lo que haria la skill chat-nutricion."""
    raiz = Path(os.environ.get("AGENTE_CARPETA") or os.getcwd())
    modo = (raiz / "modo-falso").read_text().strip() if (raiz / "modo-falso").exists() else "ok"
    emitir({"type": "system", "subtype": "init", "model": MODELO})
    if modo == "limite":
        print("You've hit your session limit · resets 3:20pm (UTC)", flush=True)
        return 1
    if modo == "error":
        print("Error interno del Claude falso", file=sys.stderr, flush=True)
        return 1

    carpeta = f"encargos/chat-{turno}"
    emitir({"type": "assistant", "message": {"content": [{"type": "thinking", "thinking": "La persona pregunta por la comida. Miro la instantánea."}]}})
    if modo == "lento":
        time.sleep(60)
    leeme = json.loads((raiz / carpeta / "LEEME.json").read_text(encoding="utf-8"))
    emitir({"type": "assistant", "message": {"content": [
        {"type": "text", "text": "Leo la instantánea del hogar."},
        {"type": "tool_use", "name": "Read", "input": {"file_path": f"{carpeta}/LEEME.json"}}]}})
    emitir({"type": "user", "message": {"content": [{"type": "tool_result", "content": "x" * 500}]}})
    perfiles = json.loads((raiz / carpeta / "perfiles.json").read_text(encoding="utf-8"))
    conversacion = json.loads((raiz / carpeta / "conversacion.json").read_text(encoding="utf-8"))
    pregunta = next(m for m in conversacion["mensajes"] if m.get("es_la_pregunta"))
    fotos = [f for f in pregunta["fotos"] if f.get("archivo")]
    for f in fotos:
        herramienta("Read", {"file_path": f"{carpeta}/{f['archivo']}"}, "[imagen]")   # el Claude real veria la foto con Read
    activos = [p for p in perfiles if p["activo"]]

    acciones = []
    if "registra" in pregunta["texto"].lower() or fotos:
        accion = {"tipo": "registrar_comida", "perfil": activos[0]["id"], "fecha": leeme["hoy"], "momento": "comida", "descripcion": "Lentejas con arroz (según la conversación)"}
        fotos and accion.update(foto_hash=fotos[0]["foto_hash"])
        acciones.append(accion)
        if modo == "invalida":
            acciones.append({"tipo": "registrar_comida", "perfil": 999999, "fecha": leeme["hoy"], "momento": "cena", "descripcion": "Esto no se puede aplicar"})
    if acciones:
        (raiz / f"workspace/chat-acciones-{turno}.json").write_text(json.dumps({"acciones": acciones}, ensure_ascii=False), encoding="utf-8")
        herramienta("Write", {"file_path": f"workspace/chat-acciones-{turno}.json"})

    nombres = ", ".join(p["nombre"] for p in activos)
    texto(f"En casa hay {len(activos)} perfiles ({nombres}). ¿Para quién es la pregunta?\n\n"
          "```keyboard\n<p>¿Para quién?</p>\n<div>\n"
          + "\n".join(f'<button data-say="Para {p["nombre"]}" data-send>{p["nombre"]}</button>' for p in activos)
          + "\n</div>\n```")
    emitir({"type": "result", "result": "listo", "num_turns": 3, "duration_ms": int((time.time() - T0) * 1000), "total_cost_usd": 0.01})
    return 0


def main() -> int:
    prompt = sys.argv[sys.argv.index("-p") + 1] if "-p" in sys.argv else ""
    if (c := re.match(r"Turno de chat (\d+)\.", prompt)):
        return chat(int(c.group(1)))
    m = re.search(r"trabajo (\d+)", prompt)
    if not m:
        print("Solo atiendo encargos de planificación.")
        return 0
    n = int(m.group(1))
    modo = (RAIZ / "modo-falso").read_text().strip() if (RAIZ / "modo-falso").exists() else "ok"

    emitir({"type": "system", "subtype": "init", "model": MODELO})
    if modo == "limite":
        print("You've hit your session limit · resets 3:20pm (UTC)", flush=True)
        return 1

    encargo_ruta = f"encargos/encargo-{n}.json"
    texto(f"Leo el encargo {n}.")
    bash(f"python scripts/consumos.py fase lectura --ejecucion {n}")
    encargo = json.loads((RAIZ / encargo_ruta).read_text(encoding="utf-8"))
    herramienta("Read", {"file_path": encargo_ruta}, "x" * 4000)
    apuntar_consumo(n, "lectura", 9000, 400)

    bash(f"python scripts/consumos.py fase planificacion --ejecucion {n}")
    texto("Planifico el menú de la semana.")
    if modo == "lento":
        time.sleep(120)
    categoria = encargo["categorias_validas"][0]
    receta = lambda titulo, minutos: {
        "titulo": titulo, "ingredientes": [{"nombre": "tomate", "cantidad": 200, "unidad": "g", "en_stock": True}],
        "duracion_previa_min": 0, "duracion_momento_min": minutos, "pasos": ["Cortar", "Cocinar"]}
    opcion = lambda titulo, minutos: {**receta(titulo, minutos), "categorias": [categoria]}
    toma = lambda titulo: receta(titulo, 0)
    tarea = encargo.get("tarea", "generar")
    if tarea == "intercambiar":
        # Cambia la primera toma de comida o cena en la que el hogar cocina, con las mismas categorias que ya tenia.
        cambios = {}
        for d in encargo["menu_activo"]:
            t = next(t for t in encargo["hogar"]["tiempos"] if t["fecha"] == d["fecha"])
            momento = next((m for m in ("comida", "cena") if t[f"minutos_{m}"] is not None), None)
            if momento:
                cambios[f"{d['fecha']}/{momento}"] = {"categorias": [categoria], "opciones": [opcion(f"Cambio A {d['fecha']}", min(10, t[f"minutos_{momento}"])), opcion(f"Cambio B {d['fecha']}", min(15, t[f"minutos_{momento}"]))]}
                break
        contenido = {"entrega": {"version": 3}, "cambios": cambios}
    else:
        dias = []
        for t in encargo["hogar"]["tiempos"]:
            dias.append({"fecha": t["fecha"],
                         "comida": {"categorias": [], "opciones": []} if t["minutos_comida"] is None else {"categorias": [categoria], "opciones": [opcion(f"Plato A {t['fecha']}", min(10, t["minutos_comida"])), opcion(f"Plato B {t['fecha']}", min(15, t["minutos_comida"]))]},
                         "cena": {"categorias": [], "opciones": []} if t["minutos_cena"] is None else {"categorias": [categoria], "opciones": [opcion(f"Cena A {t['fecha']}", min(10, t["minutos_cena"])), opcion(f"Cena B {t['fecha']}", min(20, t["minutos_cena"]))]},
                         "individuales": [{"perfil_id": p["id"], "desayuno": toma("Tostada con tomate"), "almuerzo": toma("Fruta"), "merienda": toma("Yogur")} for p in encargo["perfiles"]]})
        explicaciones = [{"perfil_id": p["id"], "texto": f"Menú de prueba para {p['nombre']}."} for p in encargo["perfiles"]]
        contenido = {"entrega": {"version": 3}, "dias": dias, "explicaciones": explicaciones}
        if tarea == "editar":
            # Parte del menu actual y solo toca la primera toma de comida con opciones, y la explicacion de cada perfil.
            contenido = {"entrega": {"version": 3}, "dias": encargo["contenido_actual"]["dias"], "explicaciones": encargo["contenido_actual"]["explicaciones"]}
            for d in contenido["dias"]:
                if d["comida"]["opciones"]:
                    d["comida"]["opciones"][0]["titulo"] = f"{d['comida']['opciones'][0]['titulo']} (editado)"
                    break
            for x in contenido["explicaciones"]:
                x["texto"] += f" Editado: {encargo['instruccion']}"
    apuntar_consumo(n, "planificacion", 15000, 9000)
    borrador = f"workspace/borrador-{n}.json"
    (RAIZ / borrador).write_text(json.dumps(contenido, ensure_ascii=False), encoding="utf-8")
    herramienta("Write", {"file_path": borrador})

    if modo == "invalido":
        (RAIZ / encargo["salida"]).write_text(json.dumps({"entrega": {"completo": True, "version": 3}, **({"cambios": {"2000-01-01/comida": {}}} if tarea == "intercambiar" else {"dias": dias, "explicaciones": []})}), encoding="utf-8")
        texto("Entrego (sin validar).")
        emitir({"type": "result", "result": "Menú entregado", "num_turns": 6, "duration_ms": int((time.time() - T0) * 1000)})
        return 0

    bash(f"python scripts/consumos.py fase validacion --ejecucion {n}")
    bash(f"python scripts/menu.py validar {borrador} --encargo {encargo_ruta}")
    apuntar_consumo(n, "validacion", 4000, 300)
    if modo == "sinresultado":
        emitir({"type": "result", "result": "No puedo cumplir la restricción de uno de los perfiles.", "num_turns": 5, "duration_ms": int((time.time() - T0) * 1000)})
        return 0
    bash(f"python scripts/menu.py entregar {borrador} --encargo {encargo_ruta}")
    emitir({"type": "result", "result": "Menú entregado", "num_turns": 7, "duration_ms": int((time.time() - T0) * 1000), "total_cost_usd": 0.05})
    return 0


if __name__ == "__main__":
    sys.exit(main())
