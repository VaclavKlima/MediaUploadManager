# syntax=docker/dockerfile:1.7

ARG APP_RELEASE=development

FROM php:8.5-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        ffmpeg \
        libicu-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" intl pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:24-bookworm-slim /usr/local/ /usr/local/

WORKDIR /var/www/html
COPY . .

RUN export APP_ENV=local \
    && composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --prefer-dist \
    && npm ci \
    && npm run build \
    && rm -rf node_modules

FROM php-base AS app

ARG APP_RELEASE
ARG APP_UID=1000
ARG APP_GID=1000

ENV APP_RELEASE=${APP_RELEASE}

WORKDIR /var/www/html
COPY --from=build /var/www/html /var/www/html

RUN groupadd --gid "${APP_GID}" mum \
    && useradd --uid "${APP_UID}" --gid "${APP_GID}" --create-home --shell /usr/sbin/nologin mum \
    && mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        /var/lib/tusd \
    && chown -R "${APP_UID}:${APP_GID}" storage bootstrap/cache /var/lib/tusd \
    && chmod -R ugo+rwX storage bootstrap/cache

USER mum
CMD ["php-fpm", "-F"]

FROM nginx:1.28.0-bookworm AS nginx

RUN apt-get update \
    && apt-get install --yes --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && rm /etc/nginx/conf.d/default.conf

COPY --from=build /var/www/html/public/build /var/www/html/public/build
COPY public/favicon.ico public/favicon.svg public/apple-touch-icon.png public/robots.txt /var/www/html/public/
COPY public/images /var/www/html/public/images
COPY deploy/production/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY deploy/production/nginx/hooks.conf.template /etc/nginx/templates/hooks.conf.template
COPY deploy/nginx/tus-public.location.conf /etc/nginx/snippets/tus-public.location.conf

EXPOSE 8080
