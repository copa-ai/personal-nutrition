#!/bin/bash
# Arranque del servicio Celaut (service.json -> init.entry_path). Hace dentro de un solo microVM lo que docker-compose.yml
# reparte en contenedores, conservando su regla de oro: administracion y agente NO comparten ficheros, solo HTTP interno
# (127.0.0.1:9000, con Bearer): los archivos del agente viajan en esas peticiones y los guarda la administracion en la base de datos
# compartida. Cada pieza corre con su usuario y con un entorno vacio
# (env -i) al que solo se le pasa lo suyo: el agente nunca ve APP_KEY ni la base.
#
#   agente  (usuario agente)  runner de claude -p 0.0.0.0:9000 (el runner no deja elegir interfaz; fuera del VM no se declara)
#   web     (usuario app)     frankenphp (HTTP)   0.0.0.0:8000  <- unico puerto declarado en service.json -> api
#   cola    (usuario app)     queue:work
#
# Usuario inicial del panel: admin@demo.com / password (php artisan usuario:inicial, solo si no hay ningun usuario).
#
# Base de datos: PostgreSQL compartida entre dispositivos. `php artisan nutrition:base-datos` (que habla con el nodo con `celaut`,
# de celaut-project/service-library) la busca en la red «postgres:personal-nutrition» o lanza nutrition-postgres como hijo.
#
# Variables de service.json -> envs (todas opcionales): APP_URL, APP_TIMEZONE, AGENTE_MODELO, AGENTE_MAX_SIMULTANEAS,
# NUTRITION_RED (opcional): lo que hay que compartir entre dispositivos que usan la misma base, en una sola variable corta
# (base64url de «APP_KEY|cluster|clave_bd»; la da Ajustes -> Variables compartidas). Trae APP_KEY (cifra el token de Claude y las
# sesiones), la clave publica de la red de la base y su contraseña. Sin ella, el dispositivo genera las suyas y empieza una red nueva.
set -eu

DATOS=/var/lib/nutrition
KIT_ADMIN=/opt/nutrition/admin
PATH_BASE=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

mkdir -p "$DATOS/secretos" "$DATOS/admin" "$DATOS/agente"
chmod 755 "$DATOS"
chmod 700 "$DATOS/secretos"

