#!/bin/bash
# Reload watcher. It runs as root, started by start.sh.
# It reloads the Laravel app when a Coder child changes the shared code.
# The Coder sends nothing. This script polls the share by itself.
#
# Flow of one reload:
#   1. Poll a fingerprint (path, size, mtime) of the watched paths every RECARGA_PERIODO seconds.
#   2. After a change, wait for a quiet time (debounce).
#   3. Check the code: php -l on the files newer than the last good reload, then a boot check.
#      If a check fails, do NOT reload. The old code stays in memory. The error goes to the log and to recarga.json.
#   4. Run optimize:clear, restart FrankenPHP and the queue workers (kill of the child: bucle.sh restarts it).
#   5. Health check (HTTP 200). If it fails, restore the last good snapshot, reload again and report.
#   6. After a good reload, save a snapshot (tar.gz of the watched paths, no vendor). One snapshot only.
# php, artisan and php -l run as the app user with an empty environment (setpriv + env -i).
#
# FILES (the panel step reads and writes these; names are part of the contract)
# Directory $CODER (default /var/lib/nutrition/coder, owner app, mode 750):
#   auto-recarga        Written by the panel. Auto reload is ON when the file is missing.
#                       The content 0, off, no or false (any case) means OFF. Any other content means ON.
#                       When OFF, the watcher ignores changes until the flag recargar-ahora exists.
#   recargar-ahora      Flag. The panel creates it (any content). The watcher removes it and runs one reload.
#   restaurar-original  Flag. The panel creates it. The watcher removes it, restores the original tar, then reloads.
#   recarga.json        Status. Written by the watcher, through the app user, by atomic rename. Mode 644.
#   web.pid, cola-default.pid, cola-chat.pid   PID files from bucle.sh. The app user writes them, so the watcher does not trust them:
#                       it kills a PID only if the process belongs to the app user and its command line is frankenphp or queue:work.
# Directory $PRIVADO (default /var/lib/nutrition/coder-root, root only, mode 700):
#   ultima-buena.tar.gz  The last good snapshot.   ultima-buena.stamp  mtime = start of the last good reload.
#   ultima-buena.manifest  "sha256  path" lines of the watched paths at the last good reload. php -l runs on the files that differ from it.
#   original.manifest    "sha256  path" lines of the watched paths in the original tar.
# The private directory is not under $CODER because the app user owns $CODER and could plant symlinks there.
#
# recarga.json fields:
#   estado         vigilando | pendiente | revisando | recargando | restaurando | error
#                  (pendiente = changes found and not loaded, auto reload is OFF)
#   resultado      ninguno | ok | error_sintaxis | error_arranque | error_salud | revertido | restaurado_original
#                  (the result of the last reload attempt)
#   mensaje        Short text of the last result (Spanish or the error text, one line, max 400 characters)
#   fecha          UTC time of the last result, ISO 8601. fecha_unix is the same time in seconds.
#   auto           true | false. State of auto reload.
#   pendiente      true | false. Changes exist that the running app does not use yet.
#   cambios        Count of watched files that differ from the original tar (changed, added or removed).
#   arbol_vivo     sha256 of the manifest of the live watched paths.
#   arbol_original sha256 of the manifest of the original watched paths.
#   igual_original true when arbol_vivo equals arbol_original.
#   ultima_buena   UTC time of the last good reload (ISO 8601) or null.
#   recargas       Count of good reloads since this watcher started.
#
# Security rules for this root script (the app user and the Coder can write the share and $CODER):
#   - Before a restore or a rollback, it removes symlinks in the watched paths and their parents, so tar cannot write outside the share.
#   - After an extract and before each check, it clears setuid and setgid bits and makes the watched paths readable.
#   - It refuses to hash, copy or reload when the watched paths are larger than RECARGA_MAX_BYTES (default 200 MiB).
#   - Text in recarga.json passes through json_texto: printable ASCII only.
#
# For tests, the paths and commands can change with the RECARGA_* variables below.
set -u

