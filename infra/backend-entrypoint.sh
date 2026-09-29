#!/bin/sh
set -eu
mkdir -p /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/storage/app/private /app/bootstrap/cache
chown -R www-data:www-data /app/storage /app/bootstrap/cache
exec "$@"
