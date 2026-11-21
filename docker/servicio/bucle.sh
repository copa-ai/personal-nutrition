#!/bin/sh
# Restartable loop for one piece of the service (web or queue worker).
# Usage: bucle.sh <pidfile> <command> [args...]
# The loop writes the PID of the child (not its own PID) to <pidfile>.
# A kill of the child restarts it after one second.
# A TERM or INT on the loop stops the child, removes <pidfile> and ends the loop.
pidfile=$1
shift
hijo=

parar() {
    trap - TERM INT
    [ -z "$hijo" ] || kill "$hijo" 2>/dev/null
    wait 2>/dev/null
    rm -f "$pidfile"
    exit 0
}
trap parar TERM INT

while true; do
    "$@" &
    hijo=$!
    echo "$hijo" > "$pidfile.tmp" && mv -f "$pidfile.tmp" "$pidfile"
    wait "$hijo" || true
    hijo=
    rm -f "$pidfile"
    sleep 1
done
