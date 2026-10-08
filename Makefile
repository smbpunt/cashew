.DEFAULT_GOAL := help

SHELL := /bin/bash
.SHELLFLAGS := -o pipefail -c

# --- Variables ---
DOCKER_COMP = docker compose
PHP_CONT    = $(DOCKER_COMP) exec php

EXEC     = $(DOCKER_COMP) exec -T php
PHP      = $(EXEC) php
COMPOSER = $(EXEC) composer
SYMFONY  = $(PHP) bin/console
PHPUNIT  = $(PHP) vendor/bin/phpunit
REPORTS_DIR ?= var/reports

# --- Includes ---
include make/quality.mk
include make/ci.mk
include make/symfony.mk
include make/docker.mk

# --- Help ---
.PHONY: help
help: ## Affiche cette aide
	@echo ""
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
	@echo ""
