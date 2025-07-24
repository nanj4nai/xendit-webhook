# Use official PHP-Apache image
FROM php:8.2-apache

# Install mysqli (needed if you ever use MySQL/Supabase with PDO)
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Copy your PHP files to Apache web root
COPY . /var/www/html/
