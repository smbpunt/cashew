.PHONY: ci composer-validate lint-symfony doctrine-validate cache-warmup-prod asset-compile cs-check cs-fix rector-check rector-fix phpstan-check security-audit phpunit

## ---- Validation ----

composer-validate: ## Validate composer.json/lock consistency
	@echo "Validating composer.json…"
	$(COMPOSER) validate --strict --no-check-publish

## ---- Qualité ----

lint-symfony: ## Linters Symfony (YAML, Twig, Container, Translations)
	@echo "Running Symfony linters…"
	$(SYMFONY) lint:yaml config --parse-tags
	$(SYMFONY) lint:twig templates --env=prod
	$(SYMFONY) lint:container --env=prod
	$(SYMFONY) lint:yaml translations
	$(SYMFONY) lint:translations

doctrine-validate: ## Validate Doctrine schema mappings
	@echo "Validating Doctrine schema…"
	$(SYMFONY) doctrine:schema:validate --skip-sync

cache-warmup-prod: ## Warmup prod cache (catches DI/config errors)
	@echo "Warming up prod cache…"
	$(SYMFONY) cache:warmup --env=prod --no-debug

asset-compile: ## Compile AssetMapper (validates imports)
	@echo "Compiling assets…"
	$(SYMFONY) importmap:install
	$(SYMFONY) asset-map:compile; rc=$$?; $(EXEC) rm -rf public/assets; exit $$rc

cs-check: ## PHP-CS-Fixer dry-run
	@echo "Dry run PHP CS Fixer…"
	$(PHP) vendor/bin/php-cs-fixer check --diff --verbose

cs-fix: ## PHP-CS-Fixer apply fixes
	@echo "Running PHP CS Fixer…"
	$(PHP) vendor/bin/php-cs-fixer fix --verbose

rector-check: ## Rector dry-run
	@echo "Running Rector…"
	@mkdir -p var/cache/rector
	$(PHP) vendor/bin/rector process --dry-run

rector-fix: ## Rector apply refactorings
	@echo "Running Rector…"
	$(PHP) vendor/bin/rector process

phpstan-check: ## PHPStan analysis
	@echo "Running PHPStan…"
	$(PHP) vendor/bin/phpstan analyse --memory-limit=512M

security-audit: ## Composer security audit
	@echo "Running Composer security audit…"
	$(COMPOSER) audit --locked --abandoned=report

## ---- Tests ----

phpunit: ## PHPUnit - all tests
	@echo "Running PHPUnit tests…"
	$(PHP) vendor/bin/phpunit --testdox

## ---- CI locale ----

ci: composer-validate security-audit lint-symfony doctrine-validate cache-warmup-prod asset-compile cs-check rector-check phpstan-check phpunit ## CI locale complète
