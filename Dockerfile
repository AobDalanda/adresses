FROM dunglas/frankenphp:1.4-php8.4

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    opcache \
    redis \
    bcmath \
    sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

COPY Caddyfile /etc/caddy/Caddyfile

RUN set -eu; \
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
