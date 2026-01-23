<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HealthCheckService
{
    public function performHealthCheck(bool $detailed = false): array
    {
        return [
            'service' => config('app.name', 'Laravel'),
            'environment' => config('app.env'),
            'status' => 200,
            'timestamp' => now()->toISOString(),
            'database' => $this->checkDatabaseConnection($detailed),
            'gcp' => $this->checkGcpCredentials($detailed),
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

    private function checkGcpCredentials(bool $detailed = false): array
    {
        $projectId = env('GOOGLE_CLOUD_PROJECT');
        $authMethod = $this->detectAuthMethod();

        $result = [
            'status' => $authMethod ? 'configured' : 'not_configured',
            'auth_method' => $authMethod,
        ];

        if ($detailed) {
            $result['project_id'] = $projectId;
        }

        return $result;
    }

    private function detectAuthMethod(): ?string
    {
        // Check for explicit credentials file
        $credentialsFile = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if ($credentialsFile && file_exists($credentialsFile)) {
            return 'service_account_file';
        }

        // Check if running on GCP (metadata server available)
        if ($this->isRunningOnGcp()) {
            return 'gce_metadata';
        }

        // Check for gcloud CLI credentials
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if ($home) {
            $gcloudPath = $home . '/.config/gcloud/application_default_credentials.json';
            if (file_exists($gcloudPath)) {
                return 'gcloud_cli';
            }
        }

        return null;
    }

    private function isRunningOnGcp(): bool
    {
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
}
