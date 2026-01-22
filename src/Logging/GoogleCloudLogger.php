<?php

namespace FilDonadoni\SpineWireLaravel\Logging;

use Google\Cloud\Logging\LoggingClient;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Google Cloud Logging integration for Laravel
 *
 * Uses Application Default Credentials (ADC) for authentication.
 * Works seamlessly in both Cloud Run and local development with zero configuration.
 *
 * Authentication:
 * - Cloud Run: Automatically uses service account identity
 * - Local: Run `gcloud auth application-default login` once
 *
 * Prerequisites:
 * - Service account needs "roles/logging.logWriter" IAM role
 *
 * Usage in config/logging.php:
 * ```php
 * 'google_cloud' => [
 *     'driver' => 'custom',
 *     'via' => \FilDonadoni\SpineWireLaravel\Logging\GoogleCloudLogger::class,
 *     'level' => env('LOG_LEVEL', 'debug'),
 * ],
 * ```
 */
class GoogleCloudLogger
{
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('google_cloud');

        // Convert level from string to Level enum if needed
        $level = $this->normalizeLevel($config['level'] ?? Level::Debug);

        // Always add stderr handler as fallback
        // This ensures logs are visible even if GCP logging fails
        $stderrHandler = new \Monolog\Handler\StreamHandler(
            'php://stderr',
            $level
        );
        $logger->pushHandler($stderrHandler);

        try {
            // Get project ID from environment
            $projectId = getenv('GOOGLE_CLOUD_PROJECT');
            // Use getenv instead of config() to avoid bootstrap issues
            $logName = getenv('APP_NAME') ?: 'laravel-app';
            // Normalize log name: remove spaces and special chars for GCP compatibility
            $logName = preg_replace('/[^a-zA-Z0-9_\-.]/', '-', $logName);

            if ($projectId) {
                // Use Application Default Credentials (ADC)
                // - Cloud Run: uses service account identity
                // - Local: uses `gcloud auth application-default login`
                $gcpHandler = new GoogleCloudLoggingHandler(
                    projectId: $projectId,
                    logName: $logName,
                    level: $level
                );
                $logger->pushHandler($gcpHandler);
                error_log("GoogleCloud Logging: Initialized for project={$projectId}, logName={$logName}");
            } else {
                error_log("GoogleCloud Logging: Using stderr fallback - GOOGLE_CLOUD_PROJECT not set");
            }
        } catch (\Exception $e) {
            error_log("GoogleCloud Logging Error: " . $e->getMessage() . " - Using stderr only");
        }

        return $logger;
    }

    /**
     * Convert log level from string/int to Monolog Level enum
     */
    private function normalizeLevel(string|int|Level $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        if (is_int($level)) {
            return Level::from($level);
        }

        // Convert string to Level enum
        return Level::fromName(ucfirst(strtolower($level)));
    }
}

/**
 * Monolog handler for Google Cloud Logging
 *
 * Uses Application Default Credentials (ADC) for authentication.
 */
class GoogleCloudLoggingHandler extends AbstractProcessingHandler
{
    private LoggingClient $loggingClient;
    private $logger;

    /**
     * @param string $projectId GCP Project ID
     * @param string $logName Name of the log in Cloud Logging
     * @param int|Level $level Minimum logging level
     */
    public function __construct(
        string $projectId,
        string $logName = 'laravel-app',
        int|Level $level = Level::Debug
    ) {
        parent::__construct($level);

        // Use Application Default Credentials (ADC)
        // No credentials specified = automatic authentication
        $this->loggingClient = new LoggingClient([
            'projectId' => $projectId,
        ]);
        $this->logger = $this->loggingClient->logger($logName);

        // Test write to verify logger works
        try {
            $testEntry = $this->logger->entry('GoogleCloudLogger initialized - test message');
            $this->logger->write($testEntry);
            error_log("GoogleCloudLogger: Test message written successfully to {$logName}");
        } catch (\Exception $e) {
            error_log("GoogleCloudLogger: Test write failed - " . $e->getMessage());
        }
    }

    /**
     * Write a log record to Google Cloud Logging
     */
    protected function write(LogRecord $record): void
    {
        try {
            // Map Monolog levels to Cloud Logging severity levels
            $severityMap = [
                Level::Debug->value => 'DEBUG',
                Level::Info->value => 'INFO',
                Level::Notice->value => 'NOTICE',
                Level::Warning->value => 'WARNING',
                Level::Error->value => 'ERROR',
                Level::Critical->value => 'CRITICAL',
                Level::Alert->value => 'ALERT',
                Level::Emergency->value => 'EMERGENCY',
            ];

            $levelValue = $record->level instanceof Level ? $record->level->value : $record->level;

            // Create structured log entry
            $entry = $this->logger->entry([
                'message' => $record->message,
                'context' => $record->context ?? [],
                'extra' => $record->extra ?? [],
            ], [
                'severity' => $severityMap[$levelValue] ?? 'INFO',
                'timestamp' => $record->datetime,
            ]);

            // Write to Cloud Logging
            $this->logger->write($entry);
        } catch (\Exception $e) {
            // If GCP logging fails, write to stderr without causing a logging loop
            error_log("GCP Logging failed: " . $e->getMessage() . " - Message: " . $record->message);
        }
    }
}
