#!/usr/bin/env bash
# Interop with A2A v0.3 (the Python a2a-sdk 0.3.x), in both directions, over
# JSON-RPC and HTTP+JSON:
#   1. the PHP client (v1.0 types, Compat\V0_3 transports) against a real v0.3
#      server (tests/Interop/python/v03_hello_world_agent.py)
#   2. a real v0.3 client (tests/Interop/python/v03_client_against_php.py)
#      against the PHP hello-world server with enableV03Compat
#
# The 0.3 SDK can't share an environment with the 1.x SDK (both are the
# `a2a` package), so point A2A_PYTHON_V03 at a python that has
# tests/Interop/python/requirements-v03.txt installed (a venv), or set
# PYTHONPATH to a `pip install --target` dir. Default: python3.
#
# Env: A2A_PYTHON_V03, A2A_INTEROP_V03_PORT (default 41243, the Python v0.3
# server), A2A_INTEROP_V03_PHP_PORT (default 41244, the PHP server).
# Extra arguments go to PHPUnit.
set -euo pipefail

cd "$(dirname "$0")/.."

python="${A2A_PYTHON_V03:-python3}"
port="${A2A_INTEROP_V03_PORT:-41243}"
php_port="${A2A_INTEROP_V03_PHP_PORT:-41244}"
log="$(mktemp)"
php_db="$(mktemp -u)-a2a-interop-v03.sqlite"
php_pid=""

"$python" tests/Interop/python/v03_hello_world_agent.py --port "$port" >"$log" 2>&1 &
server_pid=$!

cleanup() {
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    if [ -n "$php_pid" ]; then
        kill -- "-$php_pid" 2>/dev/null || kill "$php_pid" 2>/dev/null || true
        wait "$php_pid" 2>/dev/null || true
    fi
    rm -f "$log" "$php_db" "$php_db-wal" "$php_db-shm"
}
trap cleanup EXIT

card_url="http://127.0.0.1:${port}/.well-known/agent-card.json"
for _ in $(seq 1 60); do
    curl -sf -o /dev/null "$card_url" && break
    if ! kill -0 "$server_pid" 2>/dev/null; then
        echo "The Python v0.3 server exited during startup:" >&2
        cat "$log" >&2
        exit 1
    fi
    sleep 1
done
if ! curl -sf -o /dev/null "$card_url"; then
    echo "The Python v0.3 server did not answer on port ${port}:" >&2
    cat "$log" >&2
    exit 1
fi

# 1. The PHP client against the v0.3 server.
echo "PHP client -> Python a2a-sdk 0.3 server (:${port}):"
A2A_PYTHON_V03_SERVER_URL="http://127.0.0.1:${port}" vendor/bin/phpunit --group integration-v03 --testdox "$@"

# 2. The v0.3 client against the PHP server.
echo
echo "Python a2a-sdk 0.3 client -> PHP server (examples/hello-world/server.php on :${php_port}):"
set -m
A2A_DB="$php_db" PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:${php_port}" examples/hello-world/server.php >/dev/null 2>&1 &
php_pid=$!
set +m
for _ in $(seq 1 30); do
    curl -sf -o /dev/null "http://127.0.0.1:${php_port}/.well-known/agent-card.json" && break
    sleep 0.5
done
"$python" tests/Interop/python/v03_client_against_php.py "http://127.0.0.1:${php_port}"
