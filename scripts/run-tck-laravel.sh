#!/usr/bin/env bash
# Runs the official A2A TCK against a real Laravel app that uses the bridge
# (packages/laravel) with the QUEUED runner: every executor runs on a
# `php artisan queue:work` process while PHP-FPM behind nginx serves the
# HTTP side, streaming events from the shared event log.
#
#     A2A_TCK_DIR=/path/to/a2a-tck scripts/run-tck-laravel.sh [must|should|may|all|long-task|v03-interop] [-- pytest args]
#
# `long-task` runs scripts/tck-laravel/long-task-proof.php instead of the
# TCK: a 20 s task streamed live from a queue worker, and one that keeps
# running after the request that started it has ended.
#
# `v03-interop` turns on the bridge's A2A v0.3 compatibility (a2a.v0_3_compat)
# and runs an A2A v0.3 client (the Python a2a-sdk 0.3.x) against the app:
# tests/Interop/python/v03_client_against_php.py --agent tck. Set
# A2A_PYTHON_V03 to a python with a2a-sdk 0.3.x (see
# tests/Interop/python/requirements-v03.txt); default python3.
#
# The app is built once (composer create-project laravel/laravel) in
# $A2A_LARAVEL_APP (default build/tck-laravel-app), wired to this checkout
# through Composer path repositories, and reused on later runs.
#
# Everything the script starts runs unprivileged from a temp dir on
# 127.0.0.1 and is stopped on exit: nginx, PHP-FPM, the queue workers and,
# unless A2A_REDIS_HOST is set, a private redis-server.
#
# Like scripts/run-tck.sh, the TCK runs once per SUT profile (minimal, full,
# required-extension; see tck/sut-agent.php), restarting PHP-FPM and the
# queue workers in between. `long-task` uses the minimal profile.
#
# Env: A2A_TCK_PROFILES (default "minimal full required-extension"),
#      A2A_TCK_PORT (default 9998), A2A_FPM_CHILDREN (default 16),
#      A2A_QUEUE_WORKERS (default 8), A2A_EVENTS_DRIVER (redis | database;
#      default redis when Redis is available),
#      A2A_REDIS_HOST / A2A_REDIS_PORT (use an existing Redis, e.g. a CI
#      service; otherwise one is started on A2A_REDIS_PORT, default 16391),
#      PHP_FPM, NGINX (binaries), A2A_LARAVEL_APP (app dir).
set -euo pipefail

cd "$(dirname "$0")/.."
repo="$PWD"

level="${1:-must}"
if [ "$#" -gt 0 ]; then shift; fi
if [ "${1:-}" = "--" ]; then shift; fi
if [ "$level" != "long-task" ] && [ "$level" != "v03-interop" ]; then
    tck_dir="${A2A_TCK_DIR:?Set A2A_TCK_DIR to an a2a-tck checkout}"
fi
v03_compat=false
if [ "$level" = "v03-interop" ]; then v03_compat=true; fi
port="${A2A_TCK_PORT:-9998}"
children="${A2A_FPM_CHILDREN:-16}"
queue_workers="${A2A_QUEUE_WORKERS:-8}"
app="${A2A_LARAVEL_APP:-$repo/build/tck-laravel-app}"
profiles="${A2A_TCK_PROFILES:-minimal full required-extension}"
if [ "$level" = "long-task" ] || [ "$level" = "v03-interop" ]; then profiles=minimal; fi
run_dir="$(mktemp -d)"
pids=()

stop_services() {
    for pid in "${pids[@]}"; do
        kill -- "-$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
    done
    for pid in "${pids[@]}"; do
        wait "$pid" 2>/dev/null || true
    done
    pids=()
}

cleanup() {
    stop_services
    if [ -f "$run_dir/redis.pid" ]; then
        kill "$(cat "$run_dir/redis.pid")" 2>/dev/null || true
    fi
    rm -rf "$run_dir"
}
trap cleanup EXIT

find_php_fpm() {
    if [ -n "${PHP_FPM:-}" ]; then echo "$PHP_FPM"; return; fi
    local version
    version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    for candidate in "php-fpm${version}" php-fpm "/usr/sbin/php-fpm${version}" /usr/sbin/php-fpm; do
        if command -v "$candidate" >/dev/null 2>&1; then command -v "$candidate"; return; fi
    done
    echo "php-fpm not found; install php${version}-fpm or set PHP_FPM" >&2
    exit 1
}

