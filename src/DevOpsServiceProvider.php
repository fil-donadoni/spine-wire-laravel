<?php

namespace FilDonadoni\SpineWireLaravel;

use FilDonadoni\SpineWireLaravel\Commands\SetupDevOpsCommand;
use FilDonadoni\SpineWireLaravel\PubSub\GoogleCloudPubSubServiceProvider;
use FilDonadoni\SpineWireLaravel\Storage\GoogleCloudStorageServiceProvider;
use Illuminate\Support\ServiceProvider;

class DevOpsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/devops.php',
            'devops'
        );

        // Register Google Cloud service providers
        $this->app->register(GoogleCloudStorageServiceProvider::class);
        $this->app->register(GoogleCloudPubSubServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Register Artisan commands
            $this->commands([
                SetupDevOpsCommand::class,
            ]);

            // Publish config file
            $this->publishes([
                __DIR__.'/../config/devops.php' => config_path('devops.php'),
            ], 'devops-config');
        }
    }
}
