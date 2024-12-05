FROM php:8.3.13-apache

# Install necessary PHP extensions and dependencies
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libgettextpo-dev \
    libonig-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd gettext mbstring zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Copy custom Apache configuration
COPY docker/custom.conf /etc/apache2/sites-available/000-default.conf

# Set the working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Create directories if they don't exist and set permissions
RUN chown -R www-data:www-data \
    /var/www/html/files \
    /var/www/html/images \
    /var/www/html/repository

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
