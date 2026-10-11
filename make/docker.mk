.PHONY: build up start down logs bash test migrate test-db vendor assets tailwind

## ---- Docker ----

build: ## Build Docker images
	@$(DOCKER_COMP) build --pull --no-cache

up: ## Start containers (detached) and wait until they are healthy
	@$(DOCKER_COMP) up --detach --wait

start: build up migrate ## Build, start containers and run release tasks

down: ## Stop containers
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Tail containers logs
	@$(DOCKER_COMP) logs --tail=0 --follow

bash: ## Bash into the php container
	@$(PHP_CONT) bash

test: test-db ## Run PHPUnit, pass options via c="...", e.g. make test c="--group e2e --stop-on-failure"
	@$(eval c ?=)
	@$(PHPUNIT) $(c)

## ---- Database ----

# Migrations are the only source of the database schema, Messenger's Doctrine table included
# (MESSENGER_TRANSPORT_DSN has auto_setup=0). Don't add "messenger:setup-transports" here: it would
# create that table outside migrations, and the next "make:migration" would then leave it out.
migrate: ## Run the database migrations
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration

test-db: ## Recreate the test database from the migrations
	@$(SYMFONY) doctrine:database:drop --env=test --force --if-exists
	@$(SYMFONY) doctrine:database:create --env=test
	@$(SYMFONY) doctrine:migrations:migrate --env=test --no-interaction --all-or-nothing --allow-no-migration

## ---- Vendor ----

vendor: c=install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction
vendor: composer ## Install vendors according to composer.lock

## ---- Assets ----

assets: ## Install and compile assets
	$(SYMFONY) assets:install && \
	$(SYMFONY) importmap:install && \
	$(SYMFONY) tailwind:build --minify && \
	$(SYMFONY) asset-map:compile && \
	if [ "$$($(SYMFONY) debug:container --parameter=kernel.environment 2>/dev/null | grep -c dev)" -eq 1 ]; then rm -R public/assets ; fi

tailwind: ## Watch and rebuild Tailwind CSS
	@$(PHP_CONT) php bin/console tailwind:build --watch
