# Servicio Celaut de Personal Nutrition: UN solo microVM con todo lo que docker-compose.yml reparte en contenedores
# (web + cola + agente). Aqui el Dockerfile solo construye el sistema de ficheros: CMD/ENTRYPOINT/EXPOSE se
# ignoran; el arranque es service.json -> init.entry_path (/opt/nutrition/start.sh) y el puerto, service.json -> api.
# Los COPY de origen del proyecto llevan «./»: el empaquetador los reescribe a service/...

# --- Dependencias de PHP (sin dev: ni boost ni pail ni phpunit) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY ./admin /app
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader --ignore-platform-reqs

# --- Binarios que se copian tal cual ---
FROM node:22-bookworm-slim AS node

# --- Imagen final ---
# FrankenPHP = Caddy con PHP (ZTS) embebido: un solo proceso web, sin php-fpm.
FROM dunglas/frankenphp:1-php8.4-bookworm

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions intl zip bcmath gd pcntl opcache pdo_pgsql pgsql

RUN apt-get update && apt-get install -y --no-install-recommends \
        bash ca-certificates curl sqlite3 python3 python-is-python3 util-linux \
    && rm -rf /var/lib/apt/lists/*

# Node solo para Claude Code (npm install -g @anthropic-ai/claude-code, como en docker/Dockerfile.agente).
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && npm install -g @anthropic-ai/claude-code \
    && npm cache clean --force


# `celaut`: la service-library de Celaut (celaut-project/service-library) como binario estatico. Laravel habla con el nodo con el
# (App\Services\Celaut\Nodo): lee las redes resueltas, lanza nutrition-postgres si no hay base y lo para al terminar.
ARG CELAUT_VERSION=v0.2.0
ARG CELAUT_SHA256=3ca8cd490d19126b7e2447347673b8348ccc5a1bb540f288993aeed7cdcba462
ADD https://github.com/celaut-project/service-library/releases/download/${CELAUT_VERSION}/celaut-linux-arm64 /usr/local/bin/celaut
RUN echo "${CELAUT_SHA256}  /usr/local/bin/celaut" | sha256sum -c - && chmod 755 /usr/local/bin/celaut

# Dependencia Celaut empaquetada por el packer (nutrition-postgres) y su hash (.dependencies).
COPY ./__services__ /opt/nutrition/celaut/__services__
COPY ./__metadata__ /opt/nutrition/celaut/__metadata__
COPY ./__block__ /opt/nutrition/celaut/__block__
COPY ./.dependencies /opt/nutrition/celaut/.dependencies

# Un usuario por pieza: lo que el agente (que ejecuta Bash) puede leer no incluye ni la base ni APP_KEY.
RUN groupadd -g 1000 app     && useradd -m -u 1000 -g 1000 -s /bin/bash app \
    && groupadd -g 1001 agente && useradd -m -u 1001 -g 1001 -s /bin/bash agente

# Administracion (Laravel + Filament): codigo de solo lectura, todo lo que escribe va a /var/lib/nutrition/admin.
COPY ./admin /opt/nutrition/admin
COPY --from=vendor /app/vendor /opt/nutrition/admin/vendor
RUN cd /opt/nutrition/admin \
    && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
       LARAVEL_STORAGE_PATH=/tmp/storage-build \
       sh -c 'mkdir -p /tmp/storage-build/framework/views /tmp/storage-build/framework/cache /tmp/storage-build/logs \
              && php artisan package:discover --ansi && php artisan filament:assets --ansi' \
    && rm -rf /tmp/storage-build /opt/nutrition/admin/bootstrap/cache/*.php.tmp

# Subidas de la copia de seguridad (por defecto PHP admite 2 MB; Livewire limita a 12 MB).
RUN printf 'upload_max_filesize=16M\npost_max_size=20M\nmemory_limit=512M\nopcache.enable=1\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/zz-nutrition.ini

# Kit del agente + runner: las mismas rutas que en docker/Dockerfile.agente, porque se reutiliza su entrypoint.
COPY ./agente /opt/agente-kit
RUN mkdir -p /opt/agente-kit/.claude \
    && echo '{"fecha": "imagen-celaut", "sistema": "Linux (microVM Celaut)", "python": "python", "persona": "Personal Nutrition"}' \
        > /opt/agente-kit/.claude/setup-completado.json
COPY ./docker/agente/runner.py /opt/runner/runner.py
COPY ./docker/agente/entrypoint.sh /opt/runner/entrypoint.sh
COPY ./docker/servicio/start.sh /opt/nutrition/start.sh
COPY ./docker/servicio/Caddyfile /opt/nutrition/Caddyfile
RUN sed -i 's/\r$//' /opt/runner/entrypoint.sh /opt/runner/runner.py /opt/nutrition/start.sh \
    && chmod 755 /opt/runner/entrypoint.sh /opt/nutrition/start.sh \
    && mkdir -p /var/lib/nutrition

# Los datos locales (sesion de Claude, estado del runner y del pool de celaut) viven en /var/lib/nutrition, creado y repartido por start.sh.
# Todo lo demas (datos, cache, sesiones, cola y archivos del agente) vive en la PostgreSQL compartida.
