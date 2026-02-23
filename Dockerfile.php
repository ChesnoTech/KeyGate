FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libgmp-dev \
    zip \
    unzip \
    default-mysql-client \
    cron \
    logrotate \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required for OEM Activation System
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    mbstring \
    xml \
    zip \
    opcache \
    gmp

# Install Redis extension for rate limiting
RUN pecl install redis-6.0.2 && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache modules
RUN a2enmod rewrite ssl headers

# Configure PHP for production
RUN { \
    echo 'memory_limit = 128M'; \
    echo 'upload_max_filesize = 50M'; \
    echo 'post_max_size = 50M'; \
    echo 'max_execution_time = 30'; \
    echo 'max_input_time = 30'; \
    echo 'date.timezone = UTC'; \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /var/www/html/activate/logs/php_errors.log'; \
    echo 'session.cookie_httponly = 1'; \
    echo 'session.cookie_secure = 1'; \
    echo 'session.use_strict_mode = 1'; \
    } > /usr/local/etc/php/conf.d/custom.ini

# Configure OPcache for performance
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=60'; \
    echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Configure log rotation (daily, keep 14 days, compress old logs)
RUN printf '/var/www/html/activate/logs/*.log {\n\
    daily\n\
    rotate 14\n\
    compress\n\
    missingok\n\
    notifempty\n\
    copytruncate\n\
    size 10M\n\
}\n' > /etc/logrotate.d/oem-activation

# Schedule log rotation via cron (runs daily at 2 AM)
RUN echo '0 2 * * * /usr/sbin/logrotate /etc/logrotate.d/oem-activation > /dev/null 2>&1' | crontab -

# Create logs and backups directories
RUN mkdir -p /var/www/html/activate/logs /var/www/html/activate/backups && \
    chown -R www-data:www-data /var/www/html/activate/logs /var/www/html/activate/backups && \
    chmod -R 775 /var/www/html/activate/logs /var/www/html/activate/backups

# Install PHP dependencies via Composer (baked into image)
COPY FINAL_PRODUCTION_SYSTEM/composer.json FINAL_PRODUCTION_SYSTEM/composer.lock /var/www/html/activate/
RUN cd /var/www/html/activate && composer install --no-dev --optimize-autoloader --no-interaction

# Configure Apache DocumentRoot
RUN sed -i 's|/var/www/html|/var/www/html/activate|g' /etc/apache2/sites-available/000-default.conf

# Configure SSL VirtualHost
COPY ssl/apache-ssl.conf /etc/apache2/sites-available/default-ssl.conf
RUN a2ensite default-ssl

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 and 443
EXPOSE 80 443

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s \
  CMD curl -f http://localhost/ || exit 1

# Start cron (for log rotation) then Apache
CMD ["sh", "-c", "cron && apache2-foreground"]
