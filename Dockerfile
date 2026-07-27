# =============================================================================
# InternTrack API — container image for a Docker-based host (built for Render's
# free web service tier, but nothing here is Render-specific).
#
# php:8.3-apache is deliberate:
#   - 8.3 matches composer.json's ^8.3 requirement.
#   - Apache + mod_php is ONE process, so there is no nginx/php-fpm supervisor to
#     babysit — the right trade for a free single-instance tier.
#   - It is Debian-based, so ca-certificates is present and configured. That
#     alone retires the CA-bundle gotcha documented in CLAUDE.md: on Windows,
#     PHP shipped with no CA bundle and EVERY outbound HTTPS call failed
#     (cURL error 60) — Google's token exchange and SMTP over TLS included.
# =============================================================================
FROM php:8.3-apache

# ---------------------------------------------------------------------------
# System libraries and PHP extensions
#
# gd is configured with jpeg + webp + freetype ON PURPOSE. AvatarProcessingService
# decodes uploads and re-encodes them to WebP; a GD built without those codecs
# cannot decode a .jpg at all and silently degrades avatars to PNG (see the
# AvatarProcessingService notes in CLAUDE.md — that is exactly the "lite" PHP
# build problem, and this is where it gets prevented for the deployed copy).
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
        libzip-dev \
        unzip \
        git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        pdo_mysql \
        zip \
        bcmath \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Laravel serves from public/, not the project root. Without this, the whole
# source tree — .env included — would be web-reachable.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers

# Production opcache settings. This matters more than usual here: a free-tier
# service spins down when idle, so the first request after a sleep pays the
# entire cold start.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
        echo 'upload_max_filesize=4M'; \
        echo 'post_max_size=8M'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, in their own layer, so application edits do not force a
# full re-install on every build.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

# --no-dev omits Faker (a require-dev package), which makes database/factories
# unusable in this image. The SEEDERS are unaffected and still work here: every
# one of them hand-writes its rows and none call ->factory() or Faker, so
# SEED_ON_BOOT=demo is viable. Only the factories (used by tests) are lost, and
# tests are excluded from the image anyway via .dockerignore.
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Render (and most container hosts) inject the port to listen on. 10000 is
# Render's default; the entrypoint rewrites Apache's config to match whatever
# arrives, so this works unchanged on a host that picks a different port.
ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
