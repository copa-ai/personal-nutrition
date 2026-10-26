# Imagen unica para la web, la cola y la preparacion: PHP 8.4 (Laravel + Filament). Sin Python: el agente no tiene base
# propia que Laravel deba escribir. Claude Code NO va aqui: corre en el contenedor «agente» (docker/Dockerfile.agente),
# que no ve la base de datos ni las claves de la administracion.
FROM php:8.4-cli-bookworm

ARG UID=1000
ARG GID=1000

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions intl zip bcmath gd pcntl

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates sqlite3 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Usuario normal: los archivos que cree quedan con tu usuario en las carpetas montadas.
RUN groupadd -g ${GID} app && useradd -m -u ${UID} -g ${GID} -s /bin/bash app \
    && mkdir -p /app/admin/vendor && chown -R app:app /app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

USER app
# El repositorio entero (admin/ y agente/ hermanos): la administracion busca ../agente.
WORKDIR /app/admin
ENTRYPOINT ["entrypoint.sh"]
