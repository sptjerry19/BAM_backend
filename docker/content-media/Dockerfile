# Content Studio media worker: the normal backend image + Python + Playwright/Chromium.
#
# The first layers are IDENTICAL to docker/Dockerfile on purpose, so Docker reuses
# their cache. Chromium comes before `COPY . .`, so editing PHP code never re-downloads it.
FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl procps ffmpeg libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql bcmath gd zip intl opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/social.ini /usr/local/etc/php/conf.d/social.ini

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# --- media tooling (not in the normal image) -------------------------------------
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
COPY content-media/requirements.txt /tmp/content-media-requirements.txt
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-venv \
    && python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir -r /tmp/content-media-requirements.txt \
    && /opt/venv/bin/playwright install --with-deps chromium \
    && rm -rf /var/lib/apt/lists/*
ENV CONTENT_MEDIA_PYTHON=/opt/venv/bin/python
# ---------------------------------------------------------------------------------

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm", "-F"]
