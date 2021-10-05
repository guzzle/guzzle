help:
	@echo "Please use \`make <target>' where <target> is one of"
	@echo "  start-server                   to start the test server"
	@echo "  stop-server                    to stop the test server"
	@echo "  test                           to perform unit tests.  Provide TEST to perform a specific test."
	@echo "  coverage                       to perform unit tests with code coverage. Provide TEST to perform a specific test."
	@echo "  coverage-show                  to show the code coverage report"
	@echo "  clean                          to remove build artifacts"
	@echo "  docs                           to build the Sphinx docs"
	@echo "  docs-show                      to view the Sphinx docs"
	@echo "  static                         to run phpstan and php-cs-fixer on the codebase"
	@echo "  static-phpstan                 to run phpstan on the codebase"
	@echo "  static-phpstan-update-baseline to regenerate the phpstan baseline file"
	@echo "  static-codestyle-fix           to run php-cs-fixer on the codebase, writing the changes"
	@echo "  static-codestyle-check         to run php-cs-fixer on the codebase"

start-server: stop-server
	node tests/server.js &> /dev/null &
	./vendor/bin/http_test_server &> /dev/null &

stop-server:
	@PID=$(shell ps axo pid,command \
	  | grep 'tests/server.js' \
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

docs:
	cd docs && make html && cd ..

docs-show:
	open docs/_build/html/index.html

static: static-phpstan static-psalm static-codestyle-check

static-psalm:
	composer install
	composer bin psalm update
	vendor/bin/psalm.phar $(PSALM_PARAMS)

static-psalm-update-baseline:
	composer install
	composer bin psalm update
	$(MAKE) static-psalm PSALM_PARAMS="--set-baseline=psalm-baseline.xml"

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

.PHONY: docs coverage-show view-coverage
