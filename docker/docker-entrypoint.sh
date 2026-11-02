#!/bin/sh
set -e

cd /var/www/html

echo "[entrypoint] waiting for database (user=$(printenv DB_USERNAME) host=$(printenv DB_HOST) db=$(printenv DB_DATABASE))..."
i=0
until php -r "try { new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD') ?: ''); exit(0);} catch (Throwable \$e) { fwrite(STDERR, \$e->getMessage() . PHP_EOL); exit(1);}"; do
  i=$((i + 1))
  if [ "$i" -ge 90 ]; then
    echo "[entrypoint] database not ready after 90s — check DB_USERNAME/DB_PASSWORD in .env (Docker needs non-empty password; avoid root with blank password)" >&2
    exit 1
  fi
  sleep 1
done

# Auxiliary long-running processes must not mutate caches, run migrations,
# or chown shared storage concurrently with the API startup.
if [ "${SOCIAL_PROCESS:-false}" = "true" ]; then
  exec "$@"
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
# `docker compose exec` runs artisan as root and can leave root-owned log files behind.
find storage/logs -type f -name '*.log' -exec chmod 666 {} + 2>/dev/null || true

php artisan config:clear || true
php artisan storage:link 2>/dev/null || true

# CI/deploy runs migrate after php-fpm is up. Skipping here avoids:
# - two concurrent migrates, and
# - a long entrypoint block that makes "wait for php-fpm" hit SSH timeouts.
if [ "${SKIP_ENTRYPOINT_MIGRATE:-false}" = "true" ]; then
  echo "[entrypoint] SKIP_ENTRYPOINT_MIGRATE=true — migrate deferred"
else
  php artisan migrate --force --no-interaction
fi

# Never scout:import during deploy boot (SKIP_ENTRYPOINT_MIGRATE=true).
# On ~1GB EC2, ES import + php-fpm + migrate OOM-kills artisan (exit 137).
if [ "${RUN_SCOUT_IMPORT:-false}" = "true" ] && [ "${SKIP_ENTRYPOINT_MIGRATE:-false}" != "true" ]; then
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
elif [ "${RUN_SCOUT_IMPORT:-false}" = "true" ]; then
  echo "[entrypoint] RUN_SCOUT_IMPORT ignored during deploy boot — run scout:import manually after deploy"
fi

echo "[entrypoint] starting php-fpm"
exec "$@"
