# Stage 1: Get Composer and install dependencies
FROM composer:2 AS composer_stage

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction

# Stage 2: Final PHP Apache Image
FROM php:8.2-apache

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install zip pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY . /var/www/html/

# Copy installed vendor folder from composer_stage
COPY --from=composer_stage /app/vendor /var/www/html/vendor

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 80
