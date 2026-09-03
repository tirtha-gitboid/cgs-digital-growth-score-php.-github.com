FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
        libpq-dev \
        libsqlite3-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        libxml2-dev \
        pkg-config \
        unzip \
    && docker-php-ext-install pdo_pgsql pdo_sqlite curl mbstring dom \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

RUN mkdir -p /app/data && chmod -R 777 /app/data

EXPOSE 10000
CMD php -S 0.0.0.0:${PORT:-10000} router.php
