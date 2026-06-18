# === STAGE 1: Build Frontend Assets ===
FROM node:20-alpine AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# === STAGE 2: Production PHP/Apache Environment ===
FROM php:8.4-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    zip \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    pdo_pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip

# Configure Apache DocumentRoot
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf
RUN sed -ri -e "s!/var/www/!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite

# Allow .htaccess overrides & Suppress ServerName warning
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Global Composer configurations to prevent memory/timeout crashes
ENV COMPOSER_MEMORY_LIMIT=-1
ENV COMPOSER_PROCESS_TIMEOUT=2000

WORKDIR /var/www/html

# Step 1: Copy ONLY dependency definitions (Leverages Docker Layer Caching)
COPY composer.json composer.lock ./

# Step 2: Install packages safely without interaction or autoloader bottlenecks
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# Step 3: Copy the rest of the application
COPY . .

# Step 4: Copy compiled Vite assets from Stage 1
COPY --from=frontend-builder /app/public/build ./public/build

# Step 5: Generate optimized production maps and run discoveries
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Create startup script
RUN printf '%s\n' '#!/bin/bash' \
    'if [ -n "$PORT" ]; then' \
    '    sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf' \
    '    sed -i "s/<VirtualHost [^:]*:80>/<VirtualHost *:${PORT}>/g" /etc/apache2/sites-available/*.conf' \
    'fi' \
    'php artisan storage:link --force' \
    'php artisan config:cache' \
    'php artisan route:cache' \
    'php artisan view:cache' \
    'exec apache2-foreground' > /usr/local/bin/start-server && chmod +x /usr/local/bin/start-server

CMD ["/usr/local/bin/start-server"]
