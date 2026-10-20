#!/bin/sh
set -eu
cd /home/site/wwwroot
# Runtime credentials stay on the App Service. This additive migration is idempotent.
php artisan migrate --force --path=database/migrations/2026_10_20_000001_create_provisional_account_claims.php
php artisan route:clear
cp default /etc/nginx/sites-available/default
nginx -t
service nginx reload