PROYECTO=${RECARGA_PROYECTO:-/opt/nutrition/project}
ADMIN="$PROYECTO/admin"
CODER=${RECARGA_CODER:-/var/lib/nutrition/coder}
PRIVADO=${RECARGA_PRIVADO:-/var/lib/nutrition/coder-root}
ORIGINAL_TAR=${RECARGA_ORIGINAL:-/opt/nutrition/project.orig.tar.gz}
PERIODO=${RECARGA_PERIODO:-5}
DEBOUNCE=${RECARGA_DEBOUNCE:-3}
ESPERA_WEB=${RECARGA_ESPERA_WEB:-40}      # seconds to wait for the new web process
INTENTOS_SALUD=${RECARGA_INTENTOS_SALUD:-30}  # health tries, one per second
URL_SALUD=${RECARGA_URL_SALUD:-http://127.0.0.1:8000/up}
# Command prefix that drops root. Empty in tests.
if [ "${RECARGA_COMO+set}" = set ]; then COMO=$RECARGA_COMO; else COMO="setpriv --reuid=app --regid=app --init-groups"; fi
PATH_APP=${RECARGA_PATH:-/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin}
# Names of the environment variables the app needs (from start.sh). The values are read from this process.
NOMBRES_ENTORNO=${RECARGA_NOMBRES:-}
MAX_BYTES=${RECARGA_MAX_BYTES:-209715200}   # size cap of the watched paths
# Owner and command line that a PID must have before the watcher kills it.
APP_UID=${RECARGA_UID:-$(id -u app 2> /dev/null)}
PATRON_WEB=${RECARGA_PATRON_WEB:-frankenphp}
PATRON_COLA=${RECARGA_PATRON_COLA:-queue:work}

# Watched paths, relative to $PROYECTO.
# Laravel loads these files and opcache keeps them (validate_timestamps=0), so a change needs a reload.
VIGILADOS=(admin/app admin/config admin/routes admin/resources admin/database admin/lang admin/bootstrap/app.php admin/bootstrap/providers.php admin/composer.lock)
# Paths where a restore of the original removes files that the tar does not hold.
PODAR_ORIGINAL=("${VIGILADOS[@]}" admin/vendor)

RUTAS=() HUELLA_VISTA=""
ESTADO=vigilando RESULTADO=ninguno MENSAJE="" FECHA_UNIX=0
PENDIENTE=false HUELLA_BASE="" ULTIMA_BUENA="" RECARGAS=0 CAMBIOS=0 ARBOL_VIVO="" ARBOL_ORIGINAL=""

log() { echo "[$(date -u +%T)] recarga: $*"; }

# --- Run commands as the app user -------------------------------------------------------------------------------------

# Run a command as the app user with an empty environment.
como_simple() { $COMO env -i PATH="$PATH_APP" "$@"; }

# Run a command in admin/ as the app user with the app environment (for php and artisan).
en_app() {
    local vars=() n home
    for n in $NOMBRES_ENTORNO; do vars+=("$n=${!n:-}"); done
    home=${RECARGA_HOME:-$(getent passwd app | cut -d: -f6)}
    ( cd "$ADMIN" && exec $COMO env -i PATH="$PATH_APP" HOME="${home:-/tmp}" "${vars[@]}" "$@" )
}

# --- Status file ------------------------------------------------------------------------------------------------------

# Make a text safe for a JSON string: one line, no quotes, no backslashes, max 400 characters.
json_texto() {
    local t=$1
    t=${t//$'\n'/ }; t=${t//$'\r'/ }; t=${t//$'\t'/ }
    # Keep printable ASCII only. A file name from the Coder can hold any byte, and one bad byte makes the JSON invalid.
    t=$(printf '%s' "$t" | LC_ALL=C tr -c ' -~' '?')
    t=${t//\\//}; t=${t//\"/\'}
    printf '%s' "${t:0:400}"
}

auto_activo() {
    local v
    v=""
    [ ! -r "$CODER/auto-recarga" ] || v=$(tr -d ' \n\r\t' < "$CODER/auto-recarga" | tr 'A-Z' 'a-z')
    case "$v" in 0 | off | no | false) return 1 ;; *) return 0 ;; esac
}

# Write recarga.json by an atomic rename. The app user writes it, so a symlink planted in $CODER cannot hurt root.
escribir_estado() {
    local auto=true igual=false ultima=null tmp="$CODER/recarga.json.tmp"
    auto_activo || auto=false
    [ -z "$ARBOL_VIVO" ] || [ "$ARBOL_VIVO" != "$ARBOL_ORIGINAL" ] || igual=true
    [ -z "$ULTIMA_BUENA" ] || ultima="\"$ULTIMA_BUENA\""
    printf '{"estado":"%s","resultado":"%s","mensaje":"%s","fecha":"%s","fecha_unix":%s,"auto":%s,"pendiente":%s,"cambios":%s,"arbol_vivo":"%s","arbol_original":"%s","igual_original":%s,"ultima_buena":%s,"recargas":%s}\n' \
        "$ESTADO" "$RESULTADO" "$(json_texto "$MENSAJE")" "$([ "$FECHA_UNIX" -eq 0 ] || date -u -d "@$FECHA_UNIX" +%FT%TZ)" "$FECHA_UNIX" \
        "$auto" "$PENDIENTE" "$CAMBIOS" "$ARBOL_VIVO" "$ARBOL_ORIGINAL" "$igual" "$ultima" "$RECARGAS" \
        | como_simple tee "$tmp" > /dev/null \
        && como_simple chmod 644 "$tmp" && como_simple mv -f "$tmp" "$CODER/recarga.json"
}

# Set the result of an attempt and write the status.
resultado() {   # resultado <estado> <resultado> <mensaje>
    ESTADO=$1; RESULTADO=$2; MENSAJE=$3; FECHA_UNIX=$(date +%s)
    log "$2: $3"
    escribir_estado
}

# --- Safety of the shared tree ----------------------------------------------------------------------------------------

# Return 0 if the watched paths are not larger than MAX_BYTES. The Coder could fill the disk of nutrition through them.
tamano_ok() {
    local total
    fijar_rutas "$PROYECTO"
    [ "${#RUTAS[@]}" -gt 0 ] || return 0
    total=$( ( cd "$PROYECTO" && du -sb "${RUTAS[@]}" 2> /dev/null ) | awk '{s += $1} END {print s + 0}')
    [ "$total" -le "$MAX_BYTES" ]
}

# Make the watched paths readable for the app user and clear setuid and setgid bits in the whole share.
# The Coder is root in its VM and can set any mode. A new file can have mode 600, and then php -l as app fails.
sanear_modos() {
    fijar_rutas "$PROYECTO"
    [ "${#RUTAS[@]}" -eq 0 ] || ( cd "$PROYECTO" && chmod -R a+rX "${RUTAS[@]}" 2> /dev/null ) || true
    find "$PROYECTO" -xdev -type f -perm /6000 -exec chmod ug-s {} + 2> /dev/null || true
}

# Remove symlinks that tar could follow when it extracts as root: the parents of the watched paths and every link inside the given paths.
# rm and find -delete never follow a link. Then tar creates the real directories again.
quitar_enlaces() {   # quitar_enlaces <ruta...>
    local r
    for r in admin admin/bootstrap; do [ ! -L "$PROYECTO/$r" ] || rm -f -- "$PROYECTO/$r"; done
    for r in "$@"; do
        if [ -L "$PROYECTO/$r" ]; then rm -f -- "$PROYECTO/$r"
        elif [ -d "$PROYECTO/$r" ]; then find "$PROYECTO/$r" -type l -delete 2> /dev/null || true
        fi
    done
}

# --- Fingerprint and manifests ----------------------------------------------------------------------------------------

# Fill the array RUTAS with the watched paths that exist in <base>. A path can be missing.
fijar_rutas() {   # fijar_rutas <base>
    local r
    RUTAS=()
    for r in "${VIGILADOS[@]}"; do [ ! -e "$1/$r" ] || RUTAS+=("$r"); done
}

# Cheap fingerprint: path, size and mtime of each file. The poll uses this.
huella_actual() {
    fijar_rutas "$PROYECTO"
    [ "${#RUTAS[@]}" -gt 0 ] || { echo vacia; return; }
    ( cd "$PROYECTO" && find "${RUTAS[@]}" -type f -printf '%p %s %T@\n' | LC_ALL=C sort | sha256sum | cut -d' ' -f1 )
}

# Manifest of file contents: "sha256  path" lines, sorted by path.
manifiesto() {   # manifiesto <base> <salida>
    fijar_rutas "$1"
    : > "$2"
    [ "${#RUTAS[@]}" -gt 0 ] || return 0
    ( cd "$1" && find "${RUTAS[@]}" -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum ) > "$2"
}

# Make the manifest of the original tar. It is cached until the tar changes.
manifiesto_original() {
    local tmp
    [ ! -s "$PRIVADO/original.manifest" ] || [ "$ORIGINAL_TAR" -nt "$PRIVADO/original.manifest" ] || return 0
    [ -f "$ORIGINAL_TAR" ] || { : > "$PRIVADO/original.manifest"; return 0; }
    tmp=$(mktemp -d "$PRIVADO/orig.XXXXXX") || return 1
    tar -xzf "$ORIGINAL_TAR" -C "$tmp" "${VIGILADOS[@]/#/./}" 2> /dev/null \
        || tar -xzf "$ORIGINAL_TAR" -C "$tmp" "${VIGILADOS[@]}" 2> /dev/null || true
    manifiesto "$tmp" "$PRIVADO/original.manifest"
    rm -rf "$tmp"
}

# Update the content hashes and the count of changed files.
actualizar_hashes() {
    local vivo="$PRIVADO/vivo.manifest"
    manifiesto_original
    if ! tamano_ok; then
        log "aviso: las rutas vigiladas pasan de $MAX_BYTES bytes: no se calculan los hashes"
        ARBOL_VIVO=""; return 0
    fi
    manifiesto "$PROYECTO" "$vivo"
    ARBOL_VIVO=$(sha256sum < "$vivo" | cut -d' ' -f1)
    ARBOL_ORIGINAL=$(sha256sum < "$PRIVADO/original.manifest" | cut -d' ' -f1)
    LC_ALL=C sort "$vivo" > "$vivo.s"; LC_ALL=C sort "$PRIVADO/original.manifest" > "$PRIVADO/original.manifest.s"
    CAMBIOS=$(LC_ALL=C comm -3 "$vivo.s" "$PRIVADO/original.manifest.s" | sed 's/^\t*//; s/^[0-9a-f]\{64\}  //' | sort -u | wc -l | tr -d ' ')
}

# --- Checks before a reload -------------------------------------------------------------------------------------------

# Run php -l on the PHP files whose content differs from the last good reload. The test is a hash, not a time:
# tar, cp -p and rsync -t can set an old mtime. With no last good manifest, check every PHP file.
# Print the first error. Return 1 on a syntax error.
revisar_sintaxis() {
    local lista nuevo f salida rc=0
    fijar_rutas "$PROYECTO"
    [ "${#RUTAS[@]}" -gt 0 ] || return 0
    lista=$(mktemp "$PRIVADO/php.XXXXXX") || return 0
    nuevo=$(mktemp "$PRIVADO/php.XXXXXX") || { rm -f "$lista"; return 0; }
    manifiesto "$PROYECTO" "$nuevo"
    if [ -f "$PRIVADO/ultima-buena.manifest" ]; then
        LC_ALL=C sort "$nuevo" > "$nuevo.s"
        LC_ALL=C sort "$PRIVADO/ultima-buena.manifest" > "$lista.b"
        LC_ALL=C comm -13 "$lista.b" "$nuevo.s" | sed 's/^[0-9a-f]\{64\}  //' | grep '\.php$' > "$lista" || true
        rm -f "$nuevo.s" "$lista.b"
    else
        sed 's/^[0-9a-f]\{64\}  //' "$nuevo" | grep '\.php$' > "$lista" || true
    fi
    while IFS= read -r f; do
        if ! salida=$(en_app php -d display_errors=0 -l "$PROYECTO/$f" 2>&1); then
            ERROR_SINTAXIS="$f: $salida"; rc=1; break
        fi
    done < "$lista"
    rm -f "$lista" "$nuevo"
    return $rc
}

# Boot check: "artisan route:list --json" boots the app, runs every service provider and loads every route file.
# It needs no database. A cheap "artisan --version" does not boot the providers, so it finds too little.
# start.sh never runs route:cache, so route:list reads the route files and not a stale cache.
revisar_arranque() {
    local salida rc=0
    salida=$(en_app php artisan route:list --json --no-interaction 2>&1) || rc=$?
    ERROR_ARRANQUE=${salida: -400}
    return $rc
}

# --- Restart ----------------------------------------------------------------------------------------------------------

# Return 0 if the process <pid> belongs to the app user and its command line holds <patron>.
# The app user (and the Coder code that runs as app) writes the PID files. Root must not kill a PID that it reads there with no check.
pid_valido() {   # pid_valido <pid> <patron>
    local uid cmd
    [ -n "$APP_UID" ] || return 1
    uid=$(awk '/^Uid:/ {print $2}' "/proc/$1/status" 2> /dev/null) || return 1
    [ "$uid" = "$APP_UID" ] || return 1
    cmd=$(tr '\0' ' ' < "/proc/$1/cmdline" 2> /dev/null) || return 1
    case "$cmd" in *"$2"*) return 0 ;; *) return 1 ;; esac
}

