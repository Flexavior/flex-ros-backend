FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 8000
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
