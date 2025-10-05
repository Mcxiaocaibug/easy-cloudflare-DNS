FROM php:8.2-apache

# Install system deps and PHP extensions
RUN apt-get update && apt-get install -y \
        libzip-dev libpng-dev libonig-dev libxml2-dev git unzip \
        libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache
RUN a2enmod rewrite && \
    sed -ri -e 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/000-default.conf && \
    printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf && a2enconf servername

WORKDIR /var/www/html

# Copy project
COPY . /var/www/html/

# Ensure runtime dir is writable (for install.lock)
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html

# Environment variables (override at runtime)
ENV MYSQL_DSN="" \
    MYSQL_USER="" \
    MYSQL_PASSWORD="" \
    AUTO_INSTALL="0" \
    ADMIN_USERNAME="admin" \
    ADMIN_PASSWORD="admin123456" \
    ADMIN_EMAIL="admin@example.com" \
    SITE_NAME="六趣DNS域名分发系统" \
    POINTS_PER_RECORD="1" \
    DEFAULT_USER_POINTS="100" \
    ALLOW_REGISTRATION="1"

EXPOSE 80

# Allow PaaS to set listening port via $PORT
ENV PORT=80
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Default is to run Apache in foreground via our entrypoint
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
