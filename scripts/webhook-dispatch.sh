#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EVENT="${1:-push}"
REF="${2:-refs/heads/main}"

if [[ "$EVENT" != "push" ]]; then
  echo "[webhook] ignored event: $EVENT"
  exit 0
fi

if [[ "$REF" != "refs/heads/main" ]]; then
  echo "[webhook] ignored ref: $REF"
  exit 0
fi

cd "$ROOT_DIR"
./scripts/ci-run.sh
./scripts/zero-downtime-deploy.sh

echo "[webhook] deploy pipeline completed"
