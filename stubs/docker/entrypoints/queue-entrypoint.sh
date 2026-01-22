#!/bin/sh

# Queue worker entrypoint for Cloud Run Jobs
# Executes Laravel queue worker with proper shutdown handling

cd /var/www

# Start queue worker with timeout
# The --max-time flag ensures worker stops before Cloud Run job timeout
exec php artisan queue:work --stop-when-empty --max-time=240 --tries=1
