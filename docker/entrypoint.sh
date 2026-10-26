#!/bin/sh
# Prepara lo minimo (solo en el servicio "preparar", ver docker-compose.yml) y despues ejecuta el comando.
set -e

if [ "$PREPARAR" = "1" ]; then
    [ -f .env ] || { cp .env.example .env && echo "Creado admin/.env desde .env.example"; }
    composer install --no-interaction --prefer-dist
    grep -q '^APP_KEY=.\+' .env || php artisan key:generate --force
    mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    [ -f database/database.sqlite ] || touch database/database.sqlite
    # Base con otro nombre (el sandbox usa database/sandbox.sqlite): que exista antes de migrar.
    case "$DB_DATABASE" in /*) [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE" ;; esac
    mkdir -p ../agente/base ../agente/workspace ../agente/encargos
    php artisan migrate --force
    php artisan filament:upgrade --no-interaction || true
    php artisan storage:link 2>/dev/null || true
    echo "Listo. Crea tu usuario con: docker compose exec web php artisan make:filament-user"
    exit 0
fi

exec "$@"