# Print the PID in $CODER/<name>.pid if it passes pid_valido. Print nothing if not.
leer_pid() {   # leer_pid <name> <patron>
    local pid
    pid=$( { tr -dc '0-9' < "$CODER/$1.pid"; } 2> /dev/null || true)
    [ -z "$pid" ] || ! pid_valido "$pid" "$2" || echo "$pid"
    return 0
}

# Restart the web server. Return 1 if the old process does not stop or no new process starts: the old code can still run then.
reiniciar_web() {
    local viejo="" nuevo i
    for i in 1 2 3 4 5 6 7 8 9 10; do   # bucle.sh can be in its sleep between two processes
        viejo=$(leer_pid web "$PATRON_WEB"); [ -z "$viejo" ] || break
        sleep 1
    done
    [ -n "$viejo" ] || { log "aviso: no hay un proceso web que reiniciar"; return 1; }
    kill -TERM "$viejo" 2> /dev/null || true
    for i in $(seq 1 "$ESPERA_WEB"); do
        if ! kill -0 "$viejo" 2> /dev/null; then   # the old process is gone: wait for the new one
            nuevo=$(leer_pid web "$PATRON_WEB")
            [ -z "$nuevo" ] || [ "$nuevo" = "$viejo" ] || return 0
        fi
        sleep 1
    done
    log "aviso: el proceso web viejo no termino o no hay uno nuevo tras ${ESPERA_WEB}s"
    return 1
}

