# syntax=docker/dockerfile:1.7
# ---------- Stage 1: composer dependencies ----------
# Pulled forward so the asset stage can mount vendor/filament/* — the
# Filament v5 theme imports its panels CSS from the vendor directory.
FROM composer:2 AS vendor

WORKDIR /app

# Optional GitHub token. Anonymous codeload.github.com zip downloads get
# rate-limited and intermittently return HTTP 400, which breaks the build.
# Pass --build-arg GITHUB_TOKEN=xxxx to authenticate and avoid that.
ARG GITHUB_TOKEN=""
RUN if [ -n "$GITHUB_TOKEN" ]; then \
        composer config --global github-oauth.github.com "$GITHUB_TOKEN"; \
    fi

COPY composer.json composer.lock ./
# Platform requirements (ext-intl, ext-gd, ext-redis, etc.) are satisfied in the
# final runtime stage, not in this composer image. Ignore them here so we can
# download the locked vendor tree without installing every PHP extension twice.
# Retry a few times: GitHub's dist downloads occasionally fail with a transient
# HTTP 400/5xx, and a single flaky package shouldn't fail the whole deploy.
RUN set -e; n=0; \
    until composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist \
        --ignore-platform-reqs; do \
        n=$((n+1)); \
        if [ "$n" -ge 5 ]; then echo "composer install failed after $n attempts"; exit 1; fi; \
        echo "composer install failed (transient network/GitHub error) — retry $n/5 in 10s..."; \
        sleep 10; \
    done

# ---------- Stage 2: node asset build ----------
# Needs vendor/filament/* (theme.css @import) and app/Filament/* + the
# resources/views/filament tree (Tailwind @source content discovery).
FROM node:22-alpine AS assets

WORKDIR /build

COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY app ./app
COPY --from=vendor /app/vendor ./vendor

RUN npm run build

# ---------- Stage 3: runtime (nginx + php-fpm + supervisor) ----------
FROM php:8.4-fpm-bookworm AS runtime

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libxml2-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        libssl-dev \
        zlib1g-dev \
        unzip \
        git \
        curl \
        ca-certificates \
        tzdata \
        tesseract-ocr \
        tesseract-ocr-eng \
        poppler-utils \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        gd \
        exif \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor

COPY . .

COPY --from=assets /build/public/build ./public/build

RUN composer dump-autoload -o --no-dev \
    && mkdir -p storage/app/private \
                storage/framework/cache \
                storage/framework/sessions \
                storage/framework/views \
                storage/framework/testing \
                storage/logs \
                bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN ln -sf /dev/stdout /var/log/nginx/access.log \
 && ln -sf /dev/stderr /var/log/nginx/error.log

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["app"]
