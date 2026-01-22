<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HealthCheckService
{
    public function performHealthCheck(bool $detailed = false): array
    {
        Log::info('Health check endpoint. Environment: ' . config('app.env'));

        return [
            'service' => config('app.name', 'Laravel'),
            'environment' => config('app.env'),
            'status' => 200,
            'timestamp' => now()->toISOString(),
            'database' => $this->checkDatabaseConnection($detailed),
            'gcp_services' => $this->checkGcpServices($detailed),
        ];
    }

    private function checkDatabaseConnection(bool $detailed = false): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'status' => 'OK',
                'connection' => 'healthy',
            ];
        } catch (\Exception $e) {
            $result = [
                'status' => 'ERROR',
                'connection' => 'failed',
            ];

            if ($detailed) {
                $result['error'] = $e->getMessage();
            }

            return $result;
        }
    }

    private function checkGcpServices(bool $detailed = false): array
    {
        return [
            'cloud_storage' => $this->checkCloudStorage($detailed),
            'pub_sub' => $this->checkPubSub($detailed),
            'cloud_logging' => $this->checkCloudLogging($detailed),
        ];
    }

    private function checkCloudStorage(bool $detailed = false): array
    {
        try {
            $adcCredentials = $this->getAdcCredentials();

            if ($adcCredentials) {
                $result = ['status' => 'configured', 'auth_method' => 'ADC'];

                if ($detailed) {
                    $result['details'] = [
                        'project_id' => config('filesystems.disks.gcs.project_id') ?: ($adcCredentials['project_id'] ?? 'from_adc'),
                        'bucket' => config('filesystems.disks.gcs.bucket'),
                        'auth_type' => $adcCredentials['type'] ?? 'default',
                    ];
                }

                return $result;
            }

            return [
                'status' => 'not_configured',
                'details' => $detailed ? ['error' => 'No ADC credentials available'] : null,
            ];
        } catch (\Exception $e) {
            $result = ['status' => 'error'];

            if ($detailed) {
                $result['details'] = ['error' => $e->getMessage()];
            }

            return $result;
        }
    }

    private function checkPubSub(bool $detailed = false): array
    {
        try {
            $adcCredentials = $this->getAdcCredentials();

            if ($adcCredentials) {
                $result = ['status' => 'configured', 'auth_method' => 'ADC'];

                if ($detailed) {
                    $result['details'] = [
                        'project_id' => config('services.gcp.pub_sub.project_id') ?: ($adcCredentials['project_id'] ?? 'from_adc'),
                        'topic' => config('services.gcp.pub_sub.topic'),
                        'subscription' => config('services.gcp.pub_sub.subscription'),
                        'auth_type' => $adcCredentials['type'] ?? 'default',
                    ];
                }

                return $result;
            }

            return [
                'status' => 'not_configured',
                'details' => $detailed ? ['error' => 'No ADC credentials available'] : null,
            ];
        } catch (\Exception $e) {
            $result = ['status' => 'error'];

            if ($detailed) {
                $result['details'] = ['error' => $e->getMessage()];
            }

            return $result;
        }
    }

    private function checkCloudLogging(bool $detailed = false): array
    {
        try {
            $adcCredentials = $this->getAdcCredentials();

            if ($adcCredentials) {
                $result = ['status' => 'configured', 'auth_method' => 'ADC'];

                if ($detailed) {
                    $result['details'] = [
                        'project_id' => config('services.gcp.logging.project_id') ?: ($adcCredentials['project_id'] ?? ''),
                        'log_channel' => config('logging.default'),
                        'auth_type' => $adcCredentials['type'] ?? 'default',
                    ];
                }

                return $result;
            }

            return [
                'status' => 'not_configured',
                'details' => $detailed ? ['error' => 'No ADC credentials available'] : null,
            ];
        } catch (\Exception $e) {
            $result = ['status' => 'error'];

            if ($detailed) {
                $result['details'] = ['error' => $e->getMessage()];
            }

            return $result;
        }
    }

    private function getAdcCredentials(): ?array
    {
        // Check for GOOGLE_APPLICATION_CREDENTIALS environment variable
        $credentialsFile = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if ($credentialsFile && file_exists($credentialsFile)) {
            return json_decode(file_get_contents($credentialsFile), true) ?: ['type' => 'service_account_file'];
        }

        // Check if running on GCP (metadata server available)
        if ($this->isRunningOnGcp()) {
            return ['type' => 'gce_metadata', 'project_id' => $this->getProjectIdFromMetadata()];
        }

        // Check for gcloud CLI credentials
        $gcloudCredentialsPath = $this->getGcloudCredentialsPath();
        if ($gcloudCredentialsPath && file_exists($gcloudCredentialsPath)) {
            return json_decode(file_get_contents($gcloudCredentialsPath), true) ?: ['type' => 'gcloud_cli'];
        }

        return null;
    }

    private function isRunningOnGcp(): bool
    {
        // Quick check for GCP metadata server
        $metadataHost = getenv('GCE_METADATA_HOST') ?: 'metadata.google.internal';

        $context = stream_context_create([
            'http' => [
                'timeout' => 1,
                'header' => "Metadata-Flavor: Google\r\n",
            ],
        ]);

        $result = @file_get_contents("http://{$metadataHost}/computeMetadata/v1/project/project-id", false, $context);

        return $result !== false;
    }

    private function getProjectIdFromMetadata(): ?string
    {
        $metadataHost = getenv('GCE_METADATA_HOST') ?: 'metadata.google.internal';

        $context = stream_context_create([
            'http' => [
                'timeout' => 1,
                'header' => "Metadata-Flavor: Google\r\n",
            ],
        ]);

        $result = @file_get_contents("http://{$metadataHost}/computeMetadata/v1/project/project-id", false, $context);

        return $result !== false ? $result : null;
    }

    private function getGcloudCredentialsPath(): ?string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (!$home) {
            return null;
        }

        $path = $home.'/.config/gcloud/application_default_credentials.json';

        return file_exists($path) ? $path : null;
    }
}
