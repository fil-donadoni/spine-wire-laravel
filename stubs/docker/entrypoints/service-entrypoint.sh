#!/bin/sh

# Script di startup che sostituisce i placeholder con le variabili d'ambiente
# I secrets vengono montati automaticamente da Cloud Run come env vars

# Esegui le migrazioni per tutti gli ambienti (eccetto local)
if [ "$APP_ENV" != "local" ]; then
    echo "Running migrations for environment: $APP_ENV"
    cd /var/www && php artisan migrate --force
fi

# Install Octane configuration for FrankenPHP if not present (idempotent)
cd /var/www && php artisan octane:install --server=frankenphp --force || true

# Use PORT environment variable provided by Cloud Run (defaults to 8080 for local development)
export PORT=${PORT:-8080}

# Set Octane environment variables for FrankenPHP
export OCTANE_SERVER=frankenphp
export OCTANE_WORKERS=4
export OCTANE_MAX_REQUESTS=500
export OCTANE_TASK_WORKERS=2
export OCTANE_WATCH=false

# Start Laravel Octane with FrankenPHP server
cd /var/www && exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=${PORT} --workers=4
