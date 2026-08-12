FROM php:8.3-cli-alpine

# Install composer from official composer image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install required system dependencies
RUN apk add --no-cache git unzip

WORKDIR /app

# Copy composer files first for caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy remaining project files
COPY . .

ENTRYPOINT [ "php", "./index.php" ]