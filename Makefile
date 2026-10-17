# ─────────────────────────────────────────────────────────────────────────────
# WFH Attendance System — the one Makefile for the monorepo (backend/ + frontend/).
#
#   make help                  list the targets
#
# Environments
#   backend/.env + frontend/.env                  local development → make up
#   backend/.env.testing + frontend/.env.testing  tests & E2E       → make cover / lint / test
#
# Laravel loads .env.testing INSTEAD of .env whenever APP_ENV=testing (phpunit.xml sets it,
# the recipes below export it). Vite layers frontend/.env.testing over frontend/.env with
# `--mode testing`. The .env.testing files are committed; the .env files are not.
#
# Needs: php + composer, node + npm, lsof (port cleanup) and, for `make cover`,
# a coverage driver (PCOV or Xdebug).
# ─────────────────────────────────────────────────────────────────────────────

SHELL := /bin/sh
.DEFAULT_GOAL := help
MAKEFLAGS += --no-print-directory

BACKEND  := backend
FRONTEND := frontend

# $(call env_value,<file>,<KEY>) → value of KEY in an env file, empty when absent
env_value = $(strip $(shell sed -n 's/^$(2)=//p' $(1) 2>/dev/null | head -n 1 | tr -d '"'))

# Dev ports come from backend/.env (SERVER_PORT) and vite.config.js; E2E ports from
# backend/.env.testing and playwright.config.js. Override on the command line if needed:
#   make up BACKEND_PORT=8000
BACKEND_PORT      ?= $(or $(call env_value,$(BACKEND)/.env,SERVER_PORT),10001)
FRONTEND_PORT     ?= 10002
E2E_BACKEND_PORT  ?= $(or $(call env_value,$(BACKEND)/.env.testing,SERVER_PORT),10003)
E2E_FRONTEND_PORT ?= 10004

# Extra arguments for the test targets, e.g. make test-backend ARGS="--filter=AttendanceTest"
ARGS ?=

# $(call kill_port,<port>) → stop whatever listens on <port>; silent no-op when it is free
kill_port = pids=$$(lsof -nP -iTCP:$(1) -sTCP:LISTEN -t 2>/dev/null); \
	if [ -n "$$pids" ]; then echo "  stopping :$(1) (pid" $$pids ")"; \
	kill $$pids 2>/dev/null; sleep 1; kill -9 $$pids 2>/dev/null; fi; true

.PHONY: help up down cover lint format test test-backend test-e2e

help: ## Show this help
	@printf "\nWFH Attendance System — make targets\n\n"
	@awk 'BEGIN { FS = ":.*## " } /^[a-zA-Z0-9_-]+:.*## / { printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@printf "\n  .env          → make up        backend :%s, frontend :%s\n" "$(BACKEND_PORT)" "$(FRONTEND_PORT)"
	@printf "  .env.testing  → cover/lint/test E2E backend :%s, frontend :%s\n" "$(E2E_BACKEND_PORT)" "$(E2E_FRONTEND_PORT)"
	@printf "  ARGS=\"...\"    extra args for test-backend / test-e2e / cover\n\n"

# ── Development ──────────────────────────────────────────────────────────────

