
all: clean coverage docs

list:
	@sh -c "$(MAKE) -p .FORCE | awk -F':' '/^[a-zA-Z0-9][^\$$#\/\\t=]*:([^=]|$$)/ {split(\$$1,A,/ /);for(i in A)print A[i]}' | grep -v '__\$$' | sort"

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

docs: .FORCE
	cd docs && make html && cd ..

view-docs:
	open docs/_build/html/index.html

.FORCE:
