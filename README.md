# Spine Wire Laravel

Deployment files for Laravel applications on Google Cloud Platform (Cloud Run). Part of the Spine project.

## Overview

This package provides:

- **Docker Configuration**: Multi-stage Dockerfile with FrankenPHP + Octane
- **CI/CD Pipeline**: Cloud Build configuration for automated deployments
- **Health Check**: Controller and service for Cloud Run probes
- **GCP Clients**: Pre-configured Cloud Storage, Pub/Sub and Cloud Run Jobs clients

## Architecture

This package is the **deployment companion** to [Spine Core](https://github.com/fil-donadoni/spine-core):

| Component | Responsibility |
|-----------|----------------|
| **Spine Wire Laravel** | Docker, cloudbuild.yaml, health check, GCP clients |
| **Spine Core** | Terraform infrastructure, environment variables, secrets |

**Important**: All environment variables (`APP_ENV`, `DB_*`, `GOOGLE_CLOUD_PROJECT`, `LOG_CHANNEL`, etc.) are configured by Terraform via Spine Core. No manual `.env` configuration is needed in production.

## Requirements

- PHP ^8.2
- Laravel ^11.0 or ^12.0
- Infrastructure provisioned via [Spine Core](https://github.com/fil-donadoni/spine-core)

## Installation
Add this repository to your `composer.json`:

```bash title="composer.json"
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/fil-donadoni/spine-wire-laravel"
    }
]
```

Then

```bash
composer require fil-donadoni/spine-wire-laravel --dev
```

## Setup

```bash
php artisan devops:setup
```

The command reads `GOOGLE_CLOUD_PROJECT` from your `.env` if available, otherwise prompts for it.

### Options

| Option | Description |
|--------|-------------|
| `--project-id` | GCP Project ID (reads from `GOOGLE_CLOUD_PROJECT` env if not provided) |
| `--client-name` | Client name for resource naming (required) |
| `--region` | GCP region (default: europe-west1) |
| `--app-name` | Application name (default: backend) |
| `--force` | Overwrite existing files |
| `--ignore-extras` | Skip Docker feature prompts |

### Generated Files

```
your-laravel-project/
├── docker/
│   ├── Dockerfile
│   ├── Dockerfile.base
│   ├── entrypoints/
│   │   ├── service-entrypoint.sh
│   │   ├── queue-entrypoint.sh
│   │   └── job-entrypoint.sh
│   └── php/
│       └── php.ini
├── cloudbuild.yaml
├── .dockerignore
├── app/
│   ├── Http/Controllers/HealthCheckController.php
│   └── Services/HealthCheckService.php
└── routes/web.php  # /health route added
```

## Configuration

### Environment Variables (.env)

```env
GOOGLE_CLOUD_PROJECT=your-gcp-project-id
GCS_BUCKET=your-bucket-name
```

### config/filesystems.php (optional)

To use Cloud Storage as a Laravel filesystem disk, install the Spatie package:

```bash
composer require spatie/laravel-google-cloud-storage
```

Then add a disk in `config/filesystems.php`:

```php
'disks' => [
    // ... other disks ...

    'gcs' => [
        'driver' => 'gcs',
        'project_id' => env('GOOGLE_CLOUD_PROJECT'),
        'bucket' => env('GCS_BUCKET'),
        'path_prefix' => env('GCS_PATH_PREFIX', ''),
        'visibility' => 'public',
        // Uses Application Default Credentials (ADC) - no key file needed
    ],
],
```

Usage:

```php
Storage::disk('gcs')->put('path/file.txt', $contents);
Storage::disk('gcs')->get('path/file.txt');
```

## Local Development

Authenticate with GCP:

```bash
gcloud auth application-default login
```

### Build and Run

```bash
# Build without base image
docker build \
  --build-arg BASE_IMAGE=dunglas/frankenphp:php8.4-alpine \
  -f docker/Dockerfile \
  -t my-app .

# Run web server
docker run -p 8080:8080 my-app

# Run queue worker
docker run my-app /queue-entrypoint.sh

# Test health check
curl http://localhost:8080/health
```

## GCP Services

The package registers `StorageClient`, `PubSubClient` and `CloudRunJobService` as singletons using Application Default Credentials (ADC).

### Cloud Storage

```php
use Google\Cloud\Storage\StorageClient;

// Via dependency injection
public function __construct(private StorageClient $storage) {}

// Via app helper
$storage = app('gcp.storage');

// Upload
$bucket = $storage->bucket('my-bucket');
$bucket->upload(fopen('/path/to/file.txt', 'r'), [
    'name' => 'destination/file.txt'
]);

// Download
$object = $bucket->object('path/to/file.txt');
$contents = $object->downloadAsString();

// Signed URL
$url = $object->signedUrl(new \DateTime('+1 hour'));
```

### Pub/Sub

```php
use Google\Cloud\PubSub\PubSubClient;

// Via dependency injection
public function __construct(private PubSubClient $pubsub) {}

// Via app helper
$pubsub = app('gcp.pubsub');

// Publish
$topic = $pubsub->topic('my-topic');
$topic->publish([
    'data' => json_encode(['event' => 'user.created', 'user_id' => 123])
]);

// Pull messages
$subscription = $pubsub->subscription('my-subscription');
$messages = $subscription->pull(['maxMessages' => 10]);

foreach ($messages as $message) {
    $data = json_decode($message->data(), true);
    $subscription->acknowledge($message);
}
```

### Cloud Run Jobs

Trigger Cloud Run Jobs on-demand via the Cloud Run Admin API. Useful for running import tasks, data processing, or any operation that should execute in a dedicated Cloud Run Job container rather than in a queue worker.

#### Setup

1. Add to `config/services.php`:

```php
'google' => [
    'project_id' => env('GOOGLE_CLOUD_PROJECT'),
    'region' => env('GCP_REGION', 'europe-west1'),
],
```

2. Set the environment variable (if not already present):

```env
GOOGLE_CLOUD_PROJECT=my-project-123456
```

3. Grant the `roles/run.invoker` IAM role to the Cloud Run service account on the target job.

4. For local development, authenticate with:

```bash
gcloud auth application-default login
```

#### Usage

```php
use FilDonadoni\SpineWireLaravel\CloudRun\CloudRunJobService;

// Via dependency injection
public function import(CloudRunJobService $cloudRunJobService)
{
    $cloudRunJobService->run('my-import-job');

    return response()->json([], 202);
}

// Via container
app(CloudRunJobService::class)->run('my-import-job');
```

The `run()` method calls the Cloud Run Admin API v2 (`jobs/:run`) and throws an exception if the request fails.

## Deployment

Push to your repository to trigger Cloud Build, or manually:

```bash
gcloud builds submit --config=cloudbuild.yaml
```

## License

MIT License
