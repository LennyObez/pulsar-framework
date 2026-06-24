#!/usr/bin/env bash
# Start the loopback services the suite expects, then run the COMPLETE PHPUnit
# suite with every issue category surfaced. Used by tools/docker/fulltest.Dockerfile
# to prove a zero-skip / zero-everything run on Linux with all extensions present.
set -euo pipefail

# Redis + Memcached on the loopback addresses the tests probe (127.0.0.1).
redis-server --daemonize yes --dir /tmp --pidfile /tmp/redis.pid --logfile /tmp/redis.log --save ''
memcached -d -p 11211 -l 127.0.0.1

# Wait for Redis to accept connections.
for _ in $(seq 1 40); do
    if redis-cli ping >/dev/null 2>&1; then break; fi
    sleep 0.25
done

exec php -d memory_limit=8G -d opcache.jit=off vendor/bin/phpunit \
    -c tools/php/phpunit.xml --no-coverage \
    --display-deprecations --display-phpunit-deprecations \
    --display-notices --display-phpunit-notices --display-warnings
