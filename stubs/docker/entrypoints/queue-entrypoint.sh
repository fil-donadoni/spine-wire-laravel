#!/bin/sh

# Queue worker entrypoint for Cloud Run Jobs
# Executes Laravel queue worker with proper shutdown handling

cd /var/www

# Memory limit for queue worker (in MB)
# Derived from QUEUE_WORKER_MEMORY env var (set by Terraform as 80% of container memory)
MEMORY_LIMIT=${QUEUE_WORKER_MEMORY:-512}

# Start queue worker with timeout
# The --max-time flag ensures worker stops before Cloud Run job timeout
exec php artisan queue:work --stop-when-empty --max-time=240 --timeout=300 --tries=1 --memory=${MEMORY_LIMIT}
