FROM php:8.2-apache

# Cài extension MySQL (PDO) cho PHP
RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client libssl-dev unzip git \
    && docker-php-ext-install pdo pdo_mysql mysqli \
    && pecl install mongodb-2.5.0 \
    && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/*

# Bật mod_rewrite cho .htaccess (route về front controller)
RUN a2enmod rewrite

# Đặt web root vào thư mục /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Cho phép .htaccess override
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/zz-override.conf \
    && a2enconf zz-override

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
