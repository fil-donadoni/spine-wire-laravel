<?php

namespace FilDonadoni\SpineWireLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Setup DevOps deployment files for GCP Cloud Run.
 *
 * This command generates Docker and CI/CD files needed for deploying
 * a Laravel application to Google Cloud Platform.
 *
 * For Terraform infrastructure management, use the companion app:
 * https://github.com/fil-donadoni/spine-core
 */
class SetupDevOpsCommand extends Command
{
    protected $signature = 'devops:setup
                            {--project-id= : GCP Project ID}
                            {--region=europe-west1 : GCP Region}
                            {--client-name= : Client name (defaults to project directory name)}
                            {--app-name= : Application name (e.g., backend, api, admin)}
                            {--force : Overwrite existing files}
                            {--ignore-extras : Skip prompts for Docker extras (Node.js, ImageMagick, Redis)}
                            {--npm-build-script= : NPM script for building frontend assets (e.g., build, prod)}';

    protected $description = 'Setup deployment files (Docker, CI/CD) for GCP Cloud Run';

    protected array $config = [];

    protected array $createdFiles = [];

    public function handle(): int
    {
        $this->info('Spine Wire - Setup');
        $this->newLine();

        // Step 1: Gather configuration
        if (!$this->gatherConfiguration()) {
            return self::FAILURE;
        }

        // Step 2: Confirm with user
        if (!$this->confirmConfiguration()) {
            $this->warn('Setup cancelled.');
            return self::FAILURE;
        }

        // Step 3: Copy stubs to project
        if (!$this->copyStubs()) {
            return self::FAILURE;
        }

        // Step 4: Replace placeholders in .stub files
        if (!$this->replacePlaceholders()) {
            return self::FAILURE;
        }

        // Step 5: Copy health check files to app/
        $this->copyHealthCheckFiles();

        // Step 6: Install health check route
        $this->installHealthRoute();

        // Step 7: Set executable permissions on script files
        $this->setScriptPermissions();

        // Step 8: Display next steps
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    protected function gatherConfiguration(): bool
    {
        $this->info('Configuration Setup');
        $this->line('Please provide the following information:');
        $this->newLine();

        // GCP Project ID (required) - check .env first
        $envProjectId = env('GOOGLE_CLOUD_PROJECT');

        if ($this->option('project-id')) {
            // Explicit option takes precedence
            $this->config['project_id'] = $this->option('project-id');
            $this->line("  <fg=green>✓</> GCP Project ID (required): <comment>{$this->config['project_id']}</comment>");
        } elseif ($envProjectId) {
            // Found in .env, use it automatically
            $this->config['project_id'] = $envProjectId;
            $this->line("  <fg=green>✓</> GCP Project ID (from .env): <comment>{$this->config['project_id']}</comment>");
        } else {
            // Ask the user
            $this->config['project_id'] = $this->ask('GCP Project ID (required)');
        }

        if (empty($this->config['project_id'])) {
            $this->error('GCP Project ID is required.');
            return false;
        }

        // Client Name (required - no default, must be explicit)
        $clientNameInput = $this->getConfigValue(
            'client-name',
            'Client name (e.g., mycompany, acme)',
            null,
            true
        );

        if (empty($clientNameInput)) {
            $this->error('Client name is required.');
            return false;
        }

        $this->config['client_name'] = $this->sanitizeClientName($clientNameInput);

        if ($this->config['client_name'] !== $clientNameInput) {
            $this->comment("Client name sanitized to: {$this->config['client_name']}");
        }

        // GCP Region
        $this->config['gcp_region'] = $this->getConfigValue(
            'region',
            'GCP Region',
            config('devops.defaults.gcp_region', 'europe-west1')
        );

        // App Name
        $appNameInput = $this->getConfigValue(
            'app-name',
            'Application name',
            config('devops.defaults.app_name', 'backend')
        );

        $this->config['app_name'] = Str::slug($appNameInput);

        if ($this->config['app_name'] !== $appNameInput) {
            $this->comment("App name sanitized to: {$this->config['app_name']}");
        }

        // Docker Configuration
        if ($this->option('ignore-extras')) {
            $this->config['enable_frontend'] = config('devops.defaults.enable_frontend', false);
            $this->config['enable_imagick'] = config('devops.defaults.enable_imagick', false);
            $this->config['disable_imagick'] = !$this->config['enable_imagick'];
            $this->config['enable_redis'] = config('devops.defaults.enable_redis', false);

            if ($this->config['enable_frontend']) {
                $this->config['node_version'] = config('devops.defaults.node_version', '22');
                $this->config['package_manager'] = config('devops.defaults.package_manager', 'pnpm');
                $this->config['use_npm'] = $this->config['package_manager'] === 'npm';
                $this->config['use_pnpm'] = $this->config['package_manager'] === 'pnpm';
                $this->config['npm_build_script'] = $this->option('npm-build-script') ?? config('devops.defaults.npm_build_script', 'build');
            } else {
                $this->config['disable_frontend'] = true;
            }

            $this->line('  <fg=green>✓</> Docker Configuration: <comment>Using defaults (--ignore-extras)</comment>');
        } else {
            $this->newLine();
            $this->info('Docker Configuration');
            $this->line('Configure optional features for your Docker image:');
            $this->newLine();

            $this->config['enable_frontend'] = $this->confirm(
                'Does your project need Node.js for frontend asset compilation? (Vite, Mix, etc.)',
                config('devops.defaults.enable_frontend', false)
            );

            if ($this->config['enable_frontend']) {
                $this->config['node_version'] = $this->choice(
                    'Node.js version',
                    ['18', '20', '22'],
                    config('devops.defaults.node_version', '22')
                );

                $this->config['package_manager'] = $this->choice(
                    'Package manager',
                    ['npm', 'pnpm'],
                    config('devops.defaults.package_manager', 'pnpm')
                );

                $this->config['use_npm'] = $this->config['package_manager'] === 'npm';
                $this->config['use_pnpm'] = $this->config['package_manager'] === 'pnpm';

                $this->config['npm_build_script'] = $this->getConfigValue(
                    'npm-build-script',
                    'NPM build script (check your package.json: build, prod, etc.)',
                    config('devops.defaults.npm_build_script', 'build')
                );
            } else {
                $this->config['disable_frontend'] = true;
            }

            $this->config['enable_imagick'] = $this->confirm(
                'Enable ImageMagick for image processing? (imagick extension)',
                config('devops.defaults.enable_imagick', false)
            );
            $this->config['disable_imagick'] = !$this->config['enable_imagick'];

            $this->config['enable_redis'] = $this->confirm(
                'Enable Redis PHP extension?',
                config('devops.defaults.enable_redis', false)
            );
        }

        return true;
    }

    protected function confirmConfiguration(): bool
    {
        if ($this->option('ignore-extras')) {
            return true;
        }

        $this->newLine();
        $this->info('Configuration Summary:');

        $this->table(
            ['Setting', 'Value'],
            [
                ['GCP Project ID', $this->config['project_id']],
                ['Client Name', $this->config['client_name']],
                ['GCP Region', $this->config['gcp_region']],
                ['App Name', $this->config['app_name']],
            ]
        );

        $this->newLine();

        $this->table(
            ['Docker Features', 'Enabled'],
            [
                ['Frontend Build Tools', $this->config['enable_frontend'] ? '✓' : '✗'],
                ['ImageMagick', $this->config['enable_imagick'] ? '✓' : '✗'],
                ['Redis Extension', $this->config['enable_redis'] ? '✓' : '✗'],
            ]
        );
        $this->newLine();

        return $this->confirm('Proceed with this configuration?', true);
    }

    protected function copyStubs(): bool
    {
        $this->info('Copying deployment files...');

        $stubsPath = config('devops.stubs_path');
        $force = $this->option('force');

        if (!File::exists($stubsPath)) {
            $this->error("Stubs directory not found: {$stubsPath}");
            return false;
        }

        $operations = [
            ['from' => 'docker', 'to' => 'docker', 'type' => 'directory'],
            ['from' => '.dockerignore', 'to' => '.dockerignore', 'type' => 'file'],
            ['from' => 'cicd/cloudbuild.yaml.stub', 'to' => 'cloudbuild.yaml', 'type' => 'file'],
        ];

        foreach ($operations as $operation) {
            $sourcePath = $stubsPath . '/' . $operation['from'];
            $targetPath = base_path($operation['to']);

            if (!File::exists($sourcePath)) {
                $this->warn("Source not found: {$sourcePath}");
                continue;
            }

            if (File::exists($targetPath) && !$force) {
                $overwrite = $this->confirm("Target already exists: {$operation['to']}. Overwrite?", false);
                if (!$overwrite) {
                    $this->line("Skipped: {$operation['to']}");
                    continue;
                }
            }

            if ($operation['type'] === 'directory') {
                if (File::exists($targetPath)) {
                    File::deleteDirectory($targetPath);
                }
                File::copyDirectory($sourcePath, $targetPath);
                $this->createdFiles[] = $operation['to'];
                $this->line("Created: {$operation['to']}/");
            } else {
                File::copy($sourcePath, $targetPath);
                $this->createdFiles[] = $operation['to'];
                $this->line("Created: {$operation['to']}");
            }
        }

        $this->newLine();
        return true;
    }

    protected function replacePlaceholders(): bool
    {
        $this->info('Replacing placeholders in template files...');

        $placeholders = [
            '{{PROJECT_ID}}' => $this->config['project_id'],
            '{{CLIENT_NAME}}' => $this->config['client_name'],
            '{{GCP_REGION}}' => $this->config['gcp_region'],
            '{{APP_NAME}}' => $this->config['app_name'],
            '{{NODE_VERSION}}' => $this->config['node_version'] ?? '22',
            '{{PACKAGE_MANAGER}}' => $this->config['package_manager'] ?? 'pnpm',
            '{{NPM_BUILD_SCRIPT}}' => $this->config['npm_build_script'] ?? 'build',
        ];

        // Process cloudbuild.yaml (was .stub)
        $cloudbuildPath = base_path('cloudbuild.yaml');
        if (File::exists($cloudbuildPath)) {
            $content = File::get($cloudbuildPath);
            $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);
            File::put($cloudbuildPath, $content);
            $this->line('Processed: cloudbuild.yaml');
        }

        // Process Dockerfile.stub with conditional blocks
        $dockerfileStub = base_path('docker/Dockerfile.stub');
        if (File::exists($dockerfileStub)) {
            $this->processDockerfileStub($dockerfileStub, $placeholders);
        }

        // Process Dockerfile.base.stub
        $dockerfileBaseStub = base_path('docker/Dockerfile.base.stub');
        if (File::exists($dockerfileBaseStub)) {
            $content = File::get($dockerfileBaseStub);
            $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);
            $targetPath = base_path('docker/Dockerfile.base');
            File::put($targetPath, $content);
            File::delete($dockerfileBaseStub);
            $this->line('Processed: docker/Dockerfile.base');
        }

