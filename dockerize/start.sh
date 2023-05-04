#!/usr/bin/env bash

set -e

role=${CONTAINER_ROLE:-app}
env=${APP_ENV:-production}
app_root=${APP_ROOT:-/var/www/html}
queue=${QUEUE:-default}

cd $app_root \
    && chown -R www-data:www-data $app_root \
    && chmod -R 777 storage \
    && php artisan migrate --force

touch public/.env.consul

if [ "$role" = "app" ]; then
    echo "App is running..."
    php artisan serve --host=0.0.0.0

elif [ "$role" = "queue" ]; then
    echo "Running the queue..."
    # php ${app_root}/artisan queue:work --verbose --tries=3 --timeout=90
    php ${app_root}/artisan rabbitmq:consume rabbitmq --queue=${queue} --verbose --tries=3 --timeout=90

elif [ "$role" = "scheduler" ]; then
    echo "Scheduler is running..."
    while true
    do
      php ${app_root}/artisan schedule:run --verbose --no-interaction &
      sleep 60
    done

else
    echo "Could not match the container role \"$role\""
    exit 1
fi
