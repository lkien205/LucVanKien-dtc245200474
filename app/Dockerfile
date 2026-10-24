FROM php:8.3-apache-bookworm

RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers \
    && mkdir -p /var/log/app \
    && chown www-data:www-data /var/log/app

COPY entrypoint.sh /usr/local/bin/attendance-entrypoint
COPY apache/ports.conf /etc/apache2/ports.conf
COPY apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY php/attendance.ini /usr/local/etc/php/conf.d/attendance.ini
COPY migrations/ /var/www/migrations/
COPY public/ /var/www/html/

RUN sed -i 's/\r$//' /usr/local/bin/attendance-entrypoint \
    && chmod 0755 /usr/local/bin/attendance-entrypoint

ENTRYPOINT ["/usr/local/bin/attendance-entrypoint"]
EXPOSE 8080
