#!/bin/sh
set -eu

PORT="${PORT:-80}"

# Render assigns a port at runtime; point Apache at it.
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Aiven requires TLS. Resolve a usable CA certificate file:
#   1. Honor MYSQL_ATTR_SSL_CA if it already points at a valid PEM file.
#   2. Otherwise decode AIVEN_CA_CERT_BASE64 into /tmp/aiven-ca.pem (converting
#      DER to PEM if needed).
#   3. Otherwise fall back to the CA certificate bundled with the image.
# Never leave MYSQL_ATTR_SSL_CA pointing at a missing/empty file, which would
# make PDO fail with "no valid certs found cafile stream".
# Helper: return success if the given file looks like a PEM certificate.
is_pem_cert() {
    [ -s "$1" ] && grep -q "BEGIN CERTIFICATE" "$1" 2>/dev/null
}

CA_FILE=""
# 1. Honor an explicit MYSQL_ATTR_SSL_CA if it points at a valid PEM file.
if [ -n "${MYSQL_ATTR_SSL_CA:-}" ] && is_pem_cert "${MYSQL_ATTR_SSL_CA}"; then
    CA_FILE="${MYSQL_ATTR_SSL_CA}"
fi
# 2. Decode AIVEN_CA_CERT_BASE64; convert DER to PEM if needed.
if [ -z "${CA_FILE}" ] && [ -n "${AIVEN_CA_CERT_BASE64:-}" ]; then
    if printf '%s' "${AIVEN_CA_CERT_BASE64}" | base64 -d > /tmp/aiven-ca.pem 2>/dev/null; then
        if ! is_pem_cert /tmp/aiven-ca.pem; then
            # The secret may be a base64-encoded DER certificate; convert it.
            if command -v openssl >/dev/null 2>&1; then
                openssl x509 -inform DER -in /tmp/aiven-ca.pem -out /tmp/aiven-ca-converted.pem 2>/dev/null && \
                    mv /tmp/aiven-ca-converted.pem /tmp/aiven-ca.pem || true
            fi
        fi
        if is_pem_cert /tmp/aiven-ca.pem; then
            CA_FILE=/tmp/aiven-ca.pem
        fi
    fi
fi
# 3. Fall back to the CA certificate bundled with the image.
if [ -z "${CA_FILE}" ]; then
    if is_pem_cert /var/www/html/ca.pem; then
        CA_FILE=/var/www/html/ca.pem
    fi
fi
if [ -n "${CA_FILE}" ] && is_pem_cert "${CA_FILE}"; then
    export MYSQL_ATTR_SSL_CA="${CA_FILE}"
else
    unset MYSQL_ATTR_SSL_CA || true
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
        echo "FATAL: database migrations failed after $max_attempts attempts."
        echo "Ensure the Aiven import is complete and the service is powered on."
        exit 1
    fi
    echo "Migration attempt $attempt failed; retrying in 5s..."
    attempt=$((attempt + 1))
    sleep 5
done

php artisan storage:link || true

exec apache2-foreground