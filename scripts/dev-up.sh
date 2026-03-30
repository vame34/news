#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

cp -n .env.example .env || true
cp -n backend/.env.example backend/.env || true
cp -n frontend/.env.example frontend/.env.local || true

docker compose -f ops/docker-compose.yml up -d --build

echo "[dev-up] waiting backend..."
sleep 3

docker compose -f ops/docker-compose.yml exec -T backend php artisan sport-radar:db-bootstrap

echo "[dev-up] backend + db ready"
