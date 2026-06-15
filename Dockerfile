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

RUN composer install \
    --no-dev \
    --no-scripts \
    --optimize-autoloader \
    --no-interaction

RUN mkdir -p var/cache var/log

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "public"]
