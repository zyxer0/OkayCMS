FROM php:7.4-fpm

RUN docker-php-ext-install pdo_mysql

RUN apt-get upgrade && apt-get update && ACCEPT_EULA=Y && apt-get install -y \
    git\
    zip\
    unzip

RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype-dev

# Install GD
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install gd