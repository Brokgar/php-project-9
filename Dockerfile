FROM php:8.4-cli

# Установка системных зависимостей (включая make, git и unzip для работы Composer и Makefile)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpq-dev \
    make \
    git \
    unzip \
    && docker-php-ext-install zip pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Копирование бинарного файла Composer из официального образа
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Оптимизация кэширования Docker-слоёв для Composer
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress

# Копирование исходного кода проекта
COPY . .

EXPOSE 8000

CMD ["make", "start"]