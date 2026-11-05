#!/bin/bash
# PostgreSQL desechable para probar migraciones, el volcado y el esquema (misma version que dependencies/postgres).
#   docker/pruebas/postgres.sh up      # escucha en 127.0.0.1:55432 (usuario/clave/base: nutrition/ci/personal_nutrition)
#   docker/pruebas/postgres.sh down
# Despues:  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=personal_nutrition DB_USERNAME=nutrition DB_PASSWORD=ci docker/pruebas/php.sh artisan migrate --force
set -eu
NOMBRE=nutrition-pg-prueba
case "${1:-up}" in
    up)
        docker rm -f "$NOMBRE" >/dev/null 2>&1 || true
        docker run -d --name "$NOMBRE" -p 127.0.0.1:55432:5432 -e POSTGRES_DB=personal_nutrition -e POSTGRES_USER=nutrition -e POSTGRES_PASSWORD=ci \
            "${IMAGEN_PG:-postgres:18-alpine}" >/dev/null
        for _ in $(seq 1 40); do docker exec "$NOMBRE" pg_isready -U nutrition -d personal_nutrition >/dev/null 2>&1 && exit 0; sleep 1; done
        echo "postgres no arranco" >&2; exit 1 ;;
    down) docker rm -f "$NOMBRE" >/dev/null 2>&1 || true ;;
    *) echo "uso: $0 up|down" >&2; exit 2 ;;
esac
