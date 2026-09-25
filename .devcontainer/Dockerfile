# EGradePro in GitHub Codespaces: PHP 8.2 + Apache + MariaDB (Debian bookworm; the PHP 8.0 image is no longer updated)
FROM php:8.2-apache-bookworm

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      mariadb-server mariadb-client git unzip curl ca-certificates \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libonig-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip bcmath exif \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# Composer (vendor/ is not in the repository; it is installed on first start)
RUN curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# .htaccess files of the app must work (FilesMatch / RedirectMatch rules)
RUN printf '<Directory /var/www/html/>\n    Options FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/egradepro.conf \
 && a2enconf egradepro \
 && printf 'upload_max_filesize=25M\npost_max_size=30M\nmemory_limit=512M\nmax_execution_time=300\ndate.timezone=Asia/Manila\ndisplay_errors=Off\nlog_errors=On\npdo_mysql.default_socket=/run/mysqld/mysqld.sock\nmysqli.default_socket=/run/mysqld/mysqld.sock\n' > /usr/local/etc/php/conf.d/egradepro.ini
