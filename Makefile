all: clean coverage docs

start-server:
	cd vendor/guzzlehttp/ringphp && make start-server

stop-server:
	cd vendor/guzzlehttp/ringphp && make stop-server

test: start-server
	vendor/bin/phpunit
	$(MAKE) stop-server

coverage: start-server
	vendor/bin/phpunit --coverage-html=artifacts/coverage
	$(MAKE) stop-server

view-coverage:
	open artifacts/coverage/index.html

clean:
	rm -rf artifacts/*

docs:
	cd docs && make html && cd ..

view-docs:
	open docs/_build/html/index.html

tag:
	$(if $(TAG),,$(error TAG is not defined. Pass via "make tag TAG=4.2.1"))
	@echo Tagging $(TAG)
	chag update $(TAG)
	sed -i '' -e "s/VERSION = '.*'/VERSION = '$(TAG)'/" src/ClientInterface.php
	php -l src/ClientInterface.php
	git add -A
	git commit -m '$(TAG) release'
	chag tag

perf: start-server
	php tests/perf.php
	$(MAKE) stop-server

package: burgomaster
	php build/packager.php

burgomaster:
	mkdir -p build/artifacts
	curl -s https://raw.githubusercontent.com/mtdowling/Burgomaster/0.0.2/src/Burgomaster.php > build/artifacts/Burgomaster.php

.PHONY: docs burgomaster
