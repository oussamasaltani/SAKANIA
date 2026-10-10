FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libfcgi-bin \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Performance tuning (opcache sizing, JIT, realpath cache). Lives outside
# /var/www so it survives the ./backend:/var/www bind mount at runtime.
COPY docker/php-performance.ini /usr/local/etc/php/conf.d/zz-performance.ini

COPY . .

RUN mkdir -p /var/www/storage /var/www/bootstrap/cache \
    && composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage /var/www/bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/sakanai-entrypoint
RUN chmod +x /usr/local/bin/sakanai-entrypoint

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/sakanai-entrypoint"]
CMD ["php-fpm", "-F"]
