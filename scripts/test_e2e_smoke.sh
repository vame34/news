#!/usr/bin/env bash
set -euo pipefail

BASE_FRONT="${FRONTEND_BASE:-http://localhost:3000}"
BASE_API="${API_BASE:-http://localhost:9000}"
TOKEN="${INTERNAL_API_TOKEN:-dev-internal-token}"
REINDEX_POLL_RETRIES="${REINDEX_POLL_RETRIES:-20}"
REINDEX_POLL_SLEEP="${REINDEX_POLL_SLEEP:-2}"
COMPOSE_FILE="${COMPOSE_FILE:-ops/docker-compose.yml}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-}"
HTTP_RETRIES="${HTTP_RETRIES:-10}"
HTTP_SLEEP_SEC="${HTTP_SLEEP_SEC:-2}"

fetch_body() {
  local url="$1"
  local retries="${2:-$HTTP_RETRIES}"
  local sleep_sec="${3:-$HTTP_SLEEP_SEC}"
  local body=""
  local i

  for ((i=1; i<=retries; i++)); do
    if body="$(curl -fsS "$url")"; then
      printf '%s' "$body"
      return 0
    fi
    sleep "$sleep_sec"
  done

  return 1
}

compose_exec() {
  if [[ -n "$COMPOSE_PROJECT_NAME" ]]; then
    docker compose -p "$COMPOSE_PROJECT_NAME" -f "$COMPOSE_FILE" exec -T backend "$@"
    return
  fi
  docker compose -f "$COMPOSE_FILE" exec -T backend "$@"
}

curl -fsS "$BASE_API/up" >/dev/null
matches_json="$(curl -fsS "$BASE_API/api/v1/matches")"
news_json="$(curl -fsS "$BASE_API/api/v1/news")"

match_slug="$(python3 -c "import json,sys; x=json.loads(sys.stdin.read()); arr=x.get('data') or []; print((arr[0] if arr else {}).get('slug',''))" <<< "$matches_json")"
news_slug="$(python3 -c "import json,sys; x=json.loads(sys.stdin.read()); arr=x.get('data') or []; print((arr[0] if arr else {}).get('slug',''))" <<< "$news_json")"

if [[ -z "$match_slug" || -z "$news_slug" ]]; then
  echo "e2e smoke failed: empty match/news dataset" >&2
  exit 1
fi

reindex_resp="$(curl -fsS -X POST "$BASE_API/api/v1/internal/reindex/1" -H "X-Internal-Token: $TOKEN")"
job_id="$(python3 -c "import json,sys; x=json.loads(sys.stdin.read()); print(x.get('job_id',''))" <<< "$reindex_resp")"
if [[ -z "$job_id" ]]; then
  echo "e2e smoke failed: reindex job id not returned" >&2
  exit 1
fi

compose_exec php artisan sport-radar:process-reindex >/dev/null

for ((i=1; i<=REINDEX_POLL_RETRIES; i++)); do
  status_json="$(curl -fsS "$BASE_API/api/v1/internal/reindex/jobs/$job_id" -H "X-Internal-Token: $TOKEN")"
  status="$(python3 -c "import json,sys; x=json.loads(sys.stdin.read()); print((x.get('data') or {}).get('status',''))" <<< "$status_json")"
  if [[ "$status" == "done" ]]; then
    break
  fi
  if [[ "$status" == "failed" ]]; then
    echo "e2e smoke failed: reindex job $job_id failed" >&2
    exit 1
  fi
  compose_exec php artisan sport-radar:process-reindex >/dev/null
  sleep "$REINDEX_POLL_SLEEP"
done

if [[ "${status:-}" != "done" ]]; then
  echo "e2e smoke failed: reindex job $job_id did not reach done status" >&2
  exit 1
fi

fetch_body "$BASE_FRONT/" >/dev/null
fetch_body "$BASE_FRONT/matches" >/dev/null
fetch_body "$BASE_FRONT/news" >/dev/null
fetch_body "$BASE_FRONT/match/$match_slug" >/dev/null
fetch_body "$BASE_FRONT/news/$news_slug" >/dev/null
sitemap="$(fetch_body "$BASE_FRONT/sitemap.xml")"
[[ "$sitemap" == *"<urlset"* ]]
robots="$(fetch_body "$BASE_FRONT/robots.txt")"
[[ "$robots" == *"Sitemap:"* ]]

echo "e2e smoke checks ok"
