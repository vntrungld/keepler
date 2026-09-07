#!/bin/sh
# Replaces the image's own Laravel automations, which `AUTORUN_ENABLED=false`
# turns off in render.yaml.
#
# The stock script starts four `php artisan` processes at every container boot:
# storage:link, config:clear, a database connection probe, then migrate, then
# optimize. Booting the framework costs about 2.5s per process on the free
# instance, and the container is rebuilt from the image every time the service
# wakes from sleep, so a user waits through all of it. Dropped here:
#
#   - storage:link, because nothing in the app writes to the public disk
#   - config:clear, because the image ships no cached config to clear
#   - the connection probe, because migrate reports the same failure itself
#
# The probe also waited for a database that was still starting, so migrate
# retries here instead.
set -e

cd /var/www/html

attempt=1
until php artisan migrate --force; do
    if [ "$attempt" -ge 3 ]; then
        echo "❌ 51-app-boot: migrate failed after $attempt attempts."
        exit 1
    fi

    echo "⏳ 51-app-boot: migrate failed, retrying in 2s..."
    attempt=$((attempt + 1))
    sleep 2
done

php artisan optimize