# Ask the queue workers to stop. A worker ends its current job first. Then bucle.sh starts it with the new code.
reiniciar_colas() {
    local c pid
    for c in cola-default cola-chat; do
        pid=$(leer_pid "$c" "$PATRON_COLA")
        [ -z "$pid" ] || kill -TERM "$pid" 2> /dev/null || true
    done
}

salud_ok() {
    if [ -n "${RECARGA_SALUD:-}" ]; then $RECARGA_SALUD
    else python3 -c "import urllib.request as u,sys; sys.exit(0 if u.urlopen('$URL_SALUD', timeout=3).status == 200 else 1)" 2> /dev/null
    fi
}

esperar_salud() {
    local i
    for i in $(seq 1 "$INTENTOS_SALUD"); do
        salud_ok && return 0
        sleep 1
    done
    return 1
}

# Clear the caches and restart web and queue. Return 0 when the app is healthy afterwards.
aplicar_codigo() {
    en_app php artisan optimize:clear --no-interaction > /dev/null 2>&1 || log "aviso: optimize:clear fallo (continua)"
    reiniciar_web || return 1
    reiniciar_colas
    esperar_salud
}

# --- Snapshots and restore --------------------------------------------------------------------------------------------

guardar_copia() {   # guardar_copia <marca>: snapshot of the watched paths (no vendor) and the stamp of this reload
    fijar_rutas "$PROYECTO"
    # A snapshot with a symlink could write outside the share when root extracts it: a link entry, then a file through it.
    if [ -n "$( cd "$PROYECTO" && find "${RUTAS[@]}" -type l -print -quit 2> /dev/null )" ]; then
        log "aviso: hay enlaces simbolicos en las rutas vigiladas: no se guarda la copia"; return 1
    fi
    tar -C "$PROYECTO" -czf "$PRIVADO/ultima-buena.tar.gz.tmp" "${RUTAS[@]}" 2> /dev/null \
        && mv -f "$PRIVADO/ultima-buena.tar.gz.tmp" "$PRIVADO/ultima-buena.tar.gz" || { log "aviso: no se pudo guardar la copia"; return 1; }
    manifiesto "$PROYECTO" "$PRIVADO/ultima-buena.manifest"
    mv -f "$1" "$PRIVADO/ultima-buena.stamp"
    ULTIMA_BUENA=$(date -u -r "$PRIVADO/ultima-buena.stamp" +%FT%TZ)
}

