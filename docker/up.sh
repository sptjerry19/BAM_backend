#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example - set APP_KEY / JWT_SECRET / S3 as needed."
fi
docker compose --env-file .env up -d --build "$@"
docker compose --env-file .env ps
