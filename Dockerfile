FROM dunglas/frankenphp:1.4-php8.4

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/* \
    && git config --system http.version HTTP/1.1

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    opcache \
    redis \
    bcmath \
    sockets

COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

COPY Caddyfile /etc/caddy/Caddyfile

RUN set -eu; \
    composer config --global source-fallback true; \
    attempt=1; \
    until COMPOSER_MAX_PARALLEL_HTTP=1 composer install \
        --no-dev \
        --no-scripts \
        --optimize-autoloader \
        --no-interaction; \
    do \
        if [ "$attempt" -ge 3 ]; then \
            exit 1; \
        fi; \
        attempt=$((attempt + 1)); \
        echo "Composer download failed, retrying ($attempt/3)..."; \
        sleep 2; \
    done

RUN mkdir -p var/cache var/log

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
