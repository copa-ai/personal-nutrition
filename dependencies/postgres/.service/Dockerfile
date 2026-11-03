# nutrition-postgres: PostgreSQL de personal-nutrition como servicio Celaut independiente.
# Solo se exporta el filesystem; CMD/ENTRYPOINT se ignoran (ver service.json -> init).
FROM postgres:18-trixie

COPY start.sh /usr/local/bin/nutrition-postgres-start

RUN chmod +x /usr/local/bin/nutrition-postgres-start
