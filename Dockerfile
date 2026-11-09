FROM php:8.3-cli-alpine

RUN apk add --no-cache bash git icu-dev libzip-dev curl \
    && docker-php-ext-install pdo pdo_mysql bcmath intl zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY . .

RUN composer dump-autoload --optimize

EXPOSE 8001

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8001"]
