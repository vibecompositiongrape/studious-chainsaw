FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libzip-dev unzip ca-certificates poppler-utils     && docker-php-ext-install curl zip     && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite headers
COPY apache/security.conf /etc/apache2/conf-available/security-lsb.conf
RUN a2enconf security-lsb
COPY public_html/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
