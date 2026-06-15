FROM php:8.1-apache

# Системные зависимости для расширений (sqlite, curl, intl для idn_to_ascii)
# Плюс curl CLI — для фонового обработчика очереди
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        libicu-dev \
        curl \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite curl mbstring intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Включаем mod_rewrite и mod_headers (нужны для .htaccess)
RUN a2enmod rewrite headers

# Разрешаем .htaccess (AllowOverride All) в корне документов
RUN printf '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/app-override.conf \
    && a2enconf app-override

# Папка приложения
WORKDIR /var/www/html

# Код кладём в образ (на проде); при локальной разработке его перекроет volume из compose
COPY . /var/www/html

# Точка входа: фоновый обработчик очереди + Apache
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Apache работает под www-data — даём ему права на запись (создание SQLite-БД, credentials.txt)
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
