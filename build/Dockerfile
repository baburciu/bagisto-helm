# main image — FrankenPHP (Caddy + embedded PHP, no separate web server needed)
FROM dunglas/frankenphp:1.11.2-php8.3 AS base

# FrankenPHP uses /app by default; override to match Laravel conventions
WORKDIR /var/www/html

# Install all system dependencies and PHP extension build deps in one layer
RUN apt-get update && apt-get install -y \
    git \
    ffmpeg \
    procps \
    libzip-dev \
    zlib1g-dev \
    unzip \
    libfreetype6-dev \
    libicu-dev \
    libgmp-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libxpm-dev \
    libmagickwand-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-configure intl \
    && docker-php-ext-install gd intl bcmath calendar exif gmp mysqli pdo pdo_mysql zip

# Install PECL extensions (version-pinned for reproducibility)
RUN pecl install imagick-3.8.1 redis-6.3.0 \
    && docker-php-ext-enable imagick redis

# Install Composer (version-pinned)
COPY --from=composer:2.7.9 /usr/bin/composer /usr/local/bin/composer

# Download Bagisto source — ARG declared here so all layers above (system deps,
# PHP extensions, Composer) are cached regardless of version changes
ARG BAGISTO_VERSION="v2.3.6"
RUN git clone --depth 1 --branch ${BAGISTO_VERSION} https://github.com/baburciu/bagisto.git /tmp/bagisto

# Move Bagisto files into place
RUN mv /tmp/bagisto/* /tmp/bagisto/.* /var/www/html/ 2>/dev/null || true \
    && rm -rf /tmp/bagisto

# Remove .env file - we'll use environment variables from Kubernetes
RUN rm -f /var/www/html/.env

# Install Bagisto dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Install Stripe Payment Gateway — ARGs declared here so changing the Stripe
# version does NOT invalidate the git clone + composer install layers above
ARG STRIPE_GATEWAY_VERSION="*"
ARG INSTALL_STRIPE_GATEWAY="true"
RUN if [ "$INSTALL_STRIPE_GATEWAY" = "true" ]; then \
        echo "Installing Stripe Payment Gateway version: $STRIPE_GATEWAY_VERSION"; \
        composer require codenteq/stripe-payment-gateway:"${STRIPE_GATEWAY_VERSION}" --no-interaction; \
    fi

# Run optimization commands
# NOTE: Do NOT run config:cache or route:cache here - they bake in build-time
# environment vars. These should be set at container startup if needed.
RUN php artisan optimize:clear || true

# ============================================
# Frontend build stage (version-pinned Node)
# ============================================
FROM node:20.20.0-slim AS frontend-builder

WORKDIR /app

# Copy package files first for better layer caching —
# npm install layer is reused when only application source code changes
COPY --from=base /var/www/html/package*.json ./

# Install dependencies (no lockfile in upstream Bagisto, so npm ci is not possible)
RUN npm install --no-audit --no-fund

# Copy full application source and build frontend assets
COPY --from=base /var/www/html /app

# Ensure package.json is configured for ESM (re-apply after full copy)
RUN node -e "const pkg = require('./package.json'); pkg.type = 'module'; require('fs').writeFileSync('./package.json', JSON.stringify(pkg, null, 2));"

RUN npm run build

# ============================================
# Final stage
# ============================================
FROM base AS final

# Copy built assets from frontend builder
COPY --from=frontend-builder /app/public /var/www/html/public

# Create the public/storage → storage/app/public symlink, set up Caddy dirs,
# and fix permissions — all in one layer to reduce image size
RUN ln -sf /var/www/html/storage/app/public /var/www/html/public/storage \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /data /config \
        /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Run container as www-data user
USER www-data

# FrankenPHP serves HTTP on port 8080 (non-privileged for www-data)
EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
