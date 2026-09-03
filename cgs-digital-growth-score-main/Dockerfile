FROM php:8.2-cli

# Extensions this app needs:
# - pdo_pgsql: persistent leads storage on Postgres (when DATABASE_URL is set)
# - pdo_sqlite: fallback local leads storage (when DATABASE_URL is not set)
# - curl: fetching the audited website, PageSpeed API, OpenAI API
# - dom, mbstring: HTML parsing and text handling in Audit.php
RUN apt-get update && apt-get install -y \
        libpq-dev \
        libsqlite3-dev \
        unzip \
    && docker-php-ext-install pdo_pgsql pdo_sqlite curl mbstring dom \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Make sure the local SQLite fallback directory is writable.
RUN mkdir -p /app/data && chmod -R 777 /app/data

# Render sets $PORT at runtime; PHP's built-in server serves the app
# through router.php, exactly like local development.
EXPOSE 10000
CMD php -S 0.0.0.0:${PORT:-10000} router.php
