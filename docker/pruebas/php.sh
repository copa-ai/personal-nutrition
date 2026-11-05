#!/bin/bash
# Ejecuta PHP en un contenedor sobre admin/ (sin instalar PHP en el equipo).
#   docker/pruebas/php.sh artisan test --compact
#   docker/pruebas/php.sh /ruta/composer.phar install --ignore-platform-req=ext-gd
# Variables: IMAGEN_PHP (por defecto pf-php: PHP 8.4 con pdo_pgsql, pdo_sqlite, intl, zip...), PHP_RED (por defecto host: para llegar a
# la base de docker/pruebas/postgres.sh). Pasa al contenedor las variables DB_*, APP_*, CHAT_* y NUTRITION_* del entorno.
set -eu
RAIZ=$(cd "$(dirname "$0")/../.." && pwd)
ENTORNO=()
for v in $(env | grep -E '^(DB_|APP_|CHAT_|NUTRITION_|AGENTE_|SESSION_|CACHE_|QUEUE_)' | cut -d= -f1); do ENTORNO+=(-e "$v"); done
exec docker run --rm --security-opt label=disable --user "$(id -u):$(id -g)" --network "${PHP_RED:-host}" -e HOME=/tmp -e COMPOSER_HOME=/tmp/cmp \
    "${ENTORNO[@]}" -v "$RAIZ:/repo" -w /repo/admin "${IMAGEN_PHP:-pf-php}" php "$@"
