FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

FROM php:8.4-apache AS app
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev libicu-dev libonig-dev libxml2-dev ghostscript imagemagick libmagickwand-dev unzip \
    && docker-php-ext-install zip pdo pdo_mysql opcache \
    && pecl install imagick && docker-php-ext-enable imagick \
    && a2enmod rewrite headers ssl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY . /var/www/html
COPY docker/php/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN mkdir -p /var/www/html/storage/tmp /var/www/html/storage/uploads /var/www/html/storage/imports \
    /var/www/html/storage/processed /var/www/html/storage/signatures /var/www/html/storage/network-share \
    /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/logs
EXPOSE 80
CMD ["apache2-foreground"]
