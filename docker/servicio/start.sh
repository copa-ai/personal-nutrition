#!/bin/bash
# Arranque del servicio Celaut (service.json -> init.entry_path). Hace dentro de un solo microVM lo que docker-compose.yml
# reparte en contenedores, conservando su regla de oro: administracion y agente NO comparten ficheros, solo HTTP interno
# (127.0.0.1:9000, con Bearer): los archivos del agente viajan en esas peticiones y los guarda la administracion en la base de datos
# compartida. Cada pieza corre con su usuario y con un entorno vacio
# (env -i) al que solo se le pasa lo suyo: el agente nunca ve APP_KEY ni la base.
#
#   agente  (usuario agente)  runner de claude -p 0.0.0.0:9000 (el runner no deja elegir interfaz; fuera del VM no se declara)
#   web     (usuario app)     frankenphp (HTTP)   0.0.0.0:8000  <- unico puerto declarado en service.json -> api
#   cola    (usuario app)     queue:work --queue=default (menus: pueden durar horas) y otro --queue=chat (respuestas del chat)
#   chat    (usuario chat)    traductor gRPC de la interfaz «chat» de chatui (docker/chat/servidor.py, venv /opt/chat-venv) 0.0.0.0:50051
#                             <- segundo slot de service.json -> api (http2 -> grpc -> chat). No guarda datos: habla por HTTP con la web
#                             en 127.0.0.1:8001/interno/chat (segundo sitio del Caddyfile, solo loopback y no declarado en service.json, asi
#                             que no se puede tunelar) con Bearer CHAT_SECRETO. Se lanza DESPUES de que la web responda. Entorno vacio:
#                             solo CHAT_SECRETO, CHAT_PUERTO y CHAT_URL_INTERNA. CHAT_SECRETO (generado una vez, como los demas secretos)
#                             lo reciben tambien la web y la cola (ENTORNO_APP); el agente NO recibe nada del chat. Si el traductor muere,
#                             el servicio cae como las demas piezas.
#
# Usuario inicial del panel: admin@demo.com / password (php artisan usuario:inicial, solo si no hay ningun usuario).
#
# Base de datos: PostgreSQL compartida entre dispositivos. `php artisan nutrition:base-datos` (que habla con el nodo con `celaut`,
# de celaut-project/service-library) la busca en la red «postgres» (PostgreSQL con el esquema de personal-nutrition) o lanza nutrition-postgres como hijo.
#
# Variable de service.json -> envs: solo NUTRITION_RED (opcional). Todo lo demas (zona horaria, modelo y simultaneidad del agente, ajustes
# de la eleccion y fusion de bases) es constante: no lo toca el usuario.
# NUTRITION_RED: lo que hay que compartir entre dispositivos que usan la misma base, en una sola variable corta
# (base64url de «APP_KEY|cluster|clave_bd»; la da Ajustes -> Variables compartidas). Trae APP_KEY (cifra el token de Claude y las
# sesiones), la clave publica de la red de la base y su contraseña. Sin ella, el dispositivo genera las suyas y empieza una red nueva.
set -eu

DATOS=/var/lib/nutrition
KIT_ADMIN=/opt/nutrition/admin
PATH_BASE=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

mkdir -p "$DATOS/secretos" "$DATOS/admin" "$DATOS/agente" "$DATOS/publico"
chmod 755 "$DATOS" "$DATOS/publico"
chmod 700 "$DATOS/secretos"

# --- Log y estado publicos desde el primer segundo: /app.log, /estado.json y la pagina /estado ---
# Todo lo que escriben start.sh y las piezas (stdout y stderr) va a app.log. Lo sirve la pagina de estado (estado/servidor.py) mientras
# arranca y despues FrankenPHP (Caddyfile). Sin autenticacion: no se imprimen secretos, pero el log es visible para quien llegue al :8000.
PUBLICO="$DATOS/publico"
[ "$(stat -c %s "$PUBLICO/app.log" 2>/dev/null || echo 0)" -lt 5242880 ] || mv -f "$PUBLICO/app.log" "$PUBLICO/app.log.1"
touch "$PUBLICO/app.log"; chmod 644 "$PUBLICO/app.log"
exec >>"$PUBLICO/app.log" 2>&1
echo "=== arranque $(date -u +%FT%TZ) ==="

