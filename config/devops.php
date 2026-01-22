<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Configuration Values
    |--------------------------------------------------------------------------
    |
    | These values are used as defaults when running the devops:setup command.
    | Users can override these values via command-line options or interactive prompts.
    |
    */

    'defaults' => [
        // Default GCP region for resource deployment
        'gcp_region' => env('GCP_REGION', 'europe-west1'),

        // Default application name (used in Cloud Run service naming)
        'app_name' => env('APP_NAME', 'backend'),

        // Docker configuration defaults
        'enable_frontend' => false,         // Enable Node.js for frontend asset compilation
        'node_version' => '22',             // Node.js version (18, 20, 22)
        'package_manager' => 'pnpm',        // Package manager (npm, pnpm)
        'enable_imagick' => false,          // Enable ImageMagick extension
        'enable_redis' => false,            // Enable Redis PHP extension
    ],

    /*
    |--------------------------------------------------------------------------
    | Stubs Path
    |--------------------------------------------------------------------------
    |
    | Path to the stubs directory containing template files (Docker, CI/CD).
    | This path is relative to the package root directory.
    |
    | For Terraform infrastructure, use Spine Core:
    | https://github.com/fil-donadoni/spine-core
    |
    */

    'stubs_path' => __DIR__.'/../stubs',

    /*
    |--------------------------------------------------------------------------
    | Target Paths
    |--------------------------------------------------------------------------
    |
    | Destination paths where files will be copied in the Laravel project.
    | These are relative to the Laravel project root.
    |
    */

    'target_paths' => [
        'docker' => 'docker',
        'cicd' => base_path(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | The package automatically registers a /health endpoint for Cloud Run
    | and load balancer health checks. Customize the path if needed.
    |
    */

    'health' => [
        'path' => env('HEALTH_CHECK_PATH', '/health'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Cloud Platform Services - Zero Configuration
    |--------------------------------------------------------------------------
    |
    | This package uses Application Default Credentials (ADC) for all GCP services.
    | No configuration needed - works automatically in both production and development.
    |
    | Authentication (automatic):
    | - Production (Cloud Run): Uses service account identity automatically
    | - Local development: Run `gcloud auth application-default login` once
    |
    | Available services (auto-registered):
    | - Storage: app('gcp.storage') - Google Cloud Storage client
    | - PubSub: app('gcp.pubsub') - Google Cloud Pub/Sub client
    | - Logging: LOG_CHANNEL=google_cloud - Google Cloud Logging
    |
    | No configuration files needed. No manual setup required.
    |
    */

];
