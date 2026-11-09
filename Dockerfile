FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.4-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libxml2-dev libicu-dev libcurl4-openssl-dev libgmp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring gd zip intl bcmath gmp dom simplexml xml xmlreader xmlwriter curl opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
COPY deploy/php.ini /usr/local/etc/php/conf.d/cte.ini
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=assets /build/public/build ./public/build
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf
EXPOSE 8080
CMD ["sh", "deploy/start.sh"]
