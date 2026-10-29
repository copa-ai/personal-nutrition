#!/bin/bash
# Arranque del servicio Celaut (service.json -> init.entry_path). Hace dentro de un solo microVM lo que docker-compose.yml
# reparte en contenedores, conservando su regla de oro: administracion y agente NO comparten ficheros, solo HTTP interno
# (127.0.0.1:9000, con Bearer) y S3 (Garage en 127.0.0.1:3900). Cada pieza corre con su usuario y con un entorno vacio
# (env -i) al que solo se le pasa lo suyo: el agente nunca ve APP_KEY ni la base.
#
#   garage  (usuario garage)  S3 local            127.0.0.1:3900
#   agente  (usuario agente)  runner de claude -p 0.0.0.0:9000 (el runner no deja elegir interfaz; fuera del VM no se declara)
#   web     (usuario app)     artisan serve       0.0.0.0:8000  <- unico puerto declarado en service.json -> api
#   cola    (usuario app)     queue:work
#
# Usuario inicial del panel: admin@demo.com / password (php artisan usuario:inicial, solo si no hay ningun usuario).
#
# Variables de service.json -> envs (todas opcionales): APP_URL, APP_TIMEZONE, AGENTE_MODELO, AGENTE_MAX_SIMULTANEAS.
set -eu

DATOS=/var/lib/nutrition
KIT_ADMIN=/opt/nutrition/admin
PATH_BASE=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

mkdir -p "$DATOS/secretos" "$DATOS/garage" "$DATOS/admin" "$DATOS/agente"
chmod 755 "$DATOS"
chmod 700 "$DATOS/secretos"

