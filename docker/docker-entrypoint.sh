#!/bin/sh
set -e

cd /var/www/html

echo "[entrypoint] waiting for database..."
i=0
until php -r "try { new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD') ?: null); exit(0);} catch (Throwable \$e) { exit(1);}"; do
  i=$((i + 1))
  if [ "$i" -ge 90 ]; then
    echo "[entrypoint] database not ready after 90s" >&2
    exit 1
  fi
  sleep 1
done

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

php artisan config:clear || true
php artisan storage:link 2>/dev/null || true
php artisan migrate --force --no-interaction

if [ "${RUN_SCOUT_IMPORT:-false}" = "true" ]; then
  echo "[entrypoint] waiting for Elasticsearch..."
  j=0
  until php -r "\$h=getenv('ELASTICSEARCH_HOST')?:'http://elasticsearch:9200'; try { \$c=@file_get_contents(\$h); exit(\$c===false?1:0);} catch (Throwable \$e) { exit(1);}"; do
    j=$((j + 1))
    if [ "$j" -ge 60 ]; then
      echo "[entrypoint] Elasticsearch not ready — skip scout import" >&2
      break
    fi
    sleep 2
  done
  if [ "$j" -lt 60 ]; then
    echo "[entrypoint] importing Scout index..."
    php artisan scout:import "App\\Models\\Product" || true
  fi
fi

echo "[entrypoint] starting php-fpm"
exec "$@"
