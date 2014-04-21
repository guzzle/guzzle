all: clean coverage docs

start-server:
	@ps aux | grep 'node tests/server.js' | grep -v grep > /dev/null \
	|| node tests/server.js &> /dev/null &

stop-server:
	@PID=$(shell ps axo pid,command | grep 'tests/server.js' | grep -v grep | cut -f 1 -d " ") && \
	[ -n "$$PID" ] && \
	kill $$PID || \
	true

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

perf: start-server
	php tests/perf.php
	$(MAKE) stop-server

.PHONY: docs
