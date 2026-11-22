#!/bin/sh
# Start-up steps for the ServerAvatar MCP image (picked up by the base image's entrypoint).
# The scheduler container sets SKIP_BOOTSTRAP=1 so only the web container migrates.
set -eu

cd /var/www/html

if [ -n "${SKIP_BOOTSTRAP:-}" ]; then
    exit 0
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is empty. Generate one with: docker run --rm <image> php artisan key:generate --show" >&2
    echo "Keep it safe: without it, every saved ServerAvatar token becomes unreadable." >&2
    exit 1
fi

# OAuth signing keys: from the environment if given, otherwise created once and kept in the storage volume.
if [ -z "${PASSPORT_PRIVATE_KEY:-}" ] && [ ! -f storage/oauth-private.key ]; then
    echo "Creating the OAuth signing keys in storage/ (back this volume up)."
    php artisan passport:keys --no-interaction
fi

php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
