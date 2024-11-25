FROM php:8.1-fpm-alpine

# Instal dependensi PHP
RUN apk add --no-cache \
    libpng-dev libjpeg-turbo-dev freetype-dev gettext-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd gettext mbstring pdo pdo_mysql

# Direktori kerja di container
WORKDIR /var/www/html

# Salin semua file (karena root project sama)
COPY . /var/www/html

# Berikan hak akses pada folder
RUN chown -R www-data:www-data /var/www/html

# Expose port PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
