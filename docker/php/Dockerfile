FROM php:8.3-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip mbstring exif pcntl bcmath

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Create user
RUN groupadd -g 1000 www && useradd -u 1000 -ms /bin/bash -g www www

# Change ownership
RUN chown -R www:www /var/www

# Switch to non-root user
USER www

EXPOSE 9000
CMD ["php-fpm"]
