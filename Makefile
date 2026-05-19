help:
	@echo "Please use \`make <target>' where <target> is one of"
	@printf "  %-32s %s\n" "start-server" "to start the test server"
	@printf "  %-32s %s\n" "stop-server" "to stop the test server"
	@printf "  %-32s %s\n" "test" "to perform unit tests.  Provide TEST to perform a specific test."
	@printf "  %-32s %s\n" "coverage" "to perform unit tests with code coverage. Provide TEST to perform a specific test."
	@printf "  %-32s %s\n" "coverage-show" "to show the code coverage report"
	@printf "  %-32s %s\n" "clean" "to remove build artifacts"
	@printf "  %-32s %s\n" "static" "to run static checks on the codebase"
	@printf "  %-32s %s\n" "static-phpstan" "to run phpstan on the codebase"
	@printf "  %-32s %s\n" "static-phpstan-update-baseline" "to regenerate the phpstan baseline file"
	@printf "  %-32s %s\n" "static-codestyle-fix" "to run php-cs-fixer on the codebase, writing the changes"
	@printf "  %-32s %s\n" "static-codestyle-check" "to run php-cs-fixer on the codebase"
	@printf "  %-32s %s\n" "static-composer-normalize-fix" "to run composer-normalize on composer.json, writing changes"
	@printf "  %-32s %s\n" "static-composer-normalize-check" "to run composer-normalize on composer.json"

start-server: stop-server
	node vendor/guzzlehttp/test-server/src/server.js &> /dev/null &
	./vendor/bin/http_test_server &> /dev/null &

stop-server:
	@PID=$(shell ps axo pid,command \
	  | grep 'vendor/guzzlehttp/test-server/src/server.js' \
	  | grep -v grep \
	  | cut -f 1 -d " "\
	) && [ -n "$$PID" ] && kill $$PID || true
	@PID=$(shell ps axo pid,command \
	  | grep 'vendor/bin/http_test_server' \
	  | grep -v grep \
	  | cut -f 1 -d " "\
	) && [ -n "$$PID" ] && kill $$PID || true

test: start-server
	vendor/bin/phpunit
	$(MAKE) stop-server

coverage: start-server
	vendor/bin/phpunit --coverage-html=build/artifacts/coverage
	$(MAKE) stop-server

coverage-show: view-coverage

view-coverage:
	open build/artifacts/coverage/index.html

clean:
	rm -rf artifacts/*

static: static-phpstan static-codestyle-check static-composer-normalize-check

static-phpstan:
	composer install
	composer bin phpstan update
	vendor/bin/phpstan analyze $(PHPSTAN_PARAMS)

static-phpstan-update-baseline:
	composer install
	composer bin phpstan update
	$(MAKE) static-phpstan PHPSTAN_PARAMS="--generate-baseline"

static-codestyle-fix:
	composer install
	composer bin php-cs-fixer update
	vendor/bin/php-cs-fixer fix --diff $(CS_PARAMS)

static-codestyle-check:
	$(MAKE) static-codestyle-fix CS_PARAMS="--dry-run"

static-composer-normalize-fix:
	composer install
	composer bin composer-normalize update
	composer bin composer-normalize normalize --diff $(COMPOSER_NORMALIZE_PARAMS) ../../composer.json

static-composer-normalize-check:
	$(MAKE) static-composer-normalize-fix COMPOSER_NORMALIZE_PARAMS="--dry-run"

.PHONY: coverage-show view-coverage