up: ## Start backend + frontend dev servers (.env); frees their ports first
	@test -f $(BACKEND)/.env || { echo "✗ $(BACKEND)/.env missing → cp $(BACKEND)/.env.example $(BACKEND)/.env && (cd $(BACKEND) && php artisan key:generate)"; exit 1; }
	@test -f $(FRONTEND)/.env || { echo "✗ $(FRONTEND)/.env missing → cp $(FRONTEND)/.env.example $(FRONTEND)/.env"; exit 1; }
	@echo "▶ Freeing ports :$(BACKEND_PORT) and :$(FRONTEND_PORT)"
	@$(call kill_port,$(BACKEND_PORT)); $(call kill_port,$(FRONTEND_PORT))
	@echo "▶ Backend   http://localhost:$(BACKEND_PORT)   ($(BACKEND)/.env)"
	@echo "▶ Frontend  http://localhost:$(FRONTEND_PORT)   ($(FRONTEND)/.env)"
	@echo "  Ctrl+C stops both."
	@trap '$(call kill_port,$(BACKEND_PORT)); $(call kill_port,$(FRONTEND_PORT))' EXIT; \
	trap 'echo; echo "▶ Stopping dev servers"; exit 0' INT TERM; \
	(cd $(BACKEND) && exec php artisan serve --host=127.0.0.1 --port=$(BACKEND_PORT)) & \
	(cd $(FRONTEND) && exec npx vite --port $(FRONTEND_PORT) --strictPort) & \
	wait

down: ## Stop dev + E2E servers and clear leftover caches/state
	@echo "▶ Stopping servers (:$(BACKEND_PORT) :$(FRONTEND_PORT) :$(E2E_BACKEND_PORT) :$(E2E_FRONTEND_PORT))"
	@$(call kill_port,$(BACKEND_PORT)); $(call kill_port,$(FRONTEND_PORT)); \
	$(call kill_port,$(E2E_BACKEND_PORT)); $(call kill_port,$(E2E_FRONTEND_PORT))
	@echo "▶ Clearing caches"
	@cd $(BACKEND) && for c in config:clear route:clear view:clear event:clear cache:clear; do \
		if php artisan $$c -q >/dev/null 2>&1; then echo "  artisan $$c"; else echo "  artisan $$c skipped (app not bootable)"; fi; \
	done
	@rm -rf $(FRONTEND)/node_modules/.vite $(BACKEND)/.phpunit.cache \
		$(BACKEND)/database/e2e.sqlite $(BACKEND)/database/e2e.sqlite-journal $(FRONTEND)/tests/e2e/.auth
	@echo "  removed: vite dep cache, phpunit cache, E2E sqlite + auth state"
	@echo "✔ Down"

# ── Quality ──────────────────────────────────────────────────────────────────

cover: ## Backend tests + coverage table (.env.testing); fails under 90% line coverage
	@php -m | grep -qiE '^(pcov|xdebug)$$' || { echo "✗ No coverage driver loaded. Install PCOV (pecl install pcov) or Xdebug, then retry."; exit 1; }
	@cd $(BACKEND) && php artisan config:clear -q && APP_ENV=testing XDEBUG_MODE=coverage php artisan test --coverage $(ARGS)

lint: ## Check code style without changing files: Pint --test (backend) + ESLint (frontend)
	@echo "▶ Backend · vendor/bin/pint --test"
	@cd $(BACKEND) && APP_ENV=testing vendor/bin/pint --test
	@echo "▶ Frontend · eslint ."
	@cd $(FRONTEND) && APP_ENV=testing npm run -s lint
	@echo "✔ Lint passed"

format: ## Auto-format: Pint (backend) + ESLint --fix (frontend)
	@echo "▶ Backend · vendor/bin/pint"
	@cd $(BACKEND) && vendor/bin/pint
	@echo "▶ Frontend · eslint . --fix"
	@cd $(FRONTEND) && npx eslint . --fix
	@echo "✔ Formatted"

# ── Tests ────────────────────────────────────────────────────────────────────

test: lint test-backend test-e2e ## Full pipeline (.env.testing): lint → backend tests → frontend E2E
	@echo "✔ All checks passed"

test-backend: ## Backend test suite only (.env.testing, no coverage)
	@cd $(BACKEND) && php artisan config:clear -q && APP_ENV=testing php artisan test $(ARGS)

test-e2e: ## Frontend Playwright E2E only; boots the backend from backend/.env.testing
	@test -f $(BACKEND)/.env.testing || { echo "✗ $(BACKEND)/.env.testing missing"; exit 1; }
	@cd $(FRONTEND) && npm test -- $(ARGS)
