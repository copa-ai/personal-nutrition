#!/bin/sh
# Arranca Garage de un solo nodo y, la primera vez, lo deja listo: capacidad, clave de acceso y bucket. Es idempotente:
# en los arranques siguientes (los datos viven en un volumen) no hace nada de eso. Con /tmp/listo el healthcheck sabe que ya se puede usar.
#
# Variables: GARAGE_RPC_SECRET (64 hex), GARAGE_CLAVE (GK + 24 hex), GARAGE_SECRETO (64 hex), GARAGE_BUCKET, GARAGE_REGION.
set -e

cat > /etc/garage.toml <<TOML
metadata_dir = "/var/lib/garage/meta"
data_dir = "/var/lib/garage/data"
db_engine = "sqlite"
replication_factor = 1
rpc_bind_addr = "127.0.0.1:3901"
rpc_public_addr = "127.0.0.1:3901"

[s3_api]
s3_region = "${GARAGE_REGION:-us-east-1}"
api_bind_addr = "0.0.0.0:3900"
TOML

rm -f /tmp/listo
garage -c /etc/garage.toml server &
PID=$!
trap 'kill $PID 2>/dev/null; wait $PID' TERM INT

until garage -c /etc/garage.toml status >/dev/null 2>&1; do sleep 1; done

if garage -c /etc/garage.toml status | grep -q "NO ROLE ASSIGNED"; then
    NODO=$(garage -c /etc/garage.toml status | awk '/^[0-9a-f]+[ \t]/ {print $1; exit}')
    garage -c /etc/garage.toml layout assign -z dev -c 1G "$NODO"
    garage -c /etc/garage.toml layout apply --version 1
    sleep 2
fi
garage -c /etc/garage.toml key info "$GARAGE_CLAVE" >/dev/null 2>&1 \
    || garage -c /etc/garage.toml key import --yes -n agente "$GARAGE_CLAVE" "$GARAGE_SECRETO"
garage -c /etc/garage.toml bucket info "$GARAGE_BUCKET" >/dev/null 2>&1 \
    || garage -c /etc/garage.toml bucket create "$GARAGE_BUCKET"
garage -c /etc/garage.toml bucket allow --read --write --owner "$GARAGE_BUCKET" --key "$GARAGE_CLAVE"

touch /tmp/listo
wait $PID