# --- Secretos: se generan la primera vez y se reutilizan mientras exista este disco ---
hex()    { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
secreto() {   # secreto <nombre> <generador...>: devuelve el guardado o lo crea
    local f="$DATOS/secretos/$1"; shift
    [ -s "$f" ] || { "$@" > "$f"; chmod 600 "$f"; }
    cat "$f"
}
AGENTE_SECRETO=$(secreto agente-secreto hex 24)
APP_KEY=$(secreto app-key sh -c 'printf "base64:%s" "$(head -c 32 /dev/urandom | base64)"')
S3_CLAVE=$(secreto s3-clave sh -c 'printf "GK%s" "$(head -c 12 /dev/urandom | od -An -tx1 | tr -d " \n")"')
S3_SECRETO=$(secreto s3-secreto hex 32)
GARAGE_RPC=$(secreto garage-rpc hex 32)

S3_BUCKET=agente
S3_REGION=us-east-1
S3_ENDPOINT=http://127.0.0.1:3900

# Ejecuta un comando como <usuario> con el entorno vacio salvo lo que se pase como VAR=valor antes de «--».
como() {
    local usuario=$1; shift
    local vars=()
    while [ "$1" != "--" ]; do vars+=("$1"); shift; done; shift
    exec setpriv --reuid="$usuario" --regid="$usuario" --init-groups \
        env -i PATH="$PATH_BASE" HOME="$(getent passwd "$usuario" | cut -d: -f6)" "${vars[@]}" "$@"
}

PIDS=()
# Cada pieza en un subshell que `como` sustituye (exec) por su proceso: matar el PID mata la pieza, no un intermediario.
lanzar() { ( "$@" ) & PIDS+=($!); }
parar() { trap - TERM INT; kill "${PIDS[@]}" 2>/dev/null || true; wait 2>/dev/null || true; }
trap 'parar; exit 0' TERM INT

# --- Garage (S3 de un solo nodo) ---
chown -R garage:garage "$DATOS/garage"
cat > "$DATOS/garage/garage.toml" <<TOML
metadata_dir = "$DATOS/garage/meta"
data_dir = "$DATOS/garage/data"
db_engine = "sqlite"
replication_factor = 1
rpc_bind_addr = "127.0.0.1:3901"
rpc_public_addr = "127.0.0.1:3901"
rpc_secret = "$GARAGE_RPC"

[s3_api]
s3_region = "$S3_REGION"
api_bind_addr = "127.0.0.1:3900"
TOML
chown garage:garage "$DATOS/garage/garage.toml"; chmod 600 "$DATOS/garage/garage.toml"
mkdir -p "$DATOS/garage/meta" "$DATOS/garage/data"; chown garage:garage "$DATOS/garage/meta" "$DATOS/garage/data"

G=(setpriv --reuid=garage --regid=garage --init-groups env -i PATH="$PATH_BASE" HOME=/home/garage garage -c "$DATOS/garage/garage.toml")
lanzar "${G[@]}" server
until "${G[@]}" status >/dev/null 2>&1; do sleep 1; done
if "${G[@]}" status | grep -q "NO ROLE ASSIGNED"; then
    NODO=$("${G[@]}" status | awk '/^[0-9a-f]+[ \t]/ {print $1; exit}')
    "${G[@]}" layout assign -z dev -c 2G "$NODO"
    "${G[@]}" layout apply --version 1
    sleep 2
fi
"${G[@]}" key info "$S3_CLAVE" >/dev/null 2>&1 || "${G[@]}" key import --yes -n agente "$S3_CLAVE" "$S3_SECRETO"
"${G[@]}" bucket info "$S3_BUCKET" >/dev/null 2>&1 || "${G[@]}" bucket create "$S3_BUCKET"
"${G[@]}" bucket allow --read --write --owner "$S3_BUCKET" --key "$S3_CLAVE"

S3_AGENTE=(AGENTE_S3_BUCKET="$S3_BUCKET" AGENTE_S3_PREFIJO= AGENTE_S3_REGION="$S3_REGION" AGENTE_S3_ENDPOINT="$S3_ENDPOINT"
           AGENTE_S3_CLAVE="$S3_CLAVE" AGENTE_S3_SECRETO="$S3_SECRETO" AGENTE_S3_RUTA_ESTILO=1)

# --- Agente: Claude Code + runner HTTP. Su entrypoint copia el kit (/opt/agente-kit) a su carpeta y marca la confianza ---
mkdir -p "$DATOS/agente/app" "$DATOS/agente/estado" "$DATOS/agente/home"
chown -R agente:agente "$DATOS/agente"; chmod 700 "$DATOS/agente"
lanzar como agente AGENTE_SECRETO="$AGENTE_SECRETO" AGENTE_CARPETA="$DATOS/agente/app" AGENTE_ESTADO="$DATOS/agente/estado" \
    AGENTE_MAX_SIMULTANEAS="${AGENTE_MAX_SIMULTANEAS:-1}" "${S3_AGENTE[@]}" -- /opt/runner/entrypoint.sh
until python3 -c "import urllib.request as u; u.urlopen('http://127.0.0.1:9000/salud', timeout=3)" 2>/dev/null; do sleep 1; done

# --- Administracion: carpeta propia del agente (encargos/workspace/base) y datos en $DATOS/admin ---
A="$DATOS/admin"
mkdir -p "$A/storage/framework/cache" "$A/storage/framework/sessions" "$A/storage/framework/views" "$A/storage/logs" \
         "$A/storage/app/private" "$A/agente" "$A/bootstrap-cache"
chown -R app:app "$A"; chmod 700 "$A"

ENTORNO_APP=(
    APP_ENV=production APP_DEBUG=false APP_KEY="$APP_KEY" APP_LOCALE=es APP_FALLBACK_LOCALE=en APP_FAKER_LOCALE=es_ES
    APP_NAME="Personal Nutrition" APP_URL="${APP_URL:-http://127.0.0.1:8000}" APP_TIMEZONE="${APP_TIMEZONE:-UTC}"
    LARAVEL_STORAGE_PATH="$A/storage"
    APP_SERVICES_CACHE="$A/bootstrap-cache/services.php" APP_PACKAGES_CACHE="$A/bootstrap-cache/packages.php"
    APP_CONFIG_CACHE="$A/bootstrap-cache/config.php" APP_ROUTES_CACHE="$A/bootstrap-cache/routes-v7.php" APP_EVENTS_CACHE="$A/bootstrap-cache/events.php"
    DB_CONNECTION=sqlite DB_DATABASE="$A/database.sqlite" DB_BUSY_TIMEOUT=10000
    SESSION_DRIVER=database QUEUE_CONNECTION=database CACHE_STORE=database LOG_CHANNEL=stderr
    DB_QUEUE_RETRY_AFTER=14400
    AGENTE_CARPETA="$A/agente" AGENTE_URL=http://127.0.0.1:9000 AGENTE_SECRETO="$AGENTE_SECRETO"
    AGENTE_MODELO="${AGENTE_MODELO:-claude-sonnet-5-5}"
    AGENTE_S3_BUCKET="$S3_BUCKET" AGENTE_S3_PREFIJO= AGENTE_S3_REGION="$S3_REGION" AGENTE_S3_ENDPOINT="$S3_ENDPOINT"
    AGENTE_S3_CLAVE="$S3_CLAVE" AGENTE_S3_SECRETO="$S3_SECRETO" AGENTE_S3_RUTA_ESTILO=1
    PHP_CLI_SERVER_WORKERS=4
)
app() { ( como app "${ENTORNO_APP[@]}" -- "$@" ); }

[ -f "$A/database.sqlite" ] || app touch "$A/database.sqlite"
app cp -R /opt/agente-kit/. "$A/agente/"
app mkdir -p "$A/agente/base" "$A/agente/workspace" "$A/agente/encargos"
cd "$KIT_ADMIN"
app php artisan migrate --force
app php artisan usuario:inicial

lanzar como app "${ENTORNO_APP[@]}" -- php artisan serve --host=0.0.0.0 --port=8000 --no-reload
lanzar como app "${ENTORNO_APP[@]}" -- php artisan queue:work --tries=1 --timeout=0 --sleep=3

# Si cualquier pieza muere, el servicio entero cae: asi nodo lo ve parado en vez de «sano» sin cola o sin agente.
wait -n
echo "start.sh: una pieza ha terminado; se detiene el servicio" >&2
parar
exit 1