# Extract a tar over the project and remove the files in the given paths that the tar does not hold.
extraer_y_podar() {   # extraer_y_podar <tar> <ruta...>
    local tarfile=$1 listado vivos r; shift
    listado=$(mktemp "$PRIVADO/tar.XXXXXX"); vivos=$(mktemp "$PRIVADO/vivos.XXXXXX")
    quitar_enlaces "$@"
    tar -C "$PROYECTO" -xzf "$tarfile" --no-same-owner --exclude=./.coder --exclude=.coder || { rm -f "$listado" "$vivos"; return 1; }
    tar -tzf "$tarfile" | grep -v '/$' | sed 's#^\./##' | LC_ALL=C sort -u > "$listado"
    ( cd "$PROYECTO" && for r in "$@"; do [ ! -e "$r" ] || find "$r" \( -type f -o -type l \) -print; done ) | LC_ALL=C sort -u > "$vivos"
    # Files that exist now and are not in the tar.
    ( cd "$PROYECTO" && comm -13 "$listado" "$vivos" | while IFS= read -r r; do rm -f -- "$r"; done )
    rm -f "$listado" "$vivos"
    sanear_modos
}

# --- Reload cycle -----------------------------------------------------------------------------------------------------

# Check the code. Return 0 if it is good. On failure, write the status and return 1.
verificar_codigo() {
    ERROR_SINTAXIS=""; ERROR_ARRANQUE=""
    if ! revisar_sintaxis; then
        resultado error "error_sintaxis" "Error de sintaxis, no se recarga: $ERROR_SINTAXIS"; return 1
    fi
    if ! revisar_arranque; then
        resultado error "error_arranque" "La aplicacion no arranca con el codigo nuevo, no se recarga: $ERROR_ARRANQUE"; return 1
    fi
}

