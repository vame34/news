# RadarArena Platform

Production-oriented sports editorial platform with automated ingestion, generation pipeline, moderation controls, and zero-downtime deployment.

Language: English | [Русский](README.ru.md)

## Live Site

Current public URL: https://radararena.ru/

## What This Repository Contains

- `backend/` Laravel 11 API, admin panel, ingestion/generation services, workers.
- `frontend/` Next.js App Router SSR site with SEO/schema metadata.
- `ops/` Docker compose for local development and runtime (blue/green).
- `scripts/` bootstrap, smoke tests, CI helpers, deploy automation.
- `docs/` architecture and implementation notes.

## Core Capabilities

- Multi-sport feed and match pages (football, hockey, basketball, tennis, MMA/boxing).
- Fully automatic news generation pipeline (ingestion -> AI generation -> validation -> publishing) without manual article writing.
- Public JSON API for frontend pages and SEO entities.
- Admin authentication with OTP code gate.
- Content generation pipeline with quality gates.
- Guardrails for AI generation cost limits.
- Blue/green deployment with health checks and rollback path.

## Tech Stack

- Backend: PHP 8.3, Laravel 11, PostgreSQL 16, Redis 7
- Frontend: Next.js 14, React 18, TypeScript
- Runtime gateway: Nginx
- Orchestration: Docker Compose

## Quick Start (5 minutes)

### 1. Prerequisites

- Docker + Docker Compose
- GNU Make
- Node.js 20+ (for local frontend commands outside containers)

### 2. One-command local start

```bash
./scripts/dev-up.sh
```

To stop:

```bash
./scripts/dev-down.sh
```

### 3. Open locally

- Frontend: `http://localhost:3000`
- Backend health: `http://localhost:9000/up`
- Admin: `http://localhost:9000/admin/login`
- Nginx gateway: `http://localhost:8080`

## Manual Local Setup

```bash
cp .env.example .env
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env.local
docker compose -f ops/docker-compose.yml up -d --build
make db-bootstrap
```

## Environment Variables

### Root `.env` (docker/dev/runtime wiring)

- `APP_PORT` Nginx port in local dev.
- `BACKEND_PORT`, `FRONTEND_PORT` exposed local ports.
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- `INTERNAL_API_BASE` internal backend URL for SSR calls.
- `NEXT_PUBLIC_API_BASE` browser API base.
- `ADMIN_PASSWORD`, `ADMIN_OTP_CODE` admin credentials.

### Backend `backend/.env`

- `DEEPSEEK_API_KEY` AI provider key.
- `DEEPSEEK_DAILY_REQUEST_LIMIT`, `DEEPSEEK_DAILY_TOKEN_LIMIT`, `DEEPSEEK_DAILY_USD_LIMIT` budget guardrails.
- `INTERNAL_API_TOKEN` internal reindex/generation API token.

### Frontend `frontend/.env.local`

- `NEXT_PUBLIC_SITE_URL`
- `NEXT_PUBLIC_GOOGLE_SITE_VERIFICATION`

## Testing and Quality Checks

Run quick API smoke:

```bash
make smoke-api
```

Run full local quality gate:

```bash
make ci
```

Available targets:

- `make lint`
- `make test-backend-unit`
- `make test-backend-integration`
- `make test-frontend-unit`
- `make test-e2e-smoke`

## Runtime Deployment (Blue/Green)

Runtime compose file: `ops/docker-compose.runtime.yml`

Deploy entrypoint:

```bash
./scripts/zero-downtime-deploy.sh
```

Flow:

1. Build and start target backend/frontend slot.
2. Run migrations on target slot.
3. Validate target slot health.
4. Switch nginx upstream include to target.
5. Recreate nginx to refresh mounted upstream config.
6. Verify public health and rollback on failure.

## Operations Notes

- Runtime storage is PostgreSQL-only.
- News pages are currently text-first; image rendering for generated news is disabled.
- JSON files under `backend/storage/data/` are seed/bootstrap source only.
- Legal pages are available under `/legal/*`.

## Public Readiness Checklist

- Remove all real secrets from tracked files.
- Keep only `.env.example` in git.
- Verify `APP_DEBUG=false` in runtime env.
- Set strong values for `INTERNAL_API_TOKEN`, `ADMIN_PASSWORD`, `ADMIN_OTP_CODE`, and `DEEPSEEK_API_KEY` before production.
- Fill `NEXT_PUBLIC_SITE_URL` and verification meta values.

## Recent Stability Fixes

- Internal `/api` over HTTP in container network without forced HTTPS redirect.
- Docker DNS resolver configured in nginx runtime.
- Nginx recreation on slot switch to avoid stale upstream mount.

These fixes eliminated intermittent long waits after deploy and improved request routing stability between containers.

## Known Issues and Limitations

- First local `next build` can fail if `.next` files were created by root inside Docker; fix ownership before local build.
- API/news quality depends on external providers (DeepSeek, source feeds), so output quality can vary by source availability.
- Blue/green deploy requires correct nginx include mount and cert mounts; misconfigured VPS paths can break rollout.
- Test suite assumes Docker-based local stack and can fail on hosts without required services/ports.
- No GitHub Actions pipeline yet; CI is script-driven (`make ci`, `scripts/ci-run.sh`).
