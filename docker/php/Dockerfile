FROM php:8.3-fpm-alpine@sha256:2127b967ef53d95fd84b3ccb15361d638c3c6dc72079123cce5467c642020a81

# Build-time deps (compiler, make, re2c, etc.) — удаляются после компиляции
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        postgresql-dev \
        rabbitmq-c-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        gd \
        intl \
        mbstring \
        zip \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apk del .build-deps \
    && apk add --no-cache \
        bash \
        curl \
        ca-certificates \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        libpq \
        rabbitmq-c \
        supervisor

RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php

# Враппер для busybox crond: перенаправляет вывод cron-задач в stdout supervisord (PID 1).
# Без него busybox ash выдаёт "redir error" при >> /dev/stdout, т.к. это именованный pipe.
RUN printf '#!/bin/sh\nexec "$@" >/proc/1/fd/1 2>/proc/1/fd/2\n' \
    > /usr/local/bin/cron-exec && chmod +x /usr/local/bin/cron-exec

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
