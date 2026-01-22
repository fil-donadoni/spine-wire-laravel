# Docker Configuration

This directory contains Docker configuration files for building Laravel application images optimized for Google Cloud Run.

## Files

- **Dockerfile**: Main application Dockerfile (uses base image for fast builds)
- **Dockerfile.base**: Base image template with pre-compiled dependencies
- **php/**: PHP configuration files
- **entrypoints/**: Service entrypoint scripts

## Quick Start

### Standard Build (Uses Base Image)

```bash
# Build application image (fast, ~1-2 minutes)
docker build -f docker/Dockerfile -t my-laravel-app .
```

This automatically uses the pre-compiled base image from your Artifact Registry.

### Base Image (Prerequisite)

The base image must be built and pushed to Artifact Registry **before** running CI/CD pipelines.

**Using Spine Core (Recommended):**

The base image is managed by [Spine Core](https://github.com/fil-donadoni/spine-core), which handles:
- Creating the Artifact Registry repository
- Building and pushing the base image
- Configuring IAM permissions for Cloud Build

**Manual Fallback (Development/Testing):**

If you need to build locally without a base image:

```bash
docker build \
  --build-arg BASE_IMAGE=dunglas/frankenphp:php8.4-alpine \
  -f docker/Dockerfile \
  -t my-laravel-app .
```

### When to Rebuild Base Image

Rebuild the base image (via Spine Core) when:
- Upgrading PHP version
- Adding/removing PHP extensions
- Adding/removing system packages
- Updating FrankenPHP version

## Architecture

### Multi-Stage Build

```
┌─────────────────────────────────────────┐
│  Base Image (Dockerfile.base)           │
│  - FrankenPHP + PHP 8.4                 │
│  - System packages (postgresql, gd...)  │
│  - PHP extensions compiled              │
│  - Composer installed                   │
│  Size: ~400MB                           │
│  Rebuild: Monthly or when deps change   │
└──────────────┬──────────────────────────┘
               │
               ├─────────────────────────────────┐
               │                                 │
       ┌───────▼───────────┐           ┌────────▼──────────┐
       │  Dependencies     │           │  Runtime          │
       │  (Stage 1)        │           │  (Stage 2)        │
       │                   │           │                   │
       │  - composer       │           │  - Runtime libs   │
       │    install        │──────────▶│    only           │
       │  - Application    │           │  - Copy from      │
       │    code           │           │    dependencies   │
       │                   │           │  - Entrypoints    │
       │  Time: ~1-2 min   │           │                   │
       └───────────────────┘           │  Size: ~200MB     │
                                       └───────────────────┘
```

## Build Time Comparison

### Without Base Image (Standard Approach)
```
Build Step                    Time
─────────────────────────────────
Pull FrankenPHP               30s
Install system packages       2-3 min
Compile PHP extensions        1-2 min
Install Composer              30s
composer install              1-2 min
Copy application files        10s
─────────────────────────────────
TOTAL:                        5-8 minutes
```

### With Base Image (Optimized)
```
Build Step                    Time
─────────────────────────────────
Pull base image (cached)      10s
composer install              1-2 min
Copy application files        10s
─────────────────────────────────
TOTAL:                        1-2 minutes
Savings:                      70-80%
```

## Base Image Management

### When to Rebuild

**Always rebuild when:**
- Upgrading PHP version (8.4 → 8.5)
- Adding PHP extensions (`install-php-extensions redis`)
- Adding system packages (`apk add imagemagick`)
- Updating FrankenPHP base image

**Optional rebuild (recommended monthly):**
- Security updates for system packages
- General maintenance

### Rebuild Commands

Base image builds are managed via **Spine Core**. See the project's documentation for details.

**Manual build (if needed):**

```bash
# Build locally
docker build -f docker/Dockerfile.base -t laravel-base:php8.4-alpine .

# Tag for Artifact Registry
docker tag laravel-base:php8.4-alpine \
  REGION-docker.pkg.dev/PROJECT_ID/CLIENT-APP-base/laravel-base:php8.4-alpine

# Push (requires gcloud auth configure-docker)
docker push REGION-docker.pkg.dev/PROJECT_ID/CLIENT-APP-base/laravel-base:php8.4-alpine
```

## Customization

### Adding PHP Extensions

1. Edit `Dockerfile.base`:
   ```dockerfile
   RUN install-php-extensions \
       zip \
       pdo_pgsql \
       redis \      # New extension
       imagick      # Another new extension
   ```

2. Rebuild base image via Spine Core (or manually as shown above)

3. Your next application build will use the updated base image automatically

### Adding System Packages

1. Edit `Dockerfile.base`:
   ```dockerfile
   RUN apk add --no-cache \
       postgresql-dev \
       imagemagick \    # New package
       ffmpeg           # Another new package
   ```

2. Rebuild base image via Spine Core

### Overriding Base Image Location

If you want to use a different base image registry:

```bash
docker build \
  --build-arg BASE_IMAGE=my-registry.com/my-base:tag \
  -t my-app .
```

## Entrypoints

The image includes three entrypoints for different Cloud Run services:

- **service-entrypoint.sh**: Web server (default)
- **queue-entrypoint.sh**: Queue workers
- **job-entrypoint.sh**: Scheduled jobs

### Running Locally

```bash
# Web server (port 8080)
docker run -p 8080:8080 my-laravel-app

# Queue worker
docker run my-laravel-app /queue-entrypoint.sh

# Custom command
docker run my-laravel-app php artisan tinker
```

## Troubleshooting

### Base Image Not Found

**Error:**
```
ERROR: failed to solve: my-base:tag: not found
```

**Solution:**

1. Build the base image via **Spine Core** first
2. Or use the fallback for local development:
   ```bash
   docker build --build-arg BASE_IMAGE=dunglas/frankenphp:php8.4-alpine -f docker/Dockerfile -t my-app .
   ```

### Slow Builds Despite Base Image

**Causes:**
- Docker layer cache cleared
- Base image not pulled locally
- Network issues downloading base image

**Solution:**
```bash
# Pre-pull base image
docker pull europe-west1-docker.pkg.dev/PROJECT/REPO/laravel-base:php8.4-alpine

# Check local images
docker images | grep laravel-base
```

### Build Fails After Adding Extension

**Error:**
```
ERROR: install-php-extensions failed
```

**Solution:**
1. Check extension name is correct
2. Some extensions need system packages first:
   ```dockerfile
   # Example: imagick needs imagemagick-dev
   RUN apk add --no-cache imagemagick-dev
   RUN install-php-extensions imagick
   ```

## Cost Analysis

### Cloud Build Pricing (example)

**Without base image:**
- 20 builds/day × 8 minutes = 160 minutes/day
- 160 min × 30 days = 4,800 minutes/month
- First 120 min free, then $0.003/min
- Cost: 4,680 × $0.003 = **~$14/month**

**With base image:**
- 20 builds/day × 2 minutes = 40 minutes/day
- 40 min × 30 days = 1,200 minutes/month
- First 120 min free, then $0.003/min
- Cost: 1,080 × $0.003 = **~$3/month**

**Savings: ~$11/month (78%)**

Plus:
- Faster deployments (developer productivity)
- Lower network bandwidth usage
- Better CI/CD pipeline performance

## References

- [FrankenPHP Documentation](https://frankenphp.dev/)
- [Docker Multi-Stage Builds](https://docs.docker.com/build/building/multi-stage/)
- [GCP Artifact Registry](https://cloud.google.com/artifact-registry/docs)
- [Cloud Run Container Guide](https://cloud.google.com/run/docs/building/containers)
