#!/usr/bin/env bash
set -euo pipefail

BASE_FRONT="${FRONTEND_BASE:-http://localhost:3000}"
BASIC_RETRIES="${BASIC_RETRIES:-10}"
BASIC_SLEEP_SEC="${BASIC_SLEEP_SEC:-2}"

fetch_body() {
  local url="$1"
  local retries="${2:-$BASIC_RETRIES}"
  local sleep_sec="${3:-$BASIC_SLEEP_SEC}"
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

assert_page_contains() {
  local path="$1"
  local needle="$2"

  local body
  body="$(fetch_body "${BASE_FRONT}${path}")"
  printf '%s' "$body" | rg -q "$needle" || {
    echo "frontend content check failed: ${path} missing '${needle}'" >&2
    exit 1
  }

  if printf '%s' "$body" | rg -qi "(букмек|коэфф(ици|ициен)|фрибет|ставк(и|а) на (спорт|матч)|odds|betting|freebet)"; then
    echo "frontend policy violation on ${path}: forbidden betting terms found" >&2
    exit 1
  fi
}

assert_page_contains "/" "Радар"
assert_page_contains "/matches" "Прогнозы|Матчи"
assert_page_contains "/news" "Новости"
assert_page_contains "/legal/disclaimer" "Отказ от ответственности"
assert_page_contains "/legal/privacy" "Политика конфиденциальности"
assert_page_contains "/legal/cookies" "Cookie"

robots="$(fetch_body "${BASE_FRONT}/robots.txt")"
if [[ "$robots" != *"Sitemap:"* || "$robots" != *"sitemap.xml"* ]]; then
  echo "robots.txt does not contain sitemap declaration" >&2
  exit 1
fi

sitemap="$(fetch_body "${BASE_FRONT}/sitemap.xml")"
if [[ "$sitemap" != *"<urlset"* ]]; then
  echo "sitemap.xml is invalid" >&2
  exit 1
fi
if [[ "$sitemap" != *"radararena.ru"* ]]; then
  echo "sitemap.xml does not contain canonical host" >&2
  exit 1
fi

echo "frontend checks ok"
