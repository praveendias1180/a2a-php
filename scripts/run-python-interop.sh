#!/usr/bin/env bash
# Interop with the official A2A Python SDK, in both directions, over
# JSON-RPC and HTTP+JSON:
#   1. the PHP client against the Python sample server
#      (tests/Interop/python/hello_world_agent.py)
#   2. the Python client (tests/Interop/python/client_against_php.py)
#      against the PHP hello-world server (examples/hello-world/server.php)
#
# Needs python3 with the pinned SDK:
#     pip install -r tests/Interop/python/requirements.txt
# (or `pip install --target DIR ...` and export PYTHONPATH=DIR).
#
# Env: A2A_INTEROP_PORT (default 41241), A2A_INTEROP_GRPC_PORT (default 50051;
# the sample also opens the next port for its 0.3 gRPC server),
# A2A_INTEROP_PHP_PORT (default 41242, the PHP server).
# Extra arguments go to PHPUnit, e.g. --filter Subscribe.
set -euo pipefail

cd "$(dirname "$0")/.."

port="${A2A_INTEROP_PORT:-41241}"
grpc_port="${A2A_INTEROP_GRPC_PORT:-50051}"
log="$(mktemp)"

python3 tests/Interop/python/hello_world_agent.py \
    --port "$port" --grpc-port "$grpc_port" --compat-grpc-port "$((grpc_port + 1))" \
    >"$log" 2>&1 &
server_pid=$!
php_pid=""
php_db="$(mktemp -u)-a2a-interop.sqlite"

cleanup() {
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    if [ -n "$php_pid" ]; then
        # php -S with workers: stop the whole process group.
        kill -- "-$php_pid" 2>/dev/null || kill "$php_pid" 2>/dev/null || true
        wait "$php_pid" 2>/dev/null || true
    fi
    rm -f "$log" "$php_db" "$php_db-wal" "$php_db-shm"
}
trap cleanup EXIT

card_url="http://127.0.0.1:${port}/.well-known/agent-card.json"
for _ in $(seq 1 60); do
    if curl -sf -o /dev/null "$card_url"; then
        break
    fi
    if ! kill -0 "$server_pid" 2>/dev/null; then
        echo "The Python sample server exited during startup:" >&2
        cat "$log" >&2
        exit 1
    fi
    sleep 1
done
if ! curl -sf -o /dev/null "$card_url"; then
    echo "The Python sample server did not answer on port ${port} within 60s:" >&2
    cat "$log" >&2
    exit 1
fi

A2A_PYTHON_SERVER_URL="http://127.0.0.1:${port}" vendor/bin/phpunit --group integration --testdox "$@"

# The documented example must work against the same server.
echo
echo "examples/call-an-agent.php:"
output="$(php examples/call-an-agent.php "http://127.0.0.1:${port}" hello)"
echo "$output"
if ! grep -q '^status TASK_STATE_COMPLETED$' <<<"$output"; then
    echo "The example did not reach TASK_STATE_COMPLETED." >&2
    exit 1
fi

# 2. The Python client against the PHP server.
php_port="${A2A_INTEROP_PHP_PORT:-41242}"
echo
echo "Python client -> PHP server (examples/hello-world/server.php on :${php_port}):"
set -m
A2A_DB="$php_db" PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:${php_port}" examples/hello-world/server.php >/dev/null 2>&1 &
php_pid=$!
set +m
for _ in $(seq 1 30); do
    curl -sf -o /dev/null "http://127.0.0.1:${php_port}/.well-known/agent-card.json" && break
    sleep 0.5
done
for binding in JSONRPC HTTP+JSON; do
    python3 tests/Interop/python/client_against_php.py "http://127.0.0.1:${php_port}" "$binding"
done
