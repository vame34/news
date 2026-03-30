SHELL := /bin/bash
COMPOSE_FILE ?= ops/docker-compose.yml
COMPOSE_PROJECT_ARGS :=

ifneq ($(strip $(COMPOSE_PROJECT_NAME)),)
COMPOSE_PROJECT_ARGS += -p $(COMPOSE_PROJECT_NAME)
endif

DOCKER_COMPOSE := docker compose $(COMPOSE_PROJECT_ARGS) -f $(COMPOSE_FILE)

.PHONY: lint test-backend-unit test-backend-integration test-frontend-unit test-e2e-smoke ci smoke-api sync-db db-bootstrap

lint:
	@$(DOCKER_COMPOSE) exec -T backend sh -lc "find app routes database tests -name '*.php' -type f | sort | xargs -n1 php -l >/dev/null"
	@echo "[lint] php syntax ok"

test-backend-unit:
	@$(DOCKER_COMPOSE) exec -T backend php artisan test --testsuite=Unit

test-backend-integration:
	@$(DOCKER_COMPOSE) exec -T backend php artisan sport-radar:auto-news >/dev/null
	@$(DOCKER_COMPOSE) exec -T backend php artisan sport-radar:validate-news
	@$(DOCKER_COMPOSE) exec -T backend php artisan sport-radar:seo-reindex >/dev/null
	@$(DOCKER_COMPOSE) exec -T backend php artisan sport-radar:process-reindex

test-frontend-unit:
	@COMPOSE_FILE=$(COMPOSE_FILE) ./scripts/test_frontend.sh

test-e2e-smoke:
	@COMPOSE_FILE=$(COMPOSE_FILE) ./scripts/test_e2e_smoke.sh

ci: lint test-backend-unit test-backend-integration test-frontend-unit test-e2e-smoke
	@echo "[ci] all checks passed"

smoke-api:
	@./scripts/smoke-api.sh

sync-db:
	@echo "[sync-db] JSON sync removed: runtime is PostgreSQL-only."

db-bootstrap:
	@$(DOCKER_COMPOSE) exec -T backend php artisan sport-radar:db-bootstrap
