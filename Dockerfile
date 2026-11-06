# The development image: PHP's command line with the extensions YorvoID and its tools need.
# YorvoID is a library, so there is no web server; tests and tools run in this container.
# Pinned by digest, so a build is the same until Dependabot proposes the next one (STATIC-08).
# Every supported PHP version is a stage; PHP_VERSION picks one (8.5 for development, 8.4 in CI's job
# for the oldest versions YorvoID supports). Dependabot proposes each stage's next patch and digest.
ARG PHP_VERSION=8.5
FROM php:8.5.11-cli@sha256:8e5d746f87655d89573e76d2b9125ed1b1578e47d3c466a2b2fdfc35593d9b41 AS php-8.4
FROM php:8.5.12-cli@sha256:9236fd373d3bcda0659cf94838a744bd79791419292958963f8c6b34a0071554 AS php-8.5
FROM php-${PHP_VERSION}

COPY --from=ghcr.io/mlocati/php-extension-installer:2@sha256:4fd45396c16685266d32bf147a24ecd5e81029d3820295895bf4a90131190ac9 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
		@composer \
		intl \
		pcov \
		pdo_mysql \
		pdo_pgsql \
		zip \
	&& apt-get update \
	&& apt-get install -y --no-install-recommends git unzip \
	&& rm -rf /var/lib/apt/lists/*

# Files created in the container belong to the developer, who runs it with their own user ID
# (compose.yaml), so Composer and the tools keep their caches in a writable place.
ENV COMPOSER_HOME=/tmp/composer \
	COMPOSER_CACHE_DIR=/tmp/composer/cache

WORKDIR /app
