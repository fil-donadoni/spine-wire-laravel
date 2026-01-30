<?php

namespace FilDonadoni\SpineWireLaravel\Storage;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\Credentials\ImpersonatedServiceAccountCredentials;
use Google\Auth\SignBlobInterface;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter as FlysystemGcsAdapter;

/**
 * Google Cloud Storage integration for Laravel
 *
 * Uses Application Default Credentials (ADC) for authentication.
 * Works seamlessly in both Cloud Run and local development with zero configuration.
 *
 * Authentication:
 * - Cloud Run: Automatically uses service account identity
 * - Local: Run `gcloud auth application-default login` once
 *
 * Prerequisites:
 * - Service account needs appropriate Storage IAM roles:
 *   - "roles/storage.objectViewer" for read access
 *   - "roles/storage.objectCreator" for write access
 *   - "roles/storage.objectAdmin" for full access
 *
 * Usage:
 * ```php
 * $storage = app('gcp.storage');
 * $bucket = $storage->bucket('my-bucket');
 * $bucket->upload(fopen('/path/to/file.txt', 'r'));
 * ```
 */
class GoogleCloudStorageServiceProvider extends ServiceProvider
{
    /**
     * Register Google Cloud Storage client with ADC
     */
    public function register(): void
    {
        // Create the singleton instance
        $this->app->singleton(StorageClient::class, function () {
            // Use Application Default Credentials (ADC)
            // No credentials specified = automatic authentication
            // - Cloud Run: uses service account identity
            // - Local: uses `gcloud auth application-default login`
            $config = [];

            // Only set projectId if available (ADC can auto-detect it)
            $projectId = getenv('GOOGLE_CLOUD_PROJECT');
            if ($projectId) {
                $config['projectId'] = $projectId;
            }

            return new StorageClient($config);
        });

        // Alias string key to same instance (both point to same singleton)
        $this->app->alias(StorageClient::class, 'gcp.storage');
    }

    /**
     * Register the `gcs` Flysystem driver
     */
    public function boot(): void
    {
        /** @var FilesystemManager $filesystemManager */
        $filesystemManager = $this->app->make('filesystem');

        $filesystemManager->extend('gcs', function ($app, array $config) {
            $storageClient = $this->buildStorageClient($config);

            $bucket = $storageClient->bucket($config['bucket']);
            $prefix = $config['path_prefix'] ?? '';

            $adapter = new FlysystemGcsAdapter($bucket, $prefix);
            $driver = new Filesystem($adapter, $config);

            return new GoogleCloudStorageAdapter($driver, $adapter, $config, $bucket);
        });
    }

    /**
     * Build a StorageClient for the Flysystem driver.
     *
     * On Cloud Run, ADC returns GCECredentials which supports signing natively.
     * Locally, ADC returns UserRefreshCredentials which cannot sign.
     * When `service_account` is configured, we impersonate that SA so signing works everywhere.
     */
    private function buildStorageClient(array $config): StorageClient
    {
        $clientConfig = [];

        $projectId = getenv('GOOGLE_CLOUD_PROJECT');
        if ($projectId) {
            $clientConfig['projectId'] = $projectId;
        }

        if ($serviceAccount = $config['service_account'] ?? null) {
            $scope = 'https://www.googleapis.com/auth/cloud-platform';
            $sourceCredentials = ApplicationDefaultCredentials::getCredentials($scope);

            if (!$sourceCredentials instanceof SignBlobInterface) {
                $clientConfig['credentialsFetcher'] = new ImpersonatedServiceAccountCredentials(
                    $scope,
                    [
                        'service_account_impersonation_url' => sprintf(
                            'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/%s:generateAccessToken',
                            $serviceAccount,
                        ),
                        'source_credentials' => $sourceCredentials,
                    ],
                );
            }
        }

        return new StorageClient($clientConfig);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'gcp.storage',
            StorageClient::class,
        ];
    }
}
