FROM php:8.2-apache
RUN docker-php-ext-install mysqli pdo_mysql \
 && a2enmod headers expires rewrite \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-rural-banking.ini
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