# --- Redis -------------------------------------------------------------------
redis_host="${A2A_REDIS_HOST:-}"
redis_port="${A2A_REDIS_PORT:-16391}"
if [ -z "$redis_host" ] && command -v redis-server >/dev/null 2>&1 && php -m | grep -qi '^redis$'; then
    redis-server --port "$redis_port" --bind 127.0.0.1 --dir "$run_dir" --save '' --appendonly no \
        --daemonize yes --pidfile "$run_dir/redis.pid" --logfile "$run_dir/redis.log"
    redis_host=127.0.0.1
fi
events="${A2A_EVENTS_DRIVER:-}"
if [ -z "$events" ]; then
    if [ -n "$redis_host" ]; then events=redis; else events=database; fi
fi
if [ "$events" = redis ] && [ -z "$redis_host" ]; then
    echo "A2A_EVENTS_DRIVER=redis needs Redis (redis-server + phpredis, or A2A_REDIS_HOST)" >&2
    exit 1
fi
queue_driver=database
cache_store=file
if [ -n "$redis_host" ]; then queue_driver=redis; cache_store=redis; fi

# --- The Laravel app -----------------------------------------------------------
if [ ! -f "$app/artisan" ]; then
    echo "Creating the Laravel app in $app ..."
    composer create-project laravel/laravel "$app" --no-interaction --prefer-dist --quiet
