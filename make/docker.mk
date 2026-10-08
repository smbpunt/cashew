.PHONY: build up start down logs bash test migrate vendor assets

## ---- Docker ----

build: ## Build Docker images
	@$(DOCKER_COMP) build --pull --no-cache

up: ## Start containers (detached)
	@$(DOCKER_COMP) up --detach

start: build up migrate ## Build, start containers and run release tasks

down: ## Stop containers
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Tail containers logs
	@$(DOCKER_COMP) logs --tail=0 --follow

bash: ## Bash into the php container
	@$(PHP_CONT) bash

test: ## Run PHPUnit, pass options via c="...", e.g. make test c="--group e2e --stop-on-failure"
	@$(eval c ?=)
	@$(PHPUNIT) $(c)

## ---- Database ----

migrate: ## Release tasks: messenger transports + migrations
	@$(SYMFONY) messenger:setup-transports
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration

## ---- Vendor ----

vendor: c=install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction
vendor: composer ## Install vendors according to composer.lock

## ---- Assets ----

assets: ## Install and compile assets
	$(SYMFONY) assets:install && \
	$(SYMFONY) importmap:install && \
	$(SYMFONY) asset-map:compile && \
	if [ "$$($(SYMFONY) debug:container --parameter=kernel.environment 2>/dev/null | grep -c dev)" -eq 1 ]; then rm -R public/assets ; fi
