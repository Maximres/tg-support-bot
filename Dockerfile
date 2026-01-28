FROM php:8.3-fpm

# Используем bash с pipefail для всех RUN
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Установка системных пакетов и Node.js
RUN apt-get update && \
    apt-get install -y --no-install-recommends git curl zip unzip libpq-dev shellcheck && \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y --no-install-recommends nodejs && \
    docker-php-ext-install pdo pdo_pgsql pgsql && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Настройки PHP
COPY ./docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# WORKDIR до COPY проекта
WORKDIR /var/www

# Отключаем получение git commit info для Laravel/npm
ENV LARAVEL_GIT_COMMIT=false

# Копируем файлы зависимостей отдельно для лучшего кеширования
COPY composer.json composer.lock* ./
COPY package.json package-lock.json* ./

# Установка PHP зависимостей (кешируется, если composer.json не изменился)
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Установка Node.js зависимостей (кешируется, если package.json не изменился)
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

# Копируем остальные файлы проекта
COPY . .

# Права доступа на storage и bootstrap/cache
RUN mkdir -p storage/logs \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    find storage bootstrap/cache -type d -exec chmod 775 {} + && \
    find storage bootstrap/cache -type f -exec chmod 664 {} +

# Запускаем post-install скрипты Composer и сборку фронтенда
RUN composer dump-autoload --optimize && \
    npm run build

# Меняем пользователя на www-data
USER www-data

EXPOSE 9000
CMD ["php-fpm"]