fi
(
    cd "$app"
    composer config repositories.a2a-core "{\"type\":\"path\",\"url\":\"$repo\",\"options\":{\"symlink\":true,\"versions\":{\"praveendias1180/a2a-php\":\"0.2.99\"}}}"
    composer config repositories.a2a-laravel "{\"type\":\"path\",\"url\":\"$repo/packages/laravel\",\"options\":{\"symlink\":true,\"versions\":{\"praveendias1180/a2a-laravel\":\"0.2.99\"}}}"
    # Check the installed package, not composer.json: the repositories block
    # above already mentions the package name, so a grep would always match.
    if [ ! -e vendor/praveendias1180/a2a-laravel/composer.json ]; then
        composer require --no-interaction --quiet "praveendias1180/a2a-laravel:0.2.99" "praveendias1180/a2a-php:0.2.99"
    fi
    # A reused app keeps the package metadata (and so the autoload map) from when
    # the path packages were installed; refresh it so namespaces added to the
    # checkout since then resolve.
    composer update --no-interaction --quiet praveendias1180/a2a-php praveendias1180/a2a-laravel
    mkdir -p app/A2A
    cp "$repo/tck/TckAgentExecutor.php" app/A2A/TckAgentExecutor.php
    cp "$repo/scripts/tck-laravel/TckAgentCard.php" "$repo/scripts/tck-laravel/LongTaskExecutor.php" app/A2A/
    cp "$repo/scripts/tck-laravel/A2ATckServiceProvider.php" app/Providers/
    grep -q 'A2ATckServiceProvider' bootstrap/providers.php \
        || sed -i 's#^];#    App\\Providers\\A2ATckServiceProvider::class,\n];#' bootstrap/providers.php

    php -r '
        [$file, $values] = [".env", json_decode($argv[1], true)];
        $env = file_get_contents($file);
        foreach ($values as $key => $value) {
            $line = "$key=$value";
            $env = preg_match("/^#?\s*$key=.*$/m", $env) ? preg_replace("/^#?\s*$key=.*$/m", $line, $env) : rtrim($env) . "\n$line\n";
        }
        file_put_contents($file, $env);
    ' "$(printf '{"APP_ENV":"production","APP_DEBUG":"false","APP_URL":"http://127.0.0.1:%s","LOG_CHANNEL":"single","LOG_LEVEL":"warning","DB_CONNECTION":"sqlite","QUEUE_CONNECTION":"%s","CACHE_STORE":"%s","SESSION_DRIVER":"array","REDIS_CLIENT":"phpredis","REDIS_HOST":"%s","REDIS_PORT":"%s","A2A_EVENTS_DRIVER":"%s","A2A_QUEUE_TIMEOUT":"600","A2A_V0_3_COMPAT":"%s"}' \
        "$port" "$queue_driver" "$cache_store" "${redis_host:-127.0.0.1}" "$redis_port" "$events" "$v03_compat")"

    # SQLite shared by FPM children and workers: wait on locks instead of failing.
    sed -i "s#'busy_timeout' => null#'busy_timeout' => 10000#; s#'journal_mode' => null#'journal_mode' => 'wal'#" config/database.php
    touch database/database.sqlite
    ls database/migrations/*_create_a2a_tables.php >/dev/null 2>&1 || php artisan vendor:publish --tag=a2a-migrations --no-interaction >/dev/null
    php artisan migrate --force --no-interaction >/dev/null
    php artisan config:clear >/dev/null
    # The TCK SUT's stream limits (same as tck/sut-agent.php).
    if [ ! -f config/a2a.php ]; then php artisan vendor:publish --tag=a2a-config --no-interaction >/dev/null; fi
    sed -i "s#'keep_alive' => [0-9.]*,#'keep_alive' => 2.0,#; s#'max_idle' => null,#'max_idle' => 12.0,#" config/a2a.php
)

start_services() {
    local profile="$1"
    # --- PHP-FPM + nginx -----------------------------------------------------------
    fpm="$(find_php_fpm)"
    nginx="${NGINX:-nginx}"
    mkdir -p "$run_dir/nginx-temp"
    cat >"$run_dir/php-fpm.conf" <<EOF
[global]
pid = $run_dir/php-fpm.pid
error_log = $run_dir/php-fpm.log
daemonize = no

[app]
listen = $run_dir/php-fpm.sock
pm = static
pm.max_children = $children
catch_workers_output = yes
clear_env = no
env[A2A_SUT_PROFILE] = $profile
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
        root $app/public;
        location / {
            fastcgi_pass unix:$run_dir/php-fpm.sock;
            fastcgi_buffering off;
            fastcgi_read_timeout 600s;
            fastcgi_param SCRIPT_FILENAME $app/public/index.php;
            fastcgi_param SCRIPT_NAME /index.php;
            fastcgi_param DOCUMENT_ROOT $app/public;
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
    for i in $(seq 1 "$queue_workers"); do
        (cd "$app" && A2A_SUT_PROFILE="$profile" exec php artisan queue:work --sleep=0.05 --timeout=600 --tries=1 >"$run_dir/worker-$i.log" 2>&1) &
        pids+=("$!")
    done
    set +m

    card_url="http://127.0.0.1:${port}/.well-known/agent-card.json"
    for _ in $(seq 1 40); do
        curl -sf -o /dev/null "$card_url" && break
        sleep 0.5
    done
    if ! curl -sf -o /dev/null "$card_url"; then
        echo "The Laravel SUT did not start:" >&2
        tail -n +1 "$run_dir"/*.log "$run_dir"/*.out "$app/storage/logs/laravel.log" 2>/dev/null >&2 || true
        exit 1
    fi
    echo "Laravel SUT on $card_url (profile: $profile, events: $events, queue: $queue_driver, $queue_workers workers, $children FPM children)"

}

extra=("$@")
failed=()
for profile in $profiles; do
    echo "=== A2A TCK (Laravel): level $level, SUT profile $profile ==="
    start_services "$profile"

    if [ "$level" = "v03-interop" ]; then
        "${A2A_PYTHON_V03:-python3}" "$repo/tests/Interop/python/v03_client_against_php.py" "http://127.0.0.1:${port}" --agent tck
        exit 0
    fi

    if [ "$level" = "long-task" ]; then
        (cd "$app" && php "$repo/scripts/tck-laravel/long-task-proof.php" "http://127.0.0.1:${port}")
        echo "queue worker pids: $(pgrep -d ' ' -f "artisan queue:work --sleep=0.05 --timeout=600" || true)"
        exit 0
    fi

    args=(--sut-host "http://127.0.0.1:${port}" --transport jsonrpc,http_json)
    if [ "$level" != "all" ]; then
        args+=(--level "$level")
    fi
    pytest_args=("${extra[@]}")
    if [ "$profile" = "required-extension" ]; then
        pytest_args+=(-k required_extension)
    fi
    if [ "${#pytest_args[@]}" -gt 0 ]; then
        args+=(-- "${pytest_args[@]}")
    fi
    status=0
    (cd "$tck_dir" && python3 run_tck.py "${args[@]}") || status=$?
    # Keep each profile's report (the TCK overwrites reports/ on every run).
    if [ -f "$tck_dir/reports/junitreport.xml" ]; then
        cp "$tck_dir/reports/junitreport.xml" "$tck_dir/reports/junitreport-${level}-${profile}.xml"
    fi
    # pytest exits 5 when a -k filter selects nothing at this level.
    if [ "$status" -ne 0 ] && [ "$status" -ne 5 ]; then
        failed+=("$profile")
    fi
    stop_services
done

if [ "${#failed[@]}" -gt 0 ]; then
    echo "A2A TCK (Laravel) failed for SUT profile(s): ${failed[*]}" >&2
    exit 1
fi
echo "A2A TCK (Laravel) passed for SUT profile(s): $profiles"