        $this->newLine();
        return true;
    }

    protected function processDockerfileStub(string $stubPath, array $placeholders): void
    {
        $content = File::get($stubPath);

        // Process conditional blocks
        $content = $this->processConditionalBlocks($content);

        // Replace simple placeholders
        $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);

        // Write to Dockerfile
        $targetPath = base_path('docker/Dockerfile');
        File::put($targetPath, $content);
        File::delete($stubPath);

        $this->line('Processed: docker/Dockerfile');
    }

    /**
     * Process conditional blocks in template content.
     * Syntax: {{#IF:FEATURE_NAME}}...{{/IF:FEATURE_NAME}}
     */
    protected function processConditionalBlocks(string $content): string
    {
        $pattern = '/\{\{#IF:([A-Z_]+)\}\}(.*?)\{\{\/IF:\1\}\}/s';

        return preg_replace_callback($pattern, function ($matches) {
            $featureName = $matches[1];
            $blockContent = $matches[2];
            $configKey = strtolower($featureName);
            $isEnabled = !empty($this->config[$configKey]);

            if ($isEnabled) {
                return $this->processConditionalBlocks($blockContent);
            }

            return '';
        }, $content);
    }

    protected function sanitizeClientName(string $name): string
    {
        $sanitized = strtolower($name);
        $sanitized = str_replace(['_', ' '], '-', $sanitized);
        $sanitized = preg_replace('/[^a-z0-9-]/', '', $sanitized);
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        $sanitized = trim($sanitized, '-');

        return $sanitized;
    }

    protected function setScriptPermissions(): void
    {
        $this->info('Setting executable permissions on script files...');

        $searchPaths = [
            base_path('docker/entrypoints'),
        ];

        $scriptFiles = [];

        foreach ($searchPaths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            $files = File::allFiles($path);
            foreach ($files as $file) {
                if (Str::endsWith($file->getFilename(), '.sh')) {
                    $scriptFiles[] = $file->getPathname();
                }
            }
        }

        foreach ($scriptFiles as $scriptFile) {
            chmod($scriptFile, 0755);
            $relativePath = str_replace(base_path() . '/', '', $scriptFile);
            $this->line("Made executable: {$relativePath}");
        }

        if (empty($scriptFiles)) {
            $this->warn('No script files found to set permissions on.');
        }

        $this->newLine();
    }

    protected function copyHealthCheckFiles(): void
    {
        $this->info('Copying health check files to app/...');

        $stubsPath = config('devops.stubs_path');
        $force = $this->option('force');

        $files = [
            [
                'from' => 'app/Http/Controllers/HealthCheckController.php',
                'to' => 'app/Http/Controllers/HealthCheckController.php',
            ],
            [
                'from' => 'app/Services/HealthCheckService.php',
                'to' => 'app/Services/HealthCheckService.php',
            ],
        ];

        foreach ($files as $file) {
            $sourcePath = $stubsPath . '/' . $file['from'];
            $targetPath = base_path($file['to']);

            if (!File::exists($sourcePath)) {
                $this->warn("Source not found: {$sourcePath}");
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }

            if (File::exists($targetPath) && !$force) {
                $overwrite = $this->confirm("File already exists: {$file['to']}. Overwrite?", false);
                if (!$overwrite) {
                    $this->line("Skipped: {$file['to']}");
                    continue;
                }
            }

            File::copy($sourcePath, $targetPath);
            $this->createdFiles[] = $file['to'];
            $this->line("Created: {$file['to']}");
        }

        $this->newLine();
    }

    protected function installHealthRoute(): void
    {
        $this->info('Installing health check route...');

        $webRoutesPath = base_path('routes/web.php');

        if (!File::exists($webRoutesPath)) {
            $this->warn('routes/web.php not found, skipping health route installation.');
            return;
        }

        $content = File::get($webRoutesPath);

        if (Str::contains($content, "'/health'") || Str::contains($content, '"/health"')) {
            $this->line('Health route already exists in routes/web.php');
            return;
        }

        $healthRoute = <<<'PHP'

// Health check endpoint for Cloud Run startup/liveness probes
\Illuminate\Support\Facades\Route::get('/health', \App\Http\Controllers\HealthCheckController::class)->name('health');
PHP;

        File::append($webRoutesPath, $healthRoute);

        $this->line('Added /health route to routes/web.php');
        $this->createdFiles[] = 'routes/web.php (modified)';
    }

    protected function displayNextSteps(): void
    {
        $this->newLine();
        $this->info('Setup completed successfully!');
        $this->newLine();

        $this->line('Next steps:');
        $this->newLine();

        $steps = [
            '1. Review Docker configuration:',
            '   docker/Dockerfile',
            '   docker/Dockerfile.base',
            '',
            '2. Review Cloud Build configuration:',
            '   cloudbuild.yaml',
            '',
            '3. Health check endpoint:',
            '   Route added to routes/web.php',
            '   Test locally: curl http://localhost:8000/health',
            '',
            '4. For Terraform infrastructure (Cloud Run, databases, etc.):',
            '   Use Spine Core:',
            '   https://github.com/fil-donadoni/spine-core',
            '',
            '5. Local development setup:',
            '   # Authenticate with Application Default Credentials',
            '   gcloud auth application-default login',
            '',
            '6. Build and test Docker image locally:',
            '   docker build -f docker/Dockerfile -t myapp:local .',
        ];

        foreach ($steps as $step) {
            $this->line($step);
        }

        $this->newLine();

        if (!empty($this->createdFiles)) {
            $this->info('Files and directories created:');
            foreach ($this->createdFiles as $file) {
                $this->line("  - {$file}");
            }
            $this->newLine();
        }

        $this->info('Happy deploying!');
    }

    protected function getConfigValue(string $optionName, string $prompt, $default = null, bool $required = false)
    {
        $optionValue = $this->option($optionName);

        if ($optionValue !== null) {
            $this->line("  <fg=green>✓</> {$prompt}: <comment>{$optionValue}</comment>");
            return $optionValue;
        }

        if ($required && $default === null) {
            return $this->ask($prompt);
        }

        return $this->ask($prompt, $default);
    }
}
