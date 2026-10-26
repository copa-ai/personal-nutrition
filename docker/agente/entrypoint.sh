#!/bin/sh
# Marca agente/ como carpeta de confianza (sin ello `claude -p` ignora agente/.claude/settings.json y su lista de
# permisos), conservando lo demas de ~/.claude.json (sesion de `claude login`), y arranca el servidor.
set -e

# agente/ es un volumen propio: el kit de la imagen se vuelve a copiar encima en cada arranque (asi una imagen nueva actualiza
# skills y scripts) sin tocar lo que ya hay en base/, workspace/ y encargos/, que el kit no trae.
CARPETA="${AGENTE_CARPETA:-/app/agente}"
if [ -d /opt/agente-kit ]; then
    mkdir -p "$CARPETA" && cp -R --no-preserve=ownership /opt/agente-kit/. "$CARPETA"/
fi
mkdir -p "$CARPETA/base" "$CARPETA/workspace" "$CARPETA/encargos"

node -e '
const fs = require("fs"), f = process.env.HOME + "/.claude.json";
let j = {}; try { j = JSON.parse(fs.readFileSync(f, "utf8")); } catch (e) {}
j.projects = j.projects || {};
for (const p of [process.env.AGENTE_CARPETA || "/app/agente"]) j.projects[p] = Object.assign(j.projects[p] || {}, { hasTrustDialogAccepted: true });
fs.writeFileSync(f, JSON.stringify(j, null, 2));
' || true

exec python3 /opt/runner/runner.py
