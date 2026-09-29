FROM php:8.4-fpm-alpine AS runtime
RUN apk add --no-cache libpq oniguruma libzip icu-libs libxml2 curl \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev oniguruma-dev libzip-dev icu-dev libxml2-dev \
    && docker-php-ext-install pdo_pgsql mbstring zip intl pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
FROM runtime AS application
COPY backend/ ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY infra/php.ini /usr/local/etc/php/conf.d/3r.ini
COPY infra/fpm.conf /usr/local/etc/php-fpm.d/zz-3r.conf
COPY infra/backend-entrypoint.sh /usr/local/bin/3r-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/3r-entrypoint && chmod +x /usr/local/bin/3r-entrypoint
ENTRYPOINT ["3r-entrypoint"]
CMD ["php-fpm"]
