<?php

namespace FilDonadoni\SpineWireLaravel\CloudRun;

use Illuminate\Support\ServiceProvider;

class CloudRunServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudRunJobService::class, function () {
            return new CloudRunJobService(
                projectId: config('services.google.project_id'),
                region: config('services.google.region', 'europe-west1'),
            );
        });
    }

    public function provides(): array
    {
        return [
            CloudRunJobService::class,
        ];
    }
}
