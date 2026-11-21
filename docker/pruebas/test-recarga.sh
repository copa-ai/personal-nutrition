#!/bin/bash
# Local test of docker/servicio/recarga.sh. It needs no PHP and no root.
# It uses a temporary directory, a fake php and web processes made by bucle.sh.
#   docker/pruebas/test-recarga.sh
set -u
AQUI=$(cd "$(dirname "$0")" && pwd)
SERVICIO="$AQUI/../servicio"
T=$(mktemp -d)
FALLOS=0
cleanup() { pkill -P $$ 2>/dev/null; kill "${BUCLES[@]}" 2>/dev/null; rm -rf "$T"; }
BUCLES=()
trap cleanup EXIT

export RECARGA_PROYECTO="$T/project" RECARGA_CODER="$T/coder" RECARGA_PRIVADO="$T/privado" RECARGA_ORIGINAL="$T/orig.tar.gz"
export RECARGA_COMO="" RECARGA_HOME="$T" RECARGA_DEBOUNCE=0 RECARGA_PERIODO=0 RECARGA_ESPERA_WEB=10 RECARGA_INTENTOS_SALUD=3
export RECARGA_UID=$(id -u) RECARGA_PATRON_WEB=sleep RECARGA_PATRON_COLA=sleep
export RECARGA_PATH="$T/bin:/usr/bin:/bin" RECARGA_SALUD="$T/bin/salud"
P=$RECARGA_PROYECTO; C=$RECARGA_CODER

mkdir -p "$T/bin" "$P/admin/app" "$P/admin/config" "$P/admin/routes" "$P/admin/resources" "$P/admin/database" "$P/admin/bootstrap" "$P/admin/lang/es" \
    "$P/admin/vendor/x" "$P/.coder" "$C"
echo '<?php // original' > "$P/admin/app/A.php"
echo '<?php return [];' > "$P/admin/config/app.php"
echo '<?php // app' > "$P/admin/bootstrap/app.php"
echo '<?php return [];' > "$P/admin/bootstrap/providers.php"
echo '<?php return [];' > "$P/admin/lang/es/x.php"
echo '{}' > "$P/admin/composer.lock"
echo 'v1' > "$P/admin/vendor/x/lib.php"
echo 'key' > "$P/.coder/authorized_keys"
echo x > "$P/admin/artisan"
tar -C "$P" -czf "$T/orig.tar.gz" .

