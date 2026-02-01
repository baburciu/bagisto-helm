# Bagisto

This Dockerfile creates a production-ready Bagisto image with optional extensions pre-installed.

**Key Feature:** The Dockerfile automatically downloads Bagisto source code from the official GitHub repository during the build process - no manual setup required!

## How It Works

1. **Downloads Bagisto**: Clones the official Bagisto repository from https://github.com/bagisto/bagisto
2. **Checks out specific version**: Uses the version tag you specify (e.g., `v2.3.6`)
3. **Installs dependencies**: Runs `composer install` to get all PHP packages
4. **Installs extensions**: Optionally installs Stripe Payment Gateway and other extensions
5. **Optimizes for production**: Caches configs, routes, and builds frontend assets

### The Process (Step by Step)

```
┌─────────────────────────────────────────────────────────────┐
│ 1. You run: docker build -f Dockerfile.production .        │
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
│    git checkout v2.3.6                                      │
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
│ 5. Dockerfile installs Stripe Payment Gateway:             │
│    composer require codenteq/stripe-payment-gateway        │
│                                                             │
│    This adds the payment extension                          │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 6. Result: Docker image with Bagisto + Stripe ready!       │
└─────────────────────────────────────────────────────────────┘
```

## Features

- PHP 8.3 with FPM
- All required PHP extensions (GD, Imagick, Intl, etc.)
- Composer 2.7
- Node.js 23
- Optimized for production (caching, permissions, etc.)
- Version-controlled extension installation

## Building the Image

### Basic Build (with latest Stripe Payment Gateway)

```bash
docker build -f Dockerfile.production -t bagisto-production:latest .
```

### Build with Specific Stripe Gateway Version

To pin the Stripe Payment Gateway to a specific version:

```bash
docker build \
  --build-arg STRIPE_GATEWAY_VERSION="^1.0" \
  -f Dockerfile.production \
  -t bagisto-production:2.3.11 .
```

### Build without Stripe Gateway

To build without installing the Stripe Payment Gateway:

```bash
docker build \
  --build-arg INSTALL_STRIPE_GATEWAY="false" \
  -f Dockerfile.production \
  -t bagisto-production:2.3.11 .
```

### Build with Specific Bagisto Version

To build with a specific Bagisto version from GitHub:

```bash
docker build \
  --build-arg BAGISTO_VERSION="v2.3.11" \
  --build-arg STRIPE_GATEWAY_VERSION="^1.0" \
  -f Dockerfile.production \
  -t bagisto-production:2.3.11 .
```

### Build with Latest Bagisto (master branch)

```bash
docker build \
  --build-arg BAGISTO_VERSION="master" \
  -f Dockerfile.production \
  -t bagisto-production:latest .
```

### Version Constraint Examples for Stripe
- It downloads Bagisto directly from GitHub
- It checks out the specific version you want (controlled by `BAGISTO_VERSION`)
- You don't need to manually download or manage the Bagisto source code

### 2. Version Control-stripe-1.2.3 .
```

## Build Arguments

| Argument | Description | Default | Example |
|----------|-------------|---------|---------|
| `STRIPE_GATEWAY_VERSION` | Version constraint for Stripe gateway | `*` (latest) | `^1.0`, `1.2.3`, `~1.5` |
| `INSTALL_STRIPE_GATEWAY` | Whether to install Stripe gateway | `true` | `true`, `false` |

## Version Constraint Examples

- `*` - Install latest version (default)
- `^1.0` - Install version >= 1.0 and < 2.0 (recommended)
- `~1.5` - Install version >= 1.5 and < 1.6
- `1.2.3` - Install exact version 1.2.3
- `>=1.0 <2.0` - Install version between 1.0 and 2.0

## Using with Helm Chart

Update your Helm `values.yaml` to use the custom image:

```yaml
image:
  repository: your-registry/bagisto-production
  tag: "2.3.11-stripe-1.0"
  pullPolicy: IfNotPresent
```

## Tagging Strategy

Recommended tagging format: `{bagisto-version}-stripe-{stripe-version}`

Examples:
- `bagisto-production:2.3.11-stripe-1.0`
- `bagisto-production:2.3.11-stripe-latest`
- `bagisto-production:2.3.11-no-stripe`

## Version Control in Git

Create a `.env.docker` file to version control build arguments:

```bash
# .env.docker
STRIPE_GATEWAY_VERSION=^1.0
INSTALL_STRIPE_GATEWAY=true
```

Then build using:

```bash
docker build \
  --build-arg STRIPE_GATEWAY_VERSION=$(grep STRIPE_GATEWAY_VERSION .env.docker | cut -d '=' -f2) \
  --build-arg INSTALL_STRIPE_GATEWAY=$(grep INSTALL_STRIPE_GATEWAY .env.docker | cut -d '=' -f2) \
  -f Dockerfile.production \
  -t bagisto-production:latest .
```

## CI/CD Pipeline Example

### GitHub Actions

```yaml
name: Build Bagisto Production Image

on:
  push:
    branches: [main]

env:
  STRIPE_GATEWAY_VERSION: "^1.0"

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Build Docker image
        run: |
          docker build \
            --build-arg STRIPE_GATEWAY_VERSION=${{ env.STRIPE_GATEWAY_VERSION }} \
            -f Dockerfile.production \
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

### Check installed Stripe version

```bash
docker run --rm bagisto-production:latest composer show codenteq/stripe-payment-gateway
```

### Verify extensions are loaded

```bash
docker run --rm bagisto-production:latest php artisan package:discover
```
