# The development image: PHP's command line with the extensions YorvoID and its tools need.
# YorvoID is a library, so there is no web server; tests and tools run in this container.
FROM php:8.5-cli

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

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
