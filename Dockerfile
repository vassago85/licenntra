FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql pcntl zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

COPY package.json ./
RUN apt-get update \
    && apt-get install -y --no-install-recommends nodejs npm \
    && npm install \
    && rm -rf /var/lib/apt/lists/*

COPY . .
RUN composer dump-autoload --optimize \
    && npm run build \
    && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
