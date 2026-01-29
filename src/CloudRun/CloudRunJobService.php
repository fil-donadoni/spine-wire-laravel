<?php

namespace FilDonadoni\SpineWireLaravel\CloudRun;

use Google\Client;
use Illuminate\Support\Facades\Http;

class CloudRunJobService
{
    public function __construct(
        private string $projectId,
        private string $region,
    ) {}

    public function run(string $jobName): void
    {
        $url = "https://run.googleapis.com/v2/projects/{$this->projectId}/locations/{$this->region}/jobs/{$jobName}:run";

        $response = Http::withToken($this->getAccessToken())
            ->withBody('{}', 'application/json')
            ->post($url);

        $response->throw();
    }

    private function getAccessToken(): string
    {
        $client = new Client();
        $client->useApplicationDefaultCredentials();
        $client->setScopes(['https://www.googleapis.com/auth/cloud-platform']);
        $client->fetchAccessTokenWithAssertion();

        return $client->getAccessToken()['access_token'];
    }
}