# Return the app to the last good snapshot. Return 0 when the app is healthy again.
revertir() {
    [ -f "$PRIVADO/ultima-buena.tar.gz" ] || return 2
    extraer_y_podar "$PRIVADO/ultima-buena.tar.gz" "${VIGILADOS[@]}" || return 1
    aplicar_codigo
}

# One reload. $1 is the reason (texto for the log). $2 is "original" after a restore of the original (no rollback to a snapshot).
ciclo_recarga() {
    local motivo=$1 origen=${2:-} marca rc intento
    marca=$(mktemp "$PRIVADO/marca.XXXXXX") || return 1
    HUELLA_BASE=$(huella_actual)
    ESTADO=revisando; escribir_estado
    if ! tamano_ok; then
        rm -f "$marca"; PENDIENTE=false; actualizar_hashes
        resultado error error_arranque "Las rutas vigiladas pasan de $((MAX_BYTES / 1048576)) MiB: no se recarga"; return 1
    fi
    log "revisando el codigo ($motivo)"
    # A change during the checks would load files that nobody checked: take the fingerprint again before the restart and check again.
    for intento in 1 2 3; do
        HUELLA_BASE=$(huella_actual)
        sanear_modos
        if ! verificar_codigo; then
            rm -f "$marca"; PENDIENTE=false; actualizar_hashes; escribir_estado; return 1
        fi
        [ "$(huella_actual)" != "$HUELLA_BASE" ] || break
        log "el codigo cambio durante la revision: se revisa otra vez"
    done
    ESTADO=recargando; escribir_estado
    log "recargando ($motivo)"
    if aplicar_codigo; then
        guardar_copia "$marca" || rm -f "$marca"
        RECARGAS=$((RECARGAS + 1)); PENDIENTE=false; actualizar_hashes
        if [ "$origen" = original ]; then resultado vigilando restaurado_original "Codigo original restaurado y recargado"
        else resultado vigilando ok "Recarga correcta ($motivo)"; fi
        return 0
    fi
    rm -f "$marca"
    # The new code passed the checks but the app is not healthy. Go back to the last good snapshot.
    if [ "$origen" = original ]; then
        PENDIENTE=false; actualizar_hashes
        resultado error error_salud "La aplicacion no responde tras restaurar el codigo original"; return 1
    fi
    log "la aplicacion no responde: se restaura la ultima copia buena"
    revertir; rc=$?
    HUELLA_BASE=$(huella_actual); PENDIENTE=false; actualizar_hashes
    case $rc in
        0) resultado vigilando revertido "El codigo nuevo no respondia (salud). Se restauro la ultima copia buena." ;;
        2) resultado error error_salud "El codigo nuevo no responde y no hay copia buena que restaurar" ;;
        *) resultado error error_salud "El codigo nuevo no responde y la copia buena tampoco arranca" ;;
    esac
    return 1
}

