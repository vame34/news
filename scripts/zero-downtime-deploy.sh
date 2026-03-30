#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-ops/docker-compose.runtime.yml}"
UPSTREAM_FILE="${UPSTREAM_FILE:-ops/runtime/backend-upstream.conf}"
LOCK_FILE="${DEPLOY_LOCK_FILE:-/tmp/sport-radar-deploy.lock}"
PRE_SWITCH_HEALTH_PATH="${PRE_SWITCH_HEALTH_PATH:-/api/v1/health}"
POST_SWITCH_HEALTH_URL="${POST_SWITCH_HEALTH_URL:-http://127.0.0.1/api/v1/health}"
HEALTH_RETRIES="${HEALTH_RETRIES:-20}"
HEALTH_SLEEP_SEC="${HEALTH_SLEEP_SEC:-2}"
NGINX_CERT_PATH="${NGINX_CERT_PATH:-/etc/letsencrypt/live/radararena.ru/fullchain.pem}"
UPSTREAM_TEMPLATE_FILE="${UPSTREAM_TEMPLATE_FILE:-ops/nginx/backend-upstream.conf}"
DEPLOY_PRUNE_IMAGES="${DEPLOY_PRUNE_IMAGES:-1}"
DEPLOY_PRUNE_BUILD_CACHE="${DEPLOY_PRUNE_BUILD_CACHE:-1}"

log() {
  printf '[deploy] %s\n' "$*"
}

active_slot() {
  if grep -q "app_green:9000" "$UPSTREAM_FILE"; then
    echo "green"
    return
  fi
  echo "blue"
}

slot_port() {
  if [[ "$1" == "green" ]]; then
    echo "8083"
    return
  fi
  echo "8082"
}

frontend_slot_port() {
  if [[ "$1" == "green" ]]; then
    echo "3003"
    return
  fi
  echo "3002"
}

write_upstream() {
  local slot="$1"
  cat > "$UPSTREAM_FILE" <<EOF
set \$backend_upstream http://app_${slot}:9000;
set \$frontend_upstream http://frontend_${slot}:3000;
EOF
}

wait_target_health() {
  local slot="$1"
  local port
  port="$(slot_port "$slot")"
  local url="http://127.0.0.1:${port}${PRE_SWITCH_HEALTH_PATH}"
  local i
  for ((i=1; i<=HEALTH_RETRIES; i++)); do
    if curl -fsS "$url" >/dev/null; then
      log "target slot ${slot} is healthy (${url})"
      return 0
    fi
    sleep "$HEALTH_SLEEP_SEC"
  done
  log "target slot ${slot} failed health check (${url})"
  return 1
}

wait_target_frontend_health() {
  local slot="$1"
  local port
  port="$(frontend_slot_port "$slot")"
  local url="http://127.0.0.1:${port}/"
  local i
  for ((i=1; i<=HEALTH_RETRIES; i++)); do
    if curl -fsS "$url" >/dev/null; then
      log "target frontend slot ${slot} is healthy (${url})"
      return 0
    fi
    sleep "$HEALTH_SLEEP_SEC"
  done
  log "target frontend slot ${slot} failed health check (${url})"
  return 1
}

wait_public_health() {
  local i
  for ((i=1; i<=HEALTH_RETRIES; i++)); do
    if curl -fsS "$POST_SWITCH_HEALTH_URL" >/dev/null; then
      log "public health is green (${POST_SWITCH_HEALTH_URL})"
      return 0
    fi
    sleep "$HEALTH_SLEEP_SEC"
  done
  log "public health check failed (${POST_SWITCH_HEALTH_URL})"
  return 1
}

ensure_nginx_cert_mount() {
  if docker compose -f "$COMPOSE_FILE" exec -T nginx test -f "$NGINX_CERT_PATH"; then
    return 0
  fi

  log "nginx does not see cert at ${NGINX_CERT_PATH}; recreating nginx container"
  docker compose -f "$COMPOSE_FILE" up -d --force-recreate --no-deps nginx
  sleep 1

  if docker compose -f "$COMPOSE_FILE" exec -T nginx test -f "$NGINX_CERT_PATH"; then
    log "nginx cert mount restored"
    return 0
  fi

  log "nginx cert still missing after recreate (${NGINX_CERT_PATH})"
  return 1
}

recreate_nginx() {
  log "recreating nginx container to refresh mounted upstream file"
  docker compose -f "$COMPOSE_FILE" up -d --force-recreate --no-deps nginx
}

cleanup_docker_artifacts() {
  if [[ "$DEPLOY_PRUNE_IMAGES" == "1" ]]; then
    log "pruning unused docker images"
    docker image prune -af >/dev/null || log "docker image prune failed; skipping"
  fi

  if [[ "$DEPLOY_PRUNE_BUILD_CACHE" == "1" ]]; then
    log "pruning docker build cache"
    docker builder prune -af >/dev/null || log "docker builder prune failed; skipping"
  fi
}

{
  flock -n 9 || { log "deploy lock is busy"; exit 1; }

  mkdir -p "$(dirname "$UPSTREAM_FILE")"
  if [[ ! -f "$UPSTREAM_FILE" ]]; then
    if [[ -f "$UPSTREAM_TEMPLATE_FILE" ]]; then
      cp "$UPSTREAM_TEMPLATE_FILE" "$UPSTREAM_FILE"
    else
      write_upstream "blue"
    fi
  fi

  current="$(active_slot)"
  if [[ "$current" == "blue" ]]; then
    target="green"
  else
    target="blue"
  fi

  log "active slot: ${current}, target slot: ${target}"

  docker compose -f "$COMPOSE_FILE" up -d postgres redis
  recreate_nginx
  docker compose -f "$COMPOSE_FILE" up -d --build --no-deps app_"$target" frontend_"$target"
  docker compose -f "$COMPOSE_FILE" up -d --no-deps scheduler horizon
  docker compose -f "$COMPOSE_FILE" exec -T app_"$target" php artisan migrate --force

  wait_target_health "$target"
  wait_target_frontend_health "$target"
  ensure_nginx_cert_mount

  write_upstream "$target"
  recreate_nginx

  if ! wait_public_health; then
    log "rollback: switching traffic back to ${current}"
    write_upstream "$current"
    recreate_nginx
    exit 1
  fi

  docker compose -f "$COMPOSE_FILE" stop app_"$current" || true
  docker compose -f "$COMPOSE_FILE" stop frontend_"$current" || true
  cleanup_docker_artifacts
  log "deploy complete; traffic moved to ${target}"
} 9>"$LOCK_FILE"
