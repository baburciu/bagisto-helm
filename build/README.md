# Bagisto

This Dockerfile creates a production-ready Bagisto image.

**Key Feature:** The Dockerfile automatically downloads Bagisto source code from the official GitHub repository during the build process - no manual setup required!

## How It Works

1. **Downloads Bagisto**: Clones the official Bagisto repository from https://github.com/bagisto/bagisto
2. **Checks out specific version**: Uses the version tag you specify (e.g., `v2.5.0`)
3. **Installs dependencies**: Runs `composer install` to get all PHP packages
4. **Optimizes for production**: Caches configs, routes, and builds frontend assets

### The Process (Step by Step)

```
┌─────────────────────────────────────────────────────────────┐
│ 1. You run: docker build -f build/Dockerfile .        │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. Dockerfile downloads Bagisto from GitHub:               │
│    git clone https://github.com/bagisto/bagisto.git        │
│                                                             │
│    This gets the official Bagisto source code              │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. Dockerfile checks out specific version:                 │
│    git checkout v2.5.0                                      │
│                                                             │
│    This pins it to a stable release                         │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. Dockerfile installs Bagisto dependencies:               │
│    composer install                                         │
│                                                             │
│    This downloads all PHP packages Bagisto needs           │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 5. Result: Docker image with Bagisto ready!                │
└─────────────────────────────────────────────────────────────┘
```

## Features

- [FrankenPHP](https://frankenphp.dev) (Caddy + embedded PHP 8.4) — single process serves both static files and PHP
- All required PHP extensions (GD, Imagick, Intl, etc.)
- Composer 2.9
- Node.js 20 (frontend build stage)
- Optimized for production (caching, permissions, non-root operation)
- Stripe payments via Bagisto's bundled `Webkul/Stripe` package (no extra install)

## Building the Image

### Basic Build

```bash
docker build -f build/Dockerfile -t bagisto-production:latest .
```

### Build with Specific Bagisto Version

To build with a specific Bagisto version from GitHub:

```bash
docker build \
  --build-arg BAGISTO_VERSION="v2.5.0" \
  -f build/Dockerfile \
  -t bagisto-production:2.5.0 .
```

### Build with Latest Bagisto (master branch)

```bash
docker build \
  --build-arg BAGISTO_VERSION="master" \
  -f build/Dockerfile \
  -t bagisto-production:latest .
```

## Build Arguments

| Argument | Description | Default | Example |
|----------|-------------|---------|---------|
| `BAGISTO_VERSION` | Bagisto git tag or branch to build | `v2.5.0` | `v2.5.0`, `master` |
| `BAGISTO_REPO` | Bagisto git repository to clone | `https://github.com/bagisto/bagisto.git` | a fork URL |

## Stripe

Bagisto bundles its own Stripe payment method (`packages/Webkul/Stripe`, using `stripe/stripe-php ^17`), so
`codenteq/stripe-payment-gateway` is no longer installed — it cannot be resolved against Bagisto's
`stripe-php` requirement and registers the same `sales.payment_methods.stripe` config key. Configure keys under
Admin → Configuration → Sales → Payment Methods → Stripe.

## Using with Helm Chart

Update your Helm `values.yaml` to use the custom image:

```yaml
image:
  repository: your-registry/bagisto-production
  tag: "v2.5.0"
  pullPolicy: IfNotPresent
```

## Tagging Strategy

The CI workflow (`.github/workflows/build-image.yml`) tags images with `{bagisto-version}` on manual dispatch and
`{bagisto-version}-{short-sha}` on pushes, plus branch/semver/`latest` tags.

## CI/CD Pipeline Example

### GitHub Actions

```yaml
name: Build Bagisto Production Image

on:
  push:
    branches: [main]

env:
  BAGISTO_VERSION: "v2.5.0"

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Build Docker image
        run: |
          docker build \
            --build-arg BAGISTO_VERSION=${{ env.BAGISTO_VERSION }} \
            -f build/Dockerfile \
            -t ${{ secrets.REGISTRY }}/bagisto-production:${{ github.sha }} \
            -t ${{ secrets.REGISTRY }}/bagisto-production:latest .

      - name: Push to registry
        run: |
          docker push ${{ secrets.REGISTRY }}/bagisto-production:${{ github.sha }}
          docker push ${{ secrets.REGISTRY }}/bagisto-production:latest
```

## Adding More Extensions

To add additional Bagisto extensions, edit the Dockerfile and add more build arguments:

```dockerfile
ARG PAYPAL_EXTENSION_VERSION="*"
ARG INSTALL_PAYPAL_EXTENSION="false"

RUN if [ "$INSTALL_PAYPAL_EXTENSION" = "true" ]; then \
        composer require vendor/paypal-extension:"${PAYPAL_EXTENSION_VERSION}" --no-interaction; \
    fi
```

## Troubleshooting

### Check installed Stripe SDK version

```bash
docker run --rm bagisto-production:latest composer show stripe/stripe-php
```

### Verify extensions are loaded

```bash
docker run --rm bagisto-production:latest php artisan package:discover
```
