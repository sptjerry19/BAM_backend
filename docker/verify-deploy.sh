#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

project="jbam_verify_${GITHUB_RUN_ID:-$$}"
compose=(docker compose -p "$project" -f docker-compose.verify.yml)
cleanup() {
  "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo '==> Build production backend image'
docker build -f docker/Dockerfile -t jbam-backend:verify .

echo '==> Build PHP 8.4 test image with dev dependencies'
"${compose[@]}" build verifier

echo '==> Check migrations on an isolated empty MySQL 8.4 database'
"${compose[@]}" run --rm verifier artisan migrate --force --no-interaction
"${compose[@]}" run --rm verifier artisan migrate:status --no-ansi
"${compose[@]}" run --rm verifier artisan migrate --pretend --force --no-interaction --no-ansi

echo '==> Run full PHPUnit suite, including Mailpit delivery'
"${compose[@]}" run --rm verifier artisan test --compact --no-ansi --fail-on-warning

echo '==> Pre-deploy verification passed'
