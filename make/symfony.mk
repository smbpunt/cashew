.PHONY: symfony sf console cc composer

## ---- Symfony ----

symfony: ## Run a console command: make symfony c="cache:clear"
	$(SYMFONY) $(c)

sf: symfony ## Alias for symfony

console: ## Run bin/console: make console c="debug:router"
	$(PHP) bin/console $(c)

cc: ## Clear the cache
	$(SYMFONY) cache:clear

## ---- Composer ----

composer: ## Run composer: make composer c="require symfony/orm-pack"
	$(COMPOSER) $(c)
