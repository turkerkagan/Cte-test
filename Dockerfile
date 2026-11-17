FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json ./
RUN npm run build

FROM php:8.4-apache-bookworm
ENV PORT=8080 \
    APACHE_DOCUMENT_ROOT=/var/www/html/public \
    CTE_PYTHON_BINARY=/opt/cte-python/bin/python \
    PYTHONDONTWRITEBYTECODE=1
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       ca-certificates curl unzip default-mysql-client \
       libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
       python3 python3-venv \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring zip bcmath gd opcache \
    && python3 -m venv /opt/cte-python \
    && /opt/cte-python/bin/pip install --no-cache-dir \
       openpyxl==3.1.5 xlrd==2.0.2 et-xmlfile==2.0.0 \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist \
       --no-progress --classmap-authoritative \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
       storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && rm -f bootstrap/cache/*.php \
    && chmod +x deploy/railway/start.sh
COPY --from=frontend /app/public/build ./public/build
COPY deploy/railway/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/railway/ports.conf /etc/apache2/ports.conf
COPY deploy/railway/php.ini /usr/local/etc/php/conf.d/cte-production.ini
RUN sh deploy/railway/prepare-apache.sh
EXPOSE 8080
CMD ["/var/www/html/deploy/railway/start.sh"]