# Restore the original tar over the share, then reload.
restaurar_original() {
    [ -f "$ORIGINAL_TAR" ] || { resultado error error_arranque "No existe $ORIGINAL_TAR"; return 1; }
    ESTADO=restaurando; escribir_estado
    log "restaurando el codigo original"
    # Remove every symlink in the share (not in .coder): tar runs as root and the Coder could point a link outside the share.
    find "$PROYECTO" -path "$PROYECTO/.coder" -prune -o -type l -delete 2> /dev/null || true
    if ! extraer_y_podar "$ORIGINAL_TAR" "${PODAR_ORIGINAL[@]}"; then
        resultado error error_arranque "No se pudo extraer el codigo original"; return 1
    fi
    # The restored tree is new. Check every file again.
    rm -f "$PRIVADO/ultima-buena.stamp" "$PRIVADO/ultima-buena.manifest"
    ciclo_recarga "restaurar original" original
}

# --- Main loop --------------------------------------------------------------------------------------------------------

# Wait until the fingerprint is stable for one debounce period. Print the stable fingerprint.
estabilizar() {
    local h=$1 n i
    for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
        sleep "$DEBOUNCE"
        n=$(huella_actual)
        [ "$n" != "$h" ] || break
        h=$n
    done
    echo "$h"
}