# --- Secretos: se generan la primera vez y se reutilizan mientras exista este disco ---
hex()    { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
secreto() {   # secreto <nombre> <generador...>: devuelve el guardado o lo crea
    local f="$DATOS/secretos/$1"; shift
    [ -s "$f" ] || { "$@" > "$f"; chmod 600 "$f"; }
    cat "$f"
}
# --- Red compartida: NUTRITION_RED = base64url de «APP_KEY|cluster|clave_bd». Se decodifica aqui, con shell, sin PHP ---
if [ -n "${NUTRITION_RED:-}" ]; then
    RED=$(printf '%s' "$NUTRITION_RED" | tr -d ' \n\r=' | tr '_-' '/+')
    while [ $(( ${#RED} % 4 )) -ne 0 ]; do RED="$RED="; done
    RED=$(printf '%s' "$RED" | base64 -d 2>/dev/null) || RED=""
    APP_KEY=$(printf '%s' "$RED" | cut -d'|' -f1)
    NUTRITION_DB_CLUSTER=$(printf '%s' "$RED" | cut -s -d'|' -f2)
    NUTRITION_DB_PASSWORD=$(printf '%s' "$RED" | cut -s -d'|' -f3-)
    if [ -z "$APP_KEY" ] || [ -z "$NUTRITION_DB_CLUSTER" ] || [ -z "$NUTRITION_DB_PASSWORD" ]; then
        echo "start.sh: NUTRITION_RED no es valida" >&2
        exit 1
    fi
fi
AGENTE_SECRETO=$(secreto agente-secreto hex 24)
# Sin NUTRITION_RED se generan (red nueva). APP_KEY debe ser la misma en todos los dispositivos: lo cifrado en la base (token de Claude) solo lo lee quien la tenga.
APP_KEY=${APP_KEY:-$(secreto app-key sh -c 'printf "base64:%s" "$(head -c 32 /dev/urandom | base64)"')}
DB_USUARIO=nutrition
DB_NOMBRE=personal_nutrition
DB_CLAVE=${NUTRITION_DB_PASSWORD:-$(secreto db-password hex 24)}


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
parar() {
    trap - TERM INT
    kill "${PIDS[@]}" 2>/dev/null || true
    wait 2>/dev/null || true
    # Las instancias Celaut que lanzo este servicio (nutrition-postgres, si no habia otra): se paran al terminar.
    ! declare -p ENTORNO_ESTADO >/dev/null 2>&1 || ( cd "$KIT_ADMIN" && como app "${ENTORNO_BASE[@]}" "${ENTORNO_ESTADO[@]}" -- php artisan nutrition:apagar-nodo ) >/dev/null 2>&1 || true
}
trap 'parar; exit 0' TERM INT

# --- Agente: Claude Code + runner HTTP. Su entrypoint copia el kit (/opt/agente-kit) a su carpeta y marca la confianza.
#     No tiene credenciales de nada: ni base de datos ni almacenamiento (la administracion le pasa y recoge los archivos por HTTP) ---
mkdir -p "$DATOS/agente/app" "$DATOS/agente/estado" "$DATOS/agente/home"
chown -R agente:agente "$DATOS/agente"; chmod 700 "$DATOS/agente"
lanzar como agente AGENTE_SECRETO="$AGENTE_SECRETO" AGENTE_CARPETA="$DATOS/agente/app" AGENTE_ESTADO="$DATOS/agente/estado" \
    AGENTE_MAX_SIMULTANEAS="${AGENTE_MAX_SIMULTANEAS:-1}" -- /opt/runner/entrypoint.sh
until python3 -c "import urllib.request as u; u.urlopen('http://127.0.0.1:9000/salud', timeout=3)" 2>/dev/null; do sleep 1; done

# --- Administracion: carpeta propia del agente (encargos/workspace/base) y datos en $DATOS/admin ---
A="$DATOS/admin"
mkdir -p "$A/storage/framework/cache" "$A/storage/framework/sessions" "$A/storage/framework/views" "$A/storage/logs" \
         "$A/storage/app/private" "$A/agente" "$A/bootstrap-cache" "$A/celaut"
chown -R app:app "$A"; chmod 700 "$A"

# /__config__ (el nodo lo escribe para root) lo lee `celaut`, que corre como app: solo app (no el agente) y sin que lo vea nadie mas.
# Lleva las variables con las que se lanzo el servicio (NUTRITION_RED).
[ ! -e /__config__ ] || { chgrp app /__config__ && chmod 640 /__config__; }

ENTORNO_BASE=(
    APP_ENV=production APP_DEBUG=false APP_KEY="$APP_KEY" APP_LOCALE=es APP_FALLBACK_LOCALE=en APP_FAKER_LOCALE=es_ES
    APP_NAME="Personal Nutrition" APP_URL="${APP_URL:-http://127.0.0.1:8000}" APP_TIMEZONE="${APP_TIMEZONE:-UTC}"
    LARAVEL_STORAGE_PATH="$A/storage"
    APP_SERVICES_CACHE="$A/bootstrap-cache/services.php" APP_PACKAGES_CACHE="$A/bootstrap-cache/packages.php"
    APP_CONFIG_CACHE="$A/bootstrap-cache/config.php" APP_ROUTES_CACHE="$A/bootstrap-cache/routes-v7.php" APP_EVENTS_CACHE="$A/bootstrap-cache/events.php"
    SESSION_DRIVER=database QUEUE_CONNECTION=database CACHE_STORE=database LOG_CHANNEL=stderr
    DB_QUEUE_RETRY_AFTER=14400
    AGENTE_CARPETA="$A/agente" AGENTE_URL=http://127.0.0.1:9000 AGENTE_SECRETO="$AGENTE_SECRETO" AGENTE_ALMACEN=base
    AGENTE_MODELO="${AGENTE_MODELO:-claude-sonnet-5-5}"
)

# --- Base de datos compartida: la busca en la red (o lanza nutrition-postgres) con `celaut`, el binario de service-library ---
# Estas variables son las de la base; se pasan solo a este comando y despues a la web y la cola.
ENTORNO_ESTADO=(CELAUT_STATE="$A/celaut/pool.json" NUTRITION_CELAUT=/opt/nutrition/celaut)
ENTORNO_DB=(DB_CONNECTION=pgsql DB_USERNAME="$DB_USUARIO" DB_DATABASE="$DB_NOMBRE" DB_PASSWORD="$DB_CLAVE" NUTRITION_DB_CLUSTER="${NUTRITION_DB_CLUSTER:-}")
RUN_DB="$A/celaut/base-datos.json"
rm -f "$RUN_DB"
cd "$KIT_ADMIN"
( como app "${ENTORNO_BASE[@]}" "${ENTORNO_ESTADO[@]}" "${ENTORNO_DB[@]}" -- \
    php artisan nutrition:base-datos "$RUN_DB" --estado="$A/celaut" ) || { echo "start.sh: no hay base de datos" >&2; parar; exit 1; }
# Sin sustitucion de procesos (`< <(...)`) ni «here-strings»: el guest no tiene /dev/fd (el servicio moria con «/dev/fd/63: No such file»).
DATOS_DB=$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d['host'], d['port'], d['cluster'])" "$RUN_DB")
set -- $DATOS_DB
DB_ANFITRION=$1; DB_PUERTO=$2; NUTRITION_DB_CLUSTER=$3

ENTORNO_APP=("${ENTORNO_BASE[@]}" "${ENTORNO_ESTADO[@]}" "${ENTORNO_DB[@]}" DB_HOST="$DB_ANFITRION" DB_PORT="$DB_PUERTO" NUTRITION_DB_CLUSTER="$NUTRITION_DB_CLUSTER")
app() { ( como app "${ENTORNO_APP[@]}" -- "$@" ); }

app cp -R /opt/agente-kit/. "$A/agente/"
app mkdir -p "$A/agente/base" "$A/agente/workspace" "$A/agente/encargos"
cd "$KIT_ADMIN"
# Varios dispositivos pueden arrancar a la vez contra la misma base: migrate se reintenta (la base puede estar arrancando
# o con las migraciones a medias por otro dispositivo).
for i in $(seq 1 60); do
    app php artisan migrate --force && break
    [ "$i" -lt 60 ] || { echo "start.sh: la base de datos no acepto las migraciones" >&2; parar; exit 1; }
    sleep 3
done
app php artisan usuario:inicial

# --- Web: FrankenPHP (Caddy con PHP embebido; HTTP, el TLS lo pone el tunel), con el entorno de la app y nada mas ---
lanzar como app "${ENTORNO_APP[@]}" -- frankenphp run --config /opt/nutrition/Caddyfile --adapter caddyfile
lanzar como app "${ENTORNO_APP[@]}" -- php artisan queue:work --tries=1 --timeout=0 --sleep=3

# Si cualquier pieza muere, el servicio entero cae: asi nodo lo ve parado en vez de «sano» sin cola o sin agente.
wait -n
echo "start.sh: una pieza ha terminado; se detiene el servicio" >&2
parar
exit 1
