FROM php:8.4-fpm-alpine

# Build variables for extensions
ARG APP_ENV=production

# Build and runtime packages for extensions
RUN set -eux; \
  apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev linux-headers libxml2-dev \
  && apk add --no-cache icu-libs libxml2 curl ca-certificates bash tzdata su-exec shadow \
  # native extensions
  && docker-php-ext-configure intl \
  && docker-php-ext-install -j$(nproc) intl pcntl pdo_mysql sysvsem sysvshm xml \
  # optional extensions only in development
  && if [ "$APP_ENV" = "development" ]; then \
    pecl install xdebug pcov; \
    docker-php-ext-enable xdebug pcov; \
  fi \
  && docker-php-ext-enable opcache \
  # cleanup
  && apk del .build-deps

# Official Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App
WORKDIR /var/www
COPY . /var/www

# Entry point that aligns the user inside the container with the host
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Extra PHP config (only applies in development)
COPY docker/php/conf.d/ /usr/local/etc/php/conf.d/
RUN if [ "$APP_ENV" = "development" ]; then \
    mv /usr/local/etc/php/conf.d/development.ini /usr/local/etc/php/conf.d/99-development.ini; \
  else \
    rm -f /usr/local/etc/php/conf.d/development.ini; \
  fi

# www-data ownership (nginx will share volume)
RUN chown -R www-data:www-data /var/www

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm", "-F"]