# The node can replace the share with a new mount when the first child starts. The root of the new mount can be mode 700 and owned by
# root. Then the app user cannot enter the project, and the web server answers 403 for every path. The watcher is root: it opens the root
# and the tree again. It writes one log line for each time that the problem starts.
ACCESO_AVISADO=false
asegurar_acceso() {
    local modo
    modo=$(stat -c %a "$PROYECTO" 2> /dev/null) || return 0
    if [ $(( 8#$modo & 5 )) -eq 5 ] && { [ ! -e "$ADMIN/artisan" ] || como_simple test -r "$ADMIN/artisan"; }; then
        ACCESO_AVISADO=false; return 0
    fi
    if [ "$ACCESO_AVISADO" != true ]; then
        log "aviso: el usuario app no puede leer $PROYECTO (modo $modo): se abre el acceso"; ACCESO_AVISADO=true
    fi
    chmod a+rx "$PROYECTO" 2> /dev/null || true
    ( cd "$PROYECTO" && chmod -R a+rX . 2> /dev/null ) || true
}

# One pass of the loop.
una_vuelta() {
    local h forzar=false ciclo=false
    asegurar_acceso
    if [ -e "$CODER/restaurar-original" ]; then
        rm -f "$CODER/restaurar-original"
        restaurar_original
        return
    fi
    h=$(huella_actual)
    if [ "$h" != "$HUELLA_VISTA" ]; then
        h=$(estabilizar "$h"); HUELLA_VISTA=$h
        if [ "$h" != "$HUELLA_BASE" ]; then PENDIENTE=true; else PENDIENTE=false; fi
        actualizar_hashes
        log "cambios en el codigo compartido ($CAMBIOS ficheros distintos del original)"
        if [ "$PENDIENTE" = true ] && ! auto_activo; then ESTADO=pendiente; else ESTADO=vigilando; fi
        escribir_estado
    fi
    if [ -e "$CODER/recargar-ahora" ]; then rm -f "$CODER/recargar-ahora"; forzar=true; fi
    if [ "$forzar" = true ]; then ciclo=true; ciclo_recarga "ahora"
    elif [ "$PENDIENTE" = true ] && auto_activo; then ciclo=true; ciclo_recarga "automatica"
    fi
    # After a cycle, a later difference from the fingerprint of that cycle is a new change. With no cycle (auto reload OFF),
    # keep the fingerprint that was seen: the pending change must not trigger the debounce and the hashes again on each pass.
    [ "$ciclo" != true ] || HUELLA_VISTA=$HUELLA_BASE
    return 0
}

preparar() {
    mkdir -p "$PRIVADO" && chmod 700 "$PRIVADO"
    [ -f "$PRIVADO/ultima-buena.stamp" ] && ULTIMA_BUENA=$(date -u -r "$PRIVADO/ultima-buena.stamp" +%FT%TZ) || ULTIMA_BUENA=""
    HUELLA_BASE=$(huella_actual); HUELLA_VISTA=$HUELLA_BASE
    actualizar_hashes
    # The code of the first start is good if the app answers. Keep it as the first snapshot.
    if [ ! -f "$PRIVADO/ultima-buena.tar.gz" ] && esperar_salud; then
        local marca; marca=$(mktemp "$PRIVADO/marca.XXXXXX") && guardar_copia "$marca"
    fi
    ESTADO=vigilando; escribir_estado
    log "vigilando $PROYECTO cada ${PERIODO}s"
}

principal() {
    preparar
    while true; do
        una_vuelta
        sleep "$PERIODO"
    done
}

# Run the loop only when the file runs as a script. A test can source it and call the functions.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then principal; fi
