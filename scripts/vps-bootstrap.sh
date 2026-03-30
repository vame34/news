#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "run as root" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ripgrep curl ca-certificates git

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

if [[ ! -f ops/.env ]]; then
  cp .env ops/.env
fi

if [[ ! -f backend/.env ]]; then
  cp backend/.env.example backend/.env
fi

if [[ ! -f frontend/.env.local ]]; then
  cp frontend/.env.example frontend/.env.local
fi

git fetch origin
git checkout master
git pull --ff-only origin master

APP_PORT="${APP_PORT:-8080}" \
BACKEND_PORT="${BACKEND_PORT:-9000}" \
FRONTEND_PORT="${FRONTEND_PORT:-3000}" \
DB_PORT="${DB_PORT:-5432}" \
REDIS_PORT="${REDIS_PORT:-6379}" \
docker compose -f ops/docker-compose.yml up -d --build

APP_PORT="${APP_PORT:-8080}" \
BACKEND_PORT="${BACKEND_PORT:-9000}" \
FRONTEND_PORT="${FRONTEND_PORT:-3000}" \
DB_PORT="${DB_PORT:-5432}" \
REDIS_PORT="${REDIS_PORT:-6379}" \
COMPOSE_FILE=ops/docker-compose.yml \
make db-bootstrap

curl -fsS "http://127.0.0.1:${BACKEND_PORT:-9000}/up" >/dev/null
curl -fsS "http://127.0.0.1:${FRONTEND_PORT:-3000}/legal/disclaimer" >/dev/null

echo "vps bootstrap ok"
