# main image
FROM php:8.3-fpm as base

# installing main dependencies
RUN apt-get update && apt-get install -y \
    git \
    ffmpeg \
    procps

# installing unzip dependencies
RUN apt-get install -y \
    libzip-dev \
    zlib1g-dev \
    unzip

# gd extension configure and install
RUN apt-get install -y \
    libfreetype6-dev \
    libicu-dev \
    libgmp-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libxpm-dev
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && docker-php-ext-install gd

# imagick extension configure and install
RUN apt-get install -y libmagickwand-dev \
    && pecl install imagick \
    && docker-php-ext-enable imagick

# intl extension configure and install
RUN docker-php-ext-configure intl && docker-php-ext-install intl

# other extensions install
RUN docker-php-ext-install bcmath calendar exif gmp mysqli pdo pdo_mysql zip

# Redis extension install
RUN pecl install redis && docker-php-ext-enable redis

# installing composer
COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer

# Build arguments for versioning
ARG BAGISTO_VERSION="v2.3.6"
ARG STRIPE_GATEWAY_VERSION="*"
ARG INSTALL_STRIPE_GATEWAY="true"

# Download Bagisto from GitHub to temporary location
RUN git clone --depth 1 --branch ${BAGISTO_VERSION} https://github.com/bagisto/bagisto.git /tmp/bagisto

# Set working directory and move Bagisto files
WORKDIR /var/www/html
RUN mv /tmp/bagisto/* /tmp/bagisto/.* /var/www/html/ 2>/dev/null || true && \
    rm -rf /tmp/bagisto

# Remove .env file - we'll use environment variables from Kubernetes
RUN rm -f /var/www/html/.env

# Install Bagisto dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Install Stripe Payment Gateway (version controlled)
RUN if [ "$INSTALL_STRIPE_GATEWAY" = "true" ]; then \
        echo "Installing Stripe Payment Gateway version: $STRIPE_GATEWAY_VERSION"; \
        composer require codenteq/stripe-payment-gateway:"${STRIPE_GATEWAY_VERSION}" --no-interaction; \
    fi

# Run optimization commands
# NOTE: Do NOT run config:cache or route:cache here - they bake in build-time
# environment vars. These should be set at container startup if needed.
RUN php artisan optimize:clear || true

# ============================================
# Frontend build stage with Node 20 (better Vite compatibility)
# ============================================
FROM node:20-slim as frontend-builder

WORKDIR /app

# Copy package files and source
COPY --from=base /var/www/html/package*.json ./
COPY --from=base /var/www/html /app

# Ensure package.json is configured for ESM
RUN node -e "const pkg = require('./package.json'); pkg.type = 'module'; require('fs').writeFileSync('./package.json', JSON.stringify(pkg, null, 2));"

# Install and build with ESM support
RUN npm install --no-audit --no-fund
RUN npm run build

# ============================================
# Final stage
# ============================================
FROM base as final

# Copy built assets from frontend builder
COPY --from=frontend-builder /app/public /var/www/html/public

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Run container as www-data user
USER www-data

# Expose port 9000 for php-fpm
EXPOSE 9000

CMD ["php-fpm"]