FASES=(secretos agente base migraciones usuario web cola chat)
declare -A F_NOMBRE=([secretos]="Secretos y claves" [agente]="Agente (Claude Code)" [base]="Base de datos PostgreSQL" \
    [migraciones]="Migraciones" [usuario]="Usuario inicial" [web]="Web (FrankenPHP)" [cola]="Cola de trabajos" [chat]="Chat (gRPC)")
declare -A F_ESTADO F_DETALLE
for f in "${FASES[@]}"; do F_ESTADO[$f]=pendiente; F_DETALLE[$f]=""; done
INICIO=$(date +%s); LISTO=false; FALLO=""
escribir_estado() {
    local f sep="" d json
    json="{\"inicio\":$INICIO,\"listo\":$LISTO,\"fallo\":\"${FALLO//\"/\'}\",\"fases\":["
    for f in "${FASES[@]}"; do
        d=${F_DETALLE[$f]//\\/}; d=${d//\"/\'}
        json+="$sep{\"id\":\"$f\",\"nombre\":\"${F_NOMBRE[$f]}\",\"estado\":\"${F_ESTADO[$f]}\",\"detalle\":\"$d\"}"
        sep=,
    done
    printf '%s]}\n' "$json" > "$PUBLICO/estado.json.tmp" && chmod 644 "$PUBLICO/estado.json.tmp" && mv -f "$PUBLICO/estado.json.tmp" "$PUBLICO/estado.json"
}
fase() {   # fase <id> <pendiente|en curso|ok|aviso|error> [detalle]
    F_ESTADO[$1]=$2; F_DETALLE[$1]=${3:-}
    echo "[$(date -u +%T)] $1: $2${3:+ - $3}"
    escribir_estado
}
fallar() {   # fallar <fase> <mensaje>: deja el motivo en la pagina y en el log; el llamador sigue con parar/exit
    FALLO=$2; fase "$1" error "$2"
}
# Si el script muere por cualquier otro motivo (set -e), la pagina lo dice en vez de quedarse en «en curso».
trap 'rc=$?; [ "$rc" -eq 0 ] || { [ -n "$FALLO" ] || FALLO="start.sh termino con error (codigo $rc); ver app.log"; escribir_estado; }' EXIT
escribir_estado

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
        fallar secretos "NUTRITION_RED no es valida (copiala entera desde Ajustes -> Variables compartidas)"
        echo "start.sh: NUTRITION_RED no es valida" >&2
        exit 1
    fi
fi
AGENTE_SECRETO=$(secreto agente-secreto hex 24)
CHAT_SECRETO=$(secreto chat-secreto hex 24)
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

# --- Pagina de estado: lo primero que escucha en :8000 (usuario app, solo biblioteca estandar). Se para justo antes de la web. ---
lanzar como app -- python3 /opt/nutrition/estado/servidor.py "$PUBLICO" 8000
PID_ESTADO=${PIDS[${#PIDS[@]}-1]}
fase secretos ok

# --- Agente: Claude Code + runner HTTP. Su entrypoint copia el kit (/opt/agente-kit) a su carpeta y marca la confianza.
#     No tiene credenciales de nada: ni base de datos ni almacenamiento (la administracion le pasa y recoge los archivos por HTTP) ---
mkdir -p "$DATOS/agente/app" "$DATOS/agente/estado" "$DATOS/agente/home"
chown -R agente:agente "$DATOS/agente"; chmod 700 "$DATOS/agente"
fase agente "en curso" "arrancando el runner en 127.0.0.1:9000"
lanzar como agente AGENTE_SECRETO="$AGENTE_SECRETO" AGENTE_CARPETA="$DATOS/agente/app" AGENTE_ESTADO="$DATOS/agente/estado" \
    AGENTE_MAX_SIMULTANEAS=1 -- /opt/runner/entrypoint.sh
agente_sano() { python3 -c "import urllib.request as u; u.urlopen('http://127.0.0.1:9000/salud', timeout=3)" 2>/dev/null; }
# El agente ya no bloquea el arranque: la web solo lo necesita al generar menus. Se vigila mientras se hace lo demas.
agente_vigilar() { [ "${F_ESTADO[agente]}" = ok ] || ! agente_sano || fase agente ok "responde en 127.0.0.1:9000"; }

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
    APP_NAME="Personal Nutrition" APP_URL=http://127.0.0.1:8000
    LARAVEL_STORAGE_PATH="$A/storage"
    APP_SERVICES_CACHE="$A/bootstrap-cache/services.php" APP_PACKAGES_CACHE="$A/bootstrap-cache/packages.php"
    APP_CONFIG_CACHE="$A/bootstrap-cache/config.php" APP_ROUTES_CACHE="$A/bootstrap-cache/routes-v7.php" APP_EVENTS_CACHE="$A/bootstrap-cache/events.php"
    SESSION_DRIVER=database QUEUE_CONNECTION=database CACHE_STORE=database LOG_CHANNEL=stderr
    DB_QUEUE_RETRY_AFTER=14400
    CHAT_SECRETO="$CHAT_SECRETO"
    AGENTE_CARPETA="$A/agente" AGENTE_URL=http://127.0.0.1:9000 AGENTE_SECRETO="$AGENTE_SECRETO" AGENTE_ALMACEN=base
)

# --- Base de datos compartida: la busca en la red (o lanza nutrition-postgres) con `celaut`, el binario de service-library ---
# Estas variables son las de la base; se pasan solo a este comando y despues a la web y la cola.
ENTORNO_ESTADO=(CELAUT_STATE="$A/celaut/pool.json" NUTRITION_CELAUT=/opt/nutrition/celaut)
ENTORNO_DB=(DB_CONNECTION=pgsql DB_USERNAME="$DB_USUARIO" DB_DATABASE="$DB_NOMBRE" DB_PASSWORD="$DB_CLAVE" NUTRITION_DB_CLUSTER="${NUTRITION_DB_CLUSTER:-}")
RUN_DB="$A/celaut/base-datos.json"
rm -f "$RUN_DB"
cd "$KIT_ADMIN"
fase base "en curso" "buscando la red postgres o lanzando nutrition-postgres"
( como app "${ENTORNO_BASE[@]}" "${ENTORNO_ESTADO[@]}" "${ENTORNO_DB[@]}" -- \
    php artisan nutrition:base-datos "$RUN_DB" --estado="$A/celaut" ) || { fallar base "no hay base de datos"; echo "start.sh: no hay base de datos" >&2; parar; exit 1; }
# Sin sustitucion de procesos (`< <(...)`) ni «here-strings»: el guest no tiene /dev/fd (el servicio moria con «/dev/fd/63: No such file»).
DATOS_DB=$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d['host'], d['port'], d['cluster'])" "$RUN_DB")
set -- $DATOS_DB
DB_ANFITRION=$1; DB_PUERTO=$2; NUTRITION_DB_CLUSTER=$3
fase base ok "$DB_ANFITRION:$DB_PUERTO"
agente_vigilar

# NUTRITION_BASE_ESTADO: el fichero con la base en la que se escribe; `nutrition:vigilar-base` lo cambia y la app se reconecta sin reiniciar.
ENTORNO_APP=("${ENTORNO_BASE[@]}" "${ENTORNO_ESTADO[@]}" "${ENTORNO_DB[@]}" DB_HOST="$DB_ANFITRION" DB_PORT="$DB_PUERTO" NUTRITION_DB_CLUSTER="$NUTRITION_DB_CLUSTER" NUTRITION_BASE_ESTADO="$RUN_DB")
app() { ( como app "${ENTORNO_APP[@]}" -- "$@" ); }

app cp -R /opt/agente-kit/. "$A/agente/"
app mkdir -p "$A/agente/base" "$A/agente/workspace" "$A/agente/encargos"
cd "$KIT_ADMIN"
# Varios dispositivos pueden arrancar a la vez contra la misma base: migrate se reintenta (la base puede estar arrancando
# o con las migraciones a medias por otro dispositivo).
for i in $(seq 1 60); do
    fase migraciones "en curso" "intento $i de 60"
    app php artisan migrate --force && break
    [ "$i" -lt 60 ] || { fallar migraciones "la base de datos no acepto las migraciones"; echo "start.sh: la base de datos no acepto las migraciones" >&2; parar; exit 1; }
    agente_vigilar
    sleep 3
done
fase migraciones ok
agente_vigilar
fase usuario "en curso"
app php artisan usuario:inicial
fase usuario ok

# --- Web: FrankenPHP (Caddy con PHP embebido; HTTP, el TLS lo pone el tunel), con el entorno de la app y nada mas ---
# El agente tiene un margen para responder; si no lo hace, el servicio arranca igual y la pagina avisa (el motivo, en app.log).
fase agente "en curso" "esperando /salud (hasta 120 s)"
for i in $(seq 1 120); do agente_sano && break; sleep 1; done
if agente_sano; then fase agente ok "responde en 127.0.0.1:9000"
else fase agente aviso "no responde en 127.0.0.1:9000 tras 120 s: no se podran generar menus; ver app.log"; fi

# La pagina de estado cede el :8000 a FrankenPHP, cuyo Caddyfile sigue sirviendo /estado, /estado.json y /app.log.
fase web "en curso" "FrankenPHP toma el puerto 8000"
kill "$PID_ESTADO" 2>/dev/null || true
wait "$PID_ESTADO" 2>/dev/null || true
lanzar como app "${ENTORNO_APP[@]}" -- frankenphp run --config /opt/nutrition/Caddyfile --adapter caddyfile
fase cola "en curso"
lanzar como app "${ENTORNO_APP[@]}" -- php artisan queue:work --queue=default --tries=1 --timeout=0 --sleep=3
# Worker propio del chat: una generacion de menu (horas) no debe dejar sin respuesta ni al bot basico.
lanzar como app "${ENTORNO_APP[@]}" -- php artisan queue:work --queue=chat --tries=1 --timeout=0 --sleep=1
for i in $(seq 1 60); do
    python3 -c "import urllib.request as u; u.urlopen('http://127.0.0.1:8000/estado.json', timeout=2)" 2>/dev/null && break
    sleep 1
done
fase web ok "escucha en :8000"
fase cola ok

# --- Vigilante de la base compartida: cada 300 s elige en que base escribir entre las de la red y guarda copias de seguridad de ella ---
# La primera vuelta es nada mas arrancar (no tras 300 s): asi la primera copia de seguridad de la base se toma en cuanto se conecta con ella.
lanzar como app "${ENTORNO_APP[@]}" -- sh -c 'while true; do php artisan nutrition:vigilar-base "$2" || true; sleep "$1"; done' vigilante 300 "$RUN_DB"

# --- Chat: traductor gRPC (usuario chat, entorno vacio). Necesita la web (127.0.0.1:8001, Caddyfile) y su base, ya arriba ---
fase chat "en curso" "arrancando el traductor en :50051"
lanzar como chat CHAT_SECRETO="$CHAT_SECRETO" CHAT_PUERTO=50051 CHAT_URL_INTERNA=http://127.0.0.1:8001 -- \
    /opt/chat-venv/bin/python /opt/chat/servidor.py
PID_CHAT=${PIDS[${#PIDS[@]}-1]}
for i in $(seq 1 30); do
    kill -0 "$PID_CHAT" 2>/dev/null || { fallar chat "el traductor termino al arrancar; ver app.log"; echo "start.sh: el traductor del chat murio" >&2; parar; exit 1; }
    python3 -c "import socket; socket.create_connection(('127.0.0.1', 50051), 2).close()" 2>/dev/null && break
    sleep 1
done
if python3 -c "import socket; socket.create_connection(('127.0.0.1', 50051), 2).close()" 2>/dev/null; then fase chat ok "gRPC en :50051"
else fase chat aviso "no acepta conexiones en :50051 tras 30 s; ver app.log"; fi
LISTO=true; escribir_estado
echo "start.sh: servicio listo"

# Si cualquier pieza muere, el servicio entero cae: asi nodo lo ve parado en vez de «sano» sin cola o sin agente.
wait -n
echo "start.sh: una pieza ha terminado; se detiene el servicio" >&2
parar
exit 1
