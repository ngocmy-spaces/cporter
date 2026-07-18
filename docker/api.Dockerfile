# cPorter API — PHP 8.3 to mirror the cPanel target host (docs/SPEC.md §2.1).
FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql zip mbstring bcmath \
    && rm -rf /var/lib/apt/lists/*

# PHP limits — mirror the cPanel target (large artifact uploads; docs/SPEC.md §2.1).
RUN { \
        echo "upload_max_filesize=512M"; \
        echo "post_max_size=300M"; \
        echo "memory_limit=512M"; \
        echo "max_execution_time=300"; \
    } > /usr/local/etc/php/conf.d/cporter.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY docker/api-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
