# Spine Wire Laravel

Deployment files for Laravel applications on Google Cloud Platform (Cloud Run). Part of the Spine project.

## Overview

This package provides deployment configuration for Laravel applications running on GCP Cloud Run:

- **Docker Configuration**: Multi-stage Dockerfile with FrankenPHP + Octane
  - Optional Node.js support (Vite, npm/pnpm)
  - Optional ImageMagick for image processing
  - Optional Redis PHP extension
  - Pre-compiled base image support for 70-80% faster builds
- **CI/CD Pipeline**: Cloud Build configuration for automated deployments
- **Health Check**: Controller and service for Cloud Run probes
- **Logging**: Simple stderr-based logging (Cloud Run captures automatically)

## Architecture

This package is the **deployment companion** to [Spine Core](https://github.com/fil-donadoni/spine-core):

| Component | Responsibility |
|-----------|----------------|
| **Spine Wire Laravel** (this package) | Docker, cloudbuild.yaml, health check |
| **Spine Core** | Terraform infrastructure, base image builds, configuration UI |

### Deployment Flow

```
┌─────────────────────────────────────────────────────────────┐
│  Spine Core                                                 │
│  1. Terraform → Cloud Run, Artifact Registry, IAM, etc.     │
│  2. Build and push base Docker image                        │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Laravel Project + Spine Wire                               │
│  php artisan devops:setup → Dockerfile, cloudbuild.yaml     │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Cloud Build (CI/CD)                                        │
│  1. Pull base image (from Artifact Registry)                │
│  2. Build application image                                 │
│  3. Deploy to Cloud Run                                     │
└─────────────────────────────────────────────────────────────┘
```

## Requirements

- **PHP**: ^8.2
- **Laravel**: ^11.0 or ^12.0
- **GCP Account**: With infrastructure provisioned via Spine Core
- **Docker**: For local development

## Installation

```bash
composer require fil-donadoni/spine-wire-laravel --dev
```

## Setup

Run the interactive setup wizard:

```bash
php artisan devops:setup
```

You'll be prompted for:
- **GCP Project ID**: Your Google Cloud project ID
- **Client Name**: Used for resource naming (defaults to directory name)
- **GCP Region**: Deployment region (default: europe-west1)
- **App Name**: Application name (default: backend)
- **Docker Configuration**:
  - Node.js for frontend builds (with version selection)
  - Package manager (npm/pnpm)
  - ImageMagick for image processing
  - Redis PHP extension

### Generated Files

```
your-laravel-project/
├── docker/
│   ├── Dockerfile           # Application Dockerfile
│   ├── Dockerfile.base      # Base image template
│   ├── entrypoints/         # Service entrypoints
│   │   ├── service-entrypoint.sh
│   │   ├── queue-entrypoint.sh
│   │   └── job-entrypoint.sh
│   └── php/
│       └── php.ini
├── cloudbuild.yaml          # CI/CD pipeline
├── .dockerignore
├── app/
│   ├── Http/Controllers/
│   │   └── HealthCheckController.php
│   └── Services/
│       └── HealthCheckService.php
└── routes/web.php           # (modified: /health route added)
```

### Command Options

```bash
php artisan devops:setup \
    --project-id=my-gcp-project \
    --client-name=my-client \
    --region=europe-west1 \
    --app-name=backend \
    --force \
    --ignore-extras
```

| Option | Description |
|--------|-------------|
| `--project-id` | GCP Project ID (required) |
| `--client-name` | Client name for resource naming |
| `--region` | GCP region (default: europe-west1) |
| `--app-name` | Application name (default: backend) |
| `--force` | Overwrite existing files |
| `--ignore-extras` | Skip Docker feature prompts (use defaults) |

## Local Development

### Build Without Base Image

For local testing without the pre-built base image:

```bash
docker build \
  --build-arg BASE_IMAGE=dunglas/frankenphp:php8.4-alpine \
  -f docker/Dockerfile \
  -t my-app .
```

### Run Locally

```bash
# Web server
docker run -p 8080:8080 my-app

# Queue worker
docker run my-app /queue-entrypoint.sh

# Custom command
docker run my-app php artisan tinker
```

### Test Health Check

```bash
curl http://localhost:8080/health
```

## Logging

Cloud Run automatically captures stdout/stderr and sends them to Cloud Logging. No special configuration needed.

### Configuration

Set `LOG_CHANNEL=stderr` in your environment. Laravel's built-in stderr channel works perfectly:

```env
LOG_CHANNEL=stderr
LOG_LEVEL=debug
```

That's it. All `Log::info()`, `Log::error()`, etc. calls will appear in Cloud Logging automatically.

### Usage

```php
use Illuminate\Support\Facades\Log;

Log::info('User logged in', ['user_id' => $user->id]);
Log::error('Payment failed', ['order_id' => $order->id]);
```

Logs appear in Cloud Logging under `run.googleapis.com/stdout` or `run.googleapis.com/stderr`.

## Deployment

### Prerequisites

Before deploying, ensure infrastructure is provisioned via **Spine Core**:
1. Cloud Run service created
2. Artifact Registry repositories created
3. Base image built and pushed
4. IAM permissions configured

### Deploy via Cloud Build

Push to your repository to trigger Cloud Build, or manually:

```bash
gcloud builds submit --config=cloudbuild.yaml
```

## Security

- Never commit `.env` files with real credentials
- Use GCP Secret Manager for sensitive data (configured via Spine Core)
- Regularly update base images for security patches

## License

MIT License - see [LICENSE](LICENSE) file for details

## Credits

Built with:
- [FrankenPHP](https://frankenphp.dev/)
- [Laravel Octane](https://laravel.com/docs/octane)
- [Google Cloud Platform](https://cloud.google.com/)
