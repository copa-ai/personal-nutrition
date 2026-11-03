#!/bin/sh
# Entrypoint de nutrition-postgres. Lo lanza personal-nutrition (o quien lo instancie) pasando DB_USERNAME, DB_PASSWORD,
# DB_DATABASE y NUTRITION_DB_CLUSTER (la clave publica de la red: ver service.json -> network).
set -eu

DB_USERNAME="${DB_USERNAME:-laravel}"
DB_DATABASE="${DB_DATABASE:-personal_nutrition}"
if [ -z "${DB_PASSWORD:-}" ]; then
    echo "[nutrition-postgres] DB_PASSWORD es obligatoria." >&2
    exit 1
fi

PGDATA="/var/lib/postgresql/nutrition"
PG_BIN="$(ls -d /usr/lib/postgresql/*/bin | sort -V | tail -n 1)"
RUN="gosu postgres"

mkdir -p "$PGDATA"
chown postgres:postgres "$PGDATA"
chmod 700 "$PGDATA"

if [ ! -s "$PGDATA/PG_VERSION" ]; then
    echo "[nutrition-postgres] Inicializando clúster en $PGDATA" >&2
    # Local por socket sin contraseña (solo dentro de esta microVM); por red, scram.
    $RUN "$PG_BIN/initdb" -D "$PGDATA" -U "$DB_USERNAME" --encoding=UTF8 \
        --auth-local=trust --auth-host=scram-sha-256 >/dev/null
    # personal-nutrition llega desde otra IP del nodo; el aislamiento lo da el propio nodo.
    {
        echo "host all all 0.0.0.0/0 scram-sha-256"
        echo "host all all ::/0 scram-sha-256"
    } >> "$PGDATA/pg_hba.conf"
fi

# Arranque provisional solo por socket para fijar contraseña y base en cada boot
# (la contraseña la elige personal-nutrition en cada arranque; los datos pueden ser anteriores).
$RUN "$PG_BIN/pg_ctl" -D "$PGDATA" -w -t 120 \
    -o "-c listen_addresses='' -c unix_socket_directories=/tmp" start

$RUN "$PG_BIN/psql" -h /tmp -U "$DB_USERNAME" -d postgres -v ON_ERROR_STOP=1 \
    -v usr="$DB_USERNAME" -v pw="$DB_PASSWORD" -v db="$DB_DATABASE" <<'SQL'
ALTER ROLE :"usr" PASSWORD :'pw';
SELECT format('CREATE DATABASE %I OWNER %I', :'db', :'usr')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db') \gexec
SQL

$RUN "$PG_BIN/pg_ctl" -D "$PGDATA" -m fast -w stop

exec $RUN "$PG_BIN/postgres" -D "$PGDATA" \
    -c listen_addresses='*' -c port=5432 -c unix_socket_directories=/tmp
