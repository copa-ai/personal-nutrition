#!/usr/bin/env bash
# Genera los stubs de Python (chat_pb2.py y chat_pb2_grpc.py) en un directorio.
# Uso: ./generar.sh [directorio_destino]   (por defecto: ./generado)
# Los stubs no se versionan.
set -euo pipefail

aqui="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
destino="${1:-$aqui/generado}"
mkdir -p "$destino"

python3 -m grpc_tools.protoc \
    -I "$aqui" \
    --python_out="$destino" \
    --grpc_python_out="$destino" \
    "$aqui/chat.proto"
