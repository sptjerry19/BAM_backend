#!/usr/bin/env bash
# Copy Laravel public-disk files (storage/app/public) into the S3 bucket
# so existing /s3/{bucket}/<key> URLs and new uploads share one path.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f .env ]]; then
  echo "Missing $ROOT/.env" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1091
source .env
set +a

: "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID is empty}"
: "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY is empty}"
: "${AWS_BUCKET:?AWS_BUCKET is empty}"

PROJECT="${COMPOSE_PROJECT_NAME:-bam_backend}"

docker compose -p "$PROJECT" -f "$ROOT/docker-compose.yml" up -d minio

echo "Waiting for MinIO..."
ready=0
for _ in $(seq 1 40); do
  if docker run --rm --network "${JB_NET:-jb_net}" curlimages/curl:latest \
    -fsS "http://minio:9000/minio/health/live" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
if [[ "$ready" -ne 1 ]]; then
  echo "MinIO did not become ready" >&2
  docker compose -p "$PROJECT" -f "$ROOT/docker-compose.yml" logs --tail 40 minio >&2 || true
  exit 1
fi

docker compose -p "$PROJECT" -f "$ROOT/docker-compose.yml" exec -T backend \
  mkdir -p /var/www/html/scripts
docker compose -p "$PROJECT" -f "$ROOT/docker-compose.yml" cp \
  "$ROOT/scripts/sync-public-disk-to-s3.php" backend:/var/www/html/scripts/sync-public-disk-to-s3.php
docker compose -p "$PROJECT" -f "$ROOT/docker-compose.yml" exec -T backend \
  php /var/www/html/scripts/sync-public-disk-to-s3.php
