PORT ?= 8000
export PHP_CLI_SERVER_WORKERS = 5

install:
	composer install

start:
	php -S 0.0.0.0:$(PORT) -t public

lint:
	composer exec phpcs -- --standard=PSR12 public

test:
	composer exec phpunit

validate:
	composer validate

.PHONY: install start lint test validate