# Fake php. Fails on -l when the file has SYNTAXERROR. Fails on route:list when any app file has BOOTERROR.
cat > "$T/bin/php" <<'PHP'
#!/bin/bash
echo "$*" >> "@T@/calls.log"
args=("$@")
for ((i = 0; i < ${#args[@]}; i++)); do
    [ "${args[$i]}" != "-l" ] || { grep -q SYNTAXERROR "${args[$((i+1))]}" && { echo "Parse error in ${args[$((i+1))]}"; exit 255; }; exit 0; }
done
case "$*" in
    *route:list*) grep -rq BOOTERROR "@P@/admin/app" && { echo "boot failed"; exit 1; }; echo '[]' ;;
esac
exit 0
PHP
# Fake health check. Fails when the file $T/salud-mal exists and app/A.php has HEALTHFAIL.
cat > "$T/bin/salud" <<EOF2
#!/bin/bash
grep -q HEALTHFAIL "$P/admin/app/A.php" && exit 1
exit 0
EOF2
sed -i "s#@T@#$T#; s#@P@#$P#" "$T/bin/php"
chmod +x "$T/bin/php" "$T/bin/salud"

for n in web cola-default cola-chat; do
    "$SERVICIO/bucle.sh" "$C/$n.pid" sleep 600 &
    BUCLES+=($!)
done
sleep 1

# shellcheck source=../servicio/recarga.sh
source "$SERVICIO/recarga.sh"

ok() { echo "ok   - $1"; }
mal() { echo "FAIL - $1"; FALLOS=$((FALLOS + 1)); }
verificar() { if eval "$2"; then ok "$1"; else mal "$1 ($2)"; fi; }
json() { python3 -c "import json,sys; print(json.load(open('$C/recarga.json')).get(sys.argv[1]))" "$1"; }
cambia() { sleep 0.05; printf '%s\n' "$2" >> "$1"; touch "$1"; }

preparar > /dev/null
verificar "status is valid JSON at start" "json estado >/dev/null"
verificar "start state is vigilando and equal to original" "[ \"\$(json estado)\" = vigilando ] && [ \"\$(json igual_original)\" = True ] && [ \"\$(json cambios)\" = 0 ]"
verificar "first snapshot exists" "[ -s '$T/privado/ultima-buena.tar.gz' ]"
verificar "snapshot has no vendor" "! tar -tzf '$T/privado/ultima-buena.tar.gz' | grep -q vendor"

# 1. No change: nothing happens.
PID_WEB=$(cat "$C/web.pid")
una_vuelta > /dev/null
verificar "no change, no restart" "[ \"\$(cat $C/web.pid)\" = $PID_WEB ]"

# 2. Good change: reload.
cambia "$P/admin/app/A.php" '// edit 1'
una_vuelta > /dev/null
verificar "good change reloads web" "[ \"\$(cat $C/web.pid)\" != $PID_WEB ]"
verificar "result ok" "[ \"\$(json resultado)\" = ok ] && [ \"\$(json recargas)\" = 1 ]"
verificar "optimize:clear ran" "grep -q optimize:clear '$T/calls.log'"
verificar "tree differs from original" "[ \"\$(json igual_original)\" = False ] && [ \"\$(json cambios)\" = 1 ]"
PID_WEB=$(cat "$C/web.pid")
una_vuelta > /dev/null
verificar "no second reload for the same change" "[ \"\$(cat $C/web.pid)\" = $PID_WEB ]"

# 3. Syntax error: no reload.
cambia "$P/admin/app/A.php" '// SYNTAXERROR'
una_vuelta > /dev/null
verificar "syntax error does not reload" "[ \"\$(cat $C/web.pid)\" = $PID_WEB ] && [ \"\$(json resultado)\" = error_sintaxis ] && [ \"\$(json estado)\" = error ]"
una_vuelta > /dev/null
verificar "syntax error is not retried without a new change" "[ \"\$(json resultado)\" = error_sintaxis ]"

# 4. Boot error: no reload.
sed -i '/SYNTAXERROR/d' "$P/admin/app/A.php"; cambia "$P/admin/app/A.php" '// BOOTERROR'
una_vuelta > /dev/null
verificar "boot error does not reload" "[ \"\$(cat $C/web.pid)\" = $PID_WEB ] && [ \"\$(json resultado)\" = error_arranque ]"

# 5. Health failure: rollback to the last good snapshot.
sed -i '/BOOTERROR/d' "$P/admin/app/A.php"; cambia "$P/admin/app/A.php" '// HEALTHFAIL'
una_vuelta > /dev/null 2>&1
verificar "health failure restores last good code" "! grep -q HEALTHFAIL '$P/admin/app/A.php' && grep -q 'edit 1' '$P/admin/app/A.php'"
verificar "result revertido" "[ \"\$(json resultado)\" = revertido ] && [ \"\$(json estado)\" = vigilando ]"

# 6. Auto reload OFF: changes wait for the flag.
echo off > "$C/auto-recarga"
PID_WEB=$(cat "$C/web.pid")
cambia "$P/admin/app/A.php" '// edit 2'
una_vuelta > /dev/null
verificar "auto off: no reload, state pendiente" "[ \"\$(cat $C/web.pid)\" = $PID_WEB ] && [ \"\$(json estado)\" = pendiente ] && [ \"\$(json pendiente)\" = True ] && [ \"\$(json auto)\" = False ]"
touch "$C/recargar-ahora"
una_vuelta > /dev/null
verificar "reload-now flag reloads and is removed" "[ \"\$(cat $C/web.pid)\" != $PID_WEB ] && [ ! -e $C/recargar-ahora ] && [ \"\$(json pendiente)\" = False ]"
rm -f "$C/auto-recarga"

# 6b. Auto OFF with a pending change: the watcher does not redo the change branch on each pass.
echo off > "$C/auto-recarga"
cambia "$P/admin/app/A.php" '// edit 2b'
una_vuelta > /dev/null
N1=$(huella_actual); LOG1=$(una_vuelta 2>&1; una_vuelta 2>&1; una_vuelta 2>&1)
verificar "auto off: later passes log nothing and stay pendiente" "[ -z \"\$LOG1\" ] && [ \"\$(json estado)\" = pendiente ]"
touch "$C/recargar-ahora"; una_vuelta > /dev/null
rm -f "$C/auto-recarga"

# 6c. providers.php and lang are watched.
PID_WEB=$(cat "$C/web.pid")
cambia "$P/admin/bootstrap/providers.php" '// provider'
una_vuelta > /dev/null
verificar "change in bootstrap/providers.php reloads" "[ \"\$(cat $C/web.pid)\" != $PID_WEB ]"
PID_WEB=$(cat "$C/web.pid")
cambia "$P/admin/lang/es/x.php" '// lang'
una_vuelta > /dev/null
verificar "change in lang reloads" "[ \"\$(cat $C/web.pid)\" != $PID_WEB ]"

# 6d. A forged PID file is not killed (the process is not the app web process).
PID_WEB=$(cat "$C/web.pid")
tail -f /dev/null & AJENO=$!
pid_valido "$AJENO" frankenphp && mal "pid_valido accepts a wrong command line" || ok "pid_valido rejects a wrong command line"
APP_UID_ANTES=$APP_UID; APP_UID=0
pid_valido "$PID_WEB" sleep && mal "pid_valido accepts a wrong owner" || ok "pid_valido rejects a wrong owner"
APP_UID=$APP_UID_ANTES
echo "$AJENO" > "$C/web.pid"
cambia "$P/admin/app/A.php" '// forged pid'
una_vuelta > /dev/null 2>&1
verificar "forged pid file: the foreign process stays alive" "kill -0 $AJENO"
verificar "forged pid file: the reload fails and is reported" "[ \"\$(json resultado)\" != ok ]"
kill "$AJENO" 2>/dev/null
echo "$PID_WEB" > "$C/web.pid"

# 6e. json_texto strips control characters.
printf '%s' "$(json_texto $'admin/app/a\x01\x7f\xff.php: "x" \\')" | python3 -c "import sys,json; json.loads('\"'+sys.stdin.read()+'\"')" 2>/dev/null \
    && ok "json_texto output is a valid JSON string" || mal "json_texto output is a valid JSON string"

# 7. Restore original: files return, extra files go, .coder and vendor are right.
cambia "$P/admin/app/A.php" '// edit 3'
echo '<?php // extra' > "$P/admin/app/Extra.php"
echo junk > "$P/admin/vendor/x/extra.php"
echo junk > "$P/admin/vendor/x/lib.php"
echo 'newkey' > "$P/.coder/authorized_keys"
touch "$C/restaurar-original"
una_vuelta > /dev/null
verificar "restore: file content back" "cmp -s '$P/admin/app/A.php' <(echo '<?php // original')"
verificar "restore: extra app file removed" "[ ! -e '$P/admin/app/Extra.php' ]"
verificar "restore: vendor fixed" "[ ! -e '$P/admin/vendor/x/extra.php' ] && [ \"\$(cat $P/admin/vendor/x/lib.php)\" = v1 ]"
verificar "restore: .coder kept" "[ \"\$(cat $P/.coder/authorized_keys)\" = newkey ]"
verificar "restore: result and equal to original" "[ \"\$(json resultado)\" = restaurado_original ] && [ \"\$(json igual_original)\" = True ] && [ \"\$(json cambios)\" = 0 ] && [ ! -e $C/restaurar-original ]"

# 8. A symlink in place of a watched path or of its parent: the rollback does not write through it.
mkdir -p "$T/fuera"
rm -rf "$P/admin/app"; ln -s "$T/fuera" "$P/admin/app"
extraer_y_podar "$T/privado/ultima-buena.tar.gz" "${VIGILADOS[@]}"
verificar "symlinked watched path: nothing written outside" "[ -z \"\$(ls -A $T/fuera)\" ] && [ -d $P/admin/app ] && [ ! -L $P/admin/app ]"
mv "$P/admin" "$T/admin.real"; ln -s "$T/admin.real" "$P/admin"
extraer_y_podar "$T/privado/ultima-buena.tar.gz" "${VIGILADOS[@]}"
verificar "symlinked parent (admin): replaced by a real directory" "[ -d $P/admin ] && [ ! -L $P/admin ]"

# 9. Setuid bits are cleared and unreadable files become readable.
echo x > "$P/admin/app/Suid.php"; chmod 4755 "$P/admin/app/Suid.php"
echo y > "$P/admin/app/Priv.php"; chmod 600 "$P/admin/app/Priv.php"
sanear_modos
verificar "sanear_modos clears setuid and opens mode 600" "[ ! -u $P/admin/app/Suid.php ] && [ -r $P/admin/app/Priv.php ] && [ \"\$(stat -c %a $P/admin/app/Priv.php)\" = 644 ]"

# 9b. A snapshot is not saved when a watched path holds a symlink.
cp "$T/privado/ultima-buena.tar.gz" "$T/antes.tar.gz"
ln -s "$T/fuera" "$P/admin/app/enlace"
marca=$(mktemp "$T/privado/marca.XXXXXX")
guardar_copia "$marca" > /dev/null 2>&1; rc=$?
rm -f "$P/admin/app/enlace" "$marca"
verificar "snapshot with a symlink: refused, old snapshot kept" "[ $rc -ne 0 ] && cmp -s '$T/privado/ultima-buena.tar.gz' '$T/antes.tar.gz'"

# 10. A size over the cap refuses the reload.
RECARGA_MAX_BYTES=10 MAX_BYTES=10
cambia "$P/admin/app/A.php" '// big'
PID_WEB=$(cat "$C/web.pid")
una_vuelta > /dev/null 2>&1
verificar "size over the cap: no reload and an error" "[ \"\$(cat $C/web.pid)\" = $PID_WEB ] && [ \"\$(json resultado)\" = error_arranque ]"
MAX_BYTES=209715200

echo
if [ "$FALLOS" -eq 0 ]; then echo "All tests passed."; else echo "$FALLOS test(s) failed."; exit 1; fi
