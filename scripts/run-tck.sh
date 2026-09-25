#!/usr/bin/env bash
# Runs the official A2A TCK (https://github.com/a2aproject/a2a-tck) against
# the PHP system under test (tck/sut-agent.php) over JSON-RPC and HTTP+JSON.
#
#     A2A_TCK_DIR=/path/to/a2a-tck scripts/run-tck.sh [must|should|may|all] [-- pytest args]
#
# e.g. `scripts/run-tck.sh must -- -k multi_stream` runs one test module.
#
# The TCK needs Python 3.11+ with its dependencies installed
# (`pip install -e "$A2A_TCK_DIR"`). Reports land in "$A2A_TCK_DIR/reports".
#
# The SUT is served by PHP-FPM behind nginx (both started unprivileged, from
# config written to a temp dir, listening on 127.0.0.1 only), the way agents
# run in production. PHP's built-in server is available as a fallback
# (A2A_TCK_SERVER=php-s) but is not reliable under the TCK: each `php -S`
# worker can accept a second connection just before it starts a long SSE
# request, and that connection then waits for the whole stream even while
# other workers are idle. The TCK's multi-stream tests hit exactly that.
#
# Env: A2A_TCK_PORT (default 9999), A2A_TCK_SERVER (fpm | php-s, default fpm),
#      A2A_TCK_WORKERS (FPM children / php -S workers, default 16; the full
#      MUST suite has at most 4 requests in flight),
#      PHP_FPM (php-fpm binary; default: php-fpm<version> or php-fpm on PATH or in /usr/sbin),
#      NGINX (nginx binary, default nginx).
set -euo pipefail

cd "$(dirname "$0")/.."
repo="$PWD"

tck_dir="${A2A_TCK_DIR:?Set A2A_TCK_DIR to an a2a-tck checkout}"
level="${1:-must}"
if [ "$#" -gt 0 ]; then shift; fi
if [ "${1:-}" = "--" ]; then shift; fi
port="${A2A_TCK_PORT:-9999}"
server="${A2A_TCK_SERVER:-fpm}"
workers="${A2A_TCK_WORKERS:-16}"
run_dir="$(mktemp -d)"
db="$run_dir/sut.sqlite"
pids=()

cleanup() {
    for pid in "${pids[@]}"; do
        kill -- "-$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
    done
    for pid in "${pids[@]}"; do
        wait "$pid" 2>/dev/null || true
    done
    rm -rf "$run_dir"
}
trap cleanup EXIT

find_php_fpm() {
    if [ -n "${PHP_FPM:-}" ]; then
        echo "$PHP_FPM"
        return
    fi
    local version
    version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    for candidate in "php-fpm${version}" php-fpm "/usr/sbin/php-fpm${version}" /usr/sbin/php-fpm; do
        if command -v "$candidate" >/dev/null 2>&1; then
            command -v "$candidate"
            return
        fi
    done
    echo "php-fpm not found; install php${version}-fpm, set PHP_FPM, or use A2A_TCK_SERVER=php-s" >&2
    exit 1
}

start_fpm() {
    local fpm nginx
    fpm="$(find_php_fpm)"
    nginx="${NGINX:-nginx}"
    mkdir -p "$run_dir/nginx-temp"

    cat >"$run_dir/php-fpm.conf" <<EOF
[global]
pid = $run_dir/php-fpm.pid
error_log = $run_dir/php-fpm.log
daemonize = no

[sut]
listen = $run_dir/php-fpm.sock
pm = static
pm.max_children = $workers
catch_workers_output = yes
clear_env = yes
env[A2A_SUT_DB] = $db
env[SUT_HOST] = 127.0.0.1:$port
php_admin_value[max_execution_time] = 0
EOF

    cat >"$run_dir/nginx.conf" <<EOF
worker_processes 1;
daemon off;
pid $run_dir/nginx.pid;
error_log $run_dir/nginx-error.log warn;
events { worker_connections 1024; }
http {
    access_log off;
    client_body_temp_path $run_dir/nginx-temp/body;
    fastcgi_temp_path $run_dir/nginx-temp/fastcgi;
    proxy_temp_path $run_dir/nginx-temp/proxy;
    uwsgi_temp_path $run_dir/nginx-temp/uwsgi;
    scgi_temp_path $run_dir/nginx-temp/scgi;
    server {
        listen 127.0.0.1:$port;
        location / {
            fastcgi_pass unix:$run_dir/php-fpm.sock;
            fastcgi_buffering off;
            fastcgi_read_timeout 300s;
            fastcgi_param SCRIPT_FILENAME $repo/tck/sut-agent.php;
            fastcgi_param REQUEST_METHOD \$request_method;
            fastcgi_param REQUEST_URI \$request_uri;
            fastcgi_param QUERY_STRING \$query_string;
            fastcgi_param CONTENT_TYPE \$content_type;
            fastcgi_param CONTENT_LENGTH \$content_length;
            fastcgi_param SERVER_PROTOCOL \$server_protocol;
            fastcgi_param SERVER_NAME \$host;
            fastcgi_param SERVER_PORT \$server_port;
            fastcgi_param REMOTE_ADDR \$remote_addr;
        }
    }
}
EOF

    set -m
    "$fpm" --nodaemonize --fpm-config "$run_dir/php-fpm.conf" >"$run_dir/php-fpm.out" 2>&1 &
    pids+=("$!")
    "$nginx" -p "$run_dir" -e "$run_dir/nginx-error.log" -c "$run_dir/nginx.conf" >"$run_dir/nginx.out" 2>&1 &
    pids+=("$!")
    set +m
}

start_php_s() {
    set -m
    A2A_SUT_DB="$db" SUT_HOST="127.0.0.1:${port}" PHP_CLI_SERVER_WORKERS="$workers" \
        php -S "127.0.0.1:${port}" tck/sut-agent.php >"$run_dir/php-s.log" 2>&1 &
    pids+=("$!")
    set +m
}

case "$server" in
    fpm) start_fpm ;;
    php-s) start_php_s ;;
    *) echo "Unknown A2A_TCK_SERVER '$server' (use fpm or php-s)" >&2; exit 1 ;;
esac

card_url="http://127.0.0.1:${port}/.well-known/agent-card.json"
for _ in $(seq 1 40); do
    curl -sf -o /dev/null "$card_url" && break
    sleep 0.5
done
if ! curl -sf -o /dev/null "$card_url"; then
    echo "The PHP SUT did not start ($server):" >&2
    tail -n +1 "$run_dir"/*.log "$run_dir"/*.out 2>/dev/null >&2 || true
    exit 1
fi

args=(--sut-host "http://127.0.0.1:${port}" --transport jsonrpc,http_json)
if [ "$level" != "all" ]; then
    args+=(--level "$level")
fi
if [ "$#" -gt 0 ]; then
    args+=(-- "$@")
fi

cd "$tck_dir"
python3 run_tck.py "${args[@]}"
