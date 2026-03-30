#!/usr/bin/env bash
set -euo pipefail

BASE="${1:-http://localhost:9000}"
TOKEN="${INTERNAL_API_TOKEN:-dev-internal-token}"

curl -fsS "$BASE/up" >/dev/null
curl -fsS "$BASE/api/v1/health" >/dev/null
matches_json="$(curl -fsS "$BASE/api/v1/matches")"
curl -fsS "$BASE/api/v1/news" >/dev/null

match_id="$(python3 -c "import json,sys; x=json.loads(sys.stdin.read()); arr=x.get('data') or []; print((arr[0] if arr else {}).get('id',''))" <<< "$matches_json")"
if [[ -z "$match_id" ]]; then
  echo "smoke api failed: no match id returned" >&2
  exit 1
fi

curl -fsS -X POST "$BASE/api/v1/internal/generate/match/$match_id" -H "X-Internal-Token: $TOKEN" >/dev/null

echo "smoke api ok"
