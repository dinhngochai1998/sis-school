FROM php:8.1-cli
LABEL maintainer="yaangvu@gmail.com"

ENV APP_ROOT /var/www/html
ENV APP_TIMEZONE UTC

WORKDIR ${APP_ROOT}

#Set TimeZone
ENV TZ=${APP_TIMEZONE}
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN sed -i "s|max_execution_time = 30|max_execution_time = 300|g" "$PHP_INI_DIR/php.ini"
RUN sed -i "s|memory_limit = 128M|memory_limit = 2G|g" "$PHP_INI_DIR/php.ini"
RUN echo "opcache.optimization_level=0" >> "$PHP_INI_DIR/php.ini"

# Add Production Dependencies
RUN apt-get update -y
RUN apt-get install -y \
    bash \
    libpq-dev \
    zlib1g-dev \
    libpng-dev \
    libxml2-dev \
    libpng-dev \
    libzip-dev \
    libbz2-dev \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev

# Configure & Install Extension
RUN docker-php-ext-configure \
    opcache --enable-opcache

RUN docker-php-ext-install \
    opcache \
    pdo_pgsql \
    pgsql \
    pdo \
    gd \
    xml \
    intl \
    sockets \
    bz2 \
    pcntl \
    bcmath \
    exif \
    zip

# Install Mongodb
RUN pecl install mongodb redis \
    && docker-php-ext-enable mongodb redis.so

COPY dockerize/start.sh /usr/local/bin/start

#Run the command on container startup
RUN chmod u+x /usr/local/bin/start

COPY record.sh /usr/local/bin/record
RUN chmod u+x /usr/local/bin/record

# Install Composer.
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && ln -s $(composer config --global home) /root/composer
ENV PATH=$PATH:/root/composer/vendor/bin COMPOSER_ALLOW_SUPERUSER=1

# Install PHP DI
COPY . .
RUN cp .env.example .env
RUN composer install --no-dev

EXPOSE 8000
CMD ["/usr/local/bin/start"]