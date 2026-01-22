<?php

namespace FilDonadoni\SpineWireLaravel\PubSub;

use Google\Cloud\PubSub\PubSubClient;
use Illuminate\Support\ServiceProvider;

/**
 * Google Cloud Pub/Sub integration for Laravel
 *
 * Uses Application Default Credentials (ADC) for authentication.
 * Works seamlessly in both Cloud Run and local development with zero configuration.
 *
 * Authentication:
 * - Cloud Run: Automatically uses service account identity
 * - Local: Run `gcloud auth application-default login` once
 *
 * Prerequisites:
 * - Service account needs appropriate Pub/Sub IAM roles:
 *   - "roles/pubsub.publisher" for publishing messages
 *   - "roles/pubsub.subscriber" for receiving messages
 *   - "roles/pubsub.editor" for full access
 *
 * Usage:
 * ```php
 * $pubsub = app('gcp.pubsub');
 * $topic = $pubsub->topic('my-topic');
 * $topic->publish(['data' => 'Hello World']);
 * ```
 */
class GoogleCloudPubSubServiceProvider extends ServiceProvider
{
    /**
     * Register Google Cloud Pub/Sub client with ADC
     */
    public function register(): void
    {
        // Create the singleton instance
        $this->app->singleton(PubSubClient::class, function () {
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

            return new PubSubClient($config);
        });

        // Alias string key to same instance (both point to same singleton)
        $this->app->alias(PubSubClient::class, 'gcp.pubsub');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'gcp.pubsub',
            PubSubClient::class,
        ];
    }
}
