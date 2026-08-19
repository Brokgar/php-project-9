PORT ?= 8000
export PHP_CLI_SERVER_WORKERS = 5

setup:
	composer install

db-init:
	psql "$$DATABASE_URL" -f database.sql

start:
	php -S 0.0.0.0:$(PORT) -t public

lint:
	composer exec phpcs -- --standard=PSR12 public

test:
	composer exec phpunit

validate:
	composer validate

.PHONY: install db-init start lint test validate
