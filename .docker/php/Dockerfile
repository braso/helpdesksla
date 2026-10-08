FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        curl \
        imap-dev \
        krb5-dev \
        openssl-dev \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        zip \
        intl \
        pcntl \
        imap

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --no-interaction --no-progress --optimize-autoloader

RUN chown -R www-data:www-data /var/www/storage \
    && chmod -R 775 /var/www/storage
