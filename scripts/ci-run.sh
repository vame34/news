#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="${CI_LOG_DIR:-$ROOT_DIR/.logs/ci}"
COMPOSE_FILE="${CI_COMPOSE_FILE:-ops/docker-compose.yml}"
CI_PROJECT_NAME="${CI_PROJECT_NAME:-sport-radar-ci}"
CI_BOOTSTRAP_DB="${CI_BOOTSTRAP_DB:-1}"
APP_PORT="${APP_PORT:-28080}"
BACKEND_PORT="${BACKEND_PORT:-29000}"
FRONTEND_PORT="${FRONTEND_PORT:-23000}"
DB_PORT="${DB_PORT:-5432}"
REDIS_PORT="${REDIS_PORT:-16379}"
API_BASE="${API_BASE:-http://localhost:${BACKEND_PORT}}"
FRONTEND_BASE="${FRONTEND_BASE:-http://localhost:${FRONTEND_PORT}}"
mkdir -p "$LOG_DIR"

STAMP="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="$LOG_DIR/ci-$STAMP.log"

exec > >(tee -a "$LOG_FILE") 2>&1

echo "[ci] start $STAMP"
cd "$ROOT_DIR"

APP_PORT="$APP_PORT" \
BACKEND_PORT="$BACKEND_PORT" \
FRONTEND_PORT="$FRONTEND_PORT" \
DB_PORT="$DB_PORT" \
REDIS_PORT="$REDIS_PORT" \
docker compose -p "$CI_PROJECT_NAME" -f "$COMPOSE_FILE" up -d --build

wait_http() {
  local url="$1"
  local retries="${2:-40}"
  local sleep_sec="${3:-2}"
  local i
  for ((i=1; i<=retries; i++)); do
    if curl -fsS "$url" >/dev/null; then
      return 0
    fi
    sleep "$sleep_sec"
  done
  return 1
}

wait_http "$API_BASE/up"
wait_http "$FRONTEND_BASE/"

if [[ "$CI_BOOTSTRAP_DB" == "1" ]]; then
  APP_PORT="$APP_PORT" \
  BACKEND_PORT="$BACKEND_PORT" \
  FRONTEND_PORT="$FRONTEND_PORT" \
  DB_PORT="$DB_PORT" \
  REDIS_PORT="$REDIS_PORT" \
  COMPOSE_PROJECT_NAME="$CI_PROJECT_NAME" \
  COMPOSE_FILE="$COMPOSE_FILE" \
  make db-bootstrap
fi

wait_http "$API_BASE/up"
wait_http "$FRONTEND_BASE/"

APP_PORT="$APP_PORT" \
BACKEND_PORT="$BACKEND_PORT" \
FRONTEND_PORT="$FRONTEND_PORT" \
DB_PORT="$DB_PORT" \
REDIS_PORT="$REDIS_PORT" \
COMPOSE_PROJECT_NAME="$CI_PROJECT_NAME" \
COMPOSE_FILE="$COMPOSE_FILE" \
API_BASE="$API_BASE" \
FRONTEND_BASE="$FRONTEND_BASE" \
make ci
echo "[ci] ok"
