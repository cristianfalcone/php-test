FROM php:8.2-fpm-alpine

# Variables de build para extensiones
ARG APP_ENV=production

# Paquetes de compilación y runtime para extensiones
RUN set -eux; \
  apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev linux-headers \
  && apk add --no-cache icu-libs curl ca-certificates bash tzdata su-exec shadow \
  # extensiones nativas
  && docker-php-ext-configure intl \
  && docker-php-ext-install -j$(nproc) intl pcntl pdo_mysql \
  # extensiones opcionales solo en desarrollo
  && if [ "$APP_ENV" = "development" ]; then \
    pecl install xdebug pcov; \
    docker-php-ext-enable xdebug pcov; \
  fi \
  && docker-php-ext-enable opcache \
  # limpiar
  && apk del .build-deps

# Composer oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App
WORKDIR /var/www
COPY . /var/www

# Entry point que alinea el usuario dentro del contenedor con el del host
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Config extra de PHP (solo aplica en desarrollo)
COPY docker/php/conf.d/ /usr/local/etc/php/conf.d/
RUN if [ "$APP_ENV" = "development" ]; then \
    mv /usr/local/etc/php/conf.d/development.ini /usr/local/etc/php/conf.d/99-development.ini; \
  else \
    rm -f /usr/local/etc/php/conf.d/development.ini; \
  fi

# www-data ownership (nginx compartirá volumen)
RUN chown -R www-data:www-data /var/www

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm", "-F"]
