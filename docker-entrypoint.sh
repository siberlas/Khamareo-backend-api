#!/bin/sh
set -e

# Decode JWT keys from base64 env vars (if provided)
if [ -n "$JWT_SECRET_KEY_BASE64" ]; then
    echo "$JWT_SECRET_KEY_BASE64" | base64 -d > config/jwt/private.pem
    echo "$JWT_PUBLIC_KEY_BASE64" | base64 -d > config/jwt/public.pem
    chmod 644 config/jwt/private.pem config/jwt/public.pem
fi

# Run database migrations
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>/dev/null || true

# Clear and warm up cache
php bin/console cache:clear --env=prod --no-debug 2>/dev/null || true

exec "$@"
