# Use multi-stage build for smaller final image
FROM alpine:3.18 as builder

# Install necessary build dependencies
RUN apk add --no-cache \
    php81-dev \
    gcc \
    g++ \
    make \
    autoconf

# Final stage
FROM alpine:3.18

# Install required packages
RUN apk add --no-cache \
    nginx \
    php81 \
    php81-fpm \
    php81-gd \
    php81-gettext \
    php81-mbstring \
    php81-pdo \
    php81-pdo_mysql \
    php81-mysqli \
    php81-session \
    php81-json \
    mariadb \
    mariadb-client \
    supervisor \
    phpmyadmin \
    php81-curl \
    php81-zip \
    php81-xml

# Configure nginx
COPY nginx.conf /etc/nginx/http.d/default.conf

# Create required directories
RUN mkdir -p /run/nginx /run/php /run/mysqld

# Set proper permissions for nginx user
RUN adduser -D -g 'www' www && \
    mkdir -p /var/www/html && \
    chown -R www:www /var/www/html && \
    chown -R www:www /run/nginx

# Setup MariaDB
RUN mkdir -p /var/lib/mysql /var/run/mysqld && \
    chown -R mysql:mysql /var/lib/mysql /var/run/mysqld

# Setup phpMyAdmin
RUN ln -s /usr/share/webapps/phpmyadmin /var/www/html/phpmyadmin && \
    chown -R www:www /var/www/html

# Configure PHP-FPM
RUN sed -i 's/nobody/www/g' /etc/php81/php-fpm.d/www.conf && \
    sed -i 's/user = nobody/user = www/g' /etc/php81/php-fpm.d/www.conf && \
    sed -i 's/group = nobody/group = www/g' /etc/php81/php-fpm.d/www.conf

# Configure supervisord
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy MySQL initialization script
COPY init.sql /docker-entrypoint-initdb.d/

# Create volume mount points
VOLUME ["/var/lib/mysql", "/var/www/html"]

# Expose ports
EXPOSE 80

# Copy and run the startup script
COPY start.sh /start.sh
RUN chmod +x /start.sh
CMD ["/start.sh"]
