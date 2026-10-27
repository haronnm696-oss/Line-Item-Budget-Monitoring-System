#!/bin/sh
set -eu

PORT="${PORT:-80}"

# Render assigns a port at runtime; point Apache at it.
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Aiven requires TLS. The CA certificate is supplied as a base64 secret.
if [ -n "${AIVEN_CA_CERT_BASE64:-}" ]; then
    printf '%s' "${AIVEN_CA_CERT_BASE64}" | base64 -d > /tmp/aiven-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/aiven-ca.pem
fi

mkdir -p \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache/data \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R ug+rwx storage bootstrap/cache || true

php artisan package:discover --ansi
php artisan config:cache

# Run migrations on boot. Aiven Free can idle out, so retry briefly; if the DB
# is still unreachable after the last attempt, fail loudly instead of crashing
# the container without explanation.
attempt=1
max_attempts=5
until php artisan migrate --force; do
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "FATAL: database migrations failed after $max_attempt attempts."
        echo "Ensure the Aiven import is complete and the service is powered on."
        exit 1
    fi
    echo "Migration attempt $attempt failed; retrying in 5s..."
    attempt=$((attempt + 1))
    sleep 5
done

php artisan storage:link || true

exec apache2-foreground
