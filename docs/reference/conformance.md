# Conformance

The SDK is checked with the official **[A2A Technology Compatibility Kit (TCK)](https://github.com/a2aproject/a2a-tck)**, run against `tck/sut-agent.php` (a port of the TCK's own Python system under test) on every push. The **MUST** level is a required CI check.

## Latest results

A2A spec 1.0 · TCK commit `263b9cf` · run 2026-09-25 · PHP-FPM (16 children) behind nginx, SQLite stores

| Level | JSON-RPC | HTTP+JSON | Agent Card | Result |
|---|---|---|---|---|
| **MUST** | 77 passed · 0 failed | 72 passed · 0 failed | 6 passed · 0 failed | ✅ 137 passed, **0 failed** |
| **SHOULD** | 5 passed · 0 failed | 5 passed · 0 failed | 3 passed · 0 failed | ✅ 13 passed, **0 failed** |
| **MAY** | 3 passed · 0 failed | 3 passed · 0 failed | 1 passed · 0 failed | ✅ 7 passed, **0 failed** |

Counts are TCK test cases. gRPC isn't offered, so its tests don't run.

### Laravel bridge

The same TCK against a real Laravel 13 app using `praveendias1180/a2a-laravel` with the **queued runner**: every executor runs on a `php artisan queue:work` process (8 workers, Redis queue), while PHP-FPM (16 children) behind nginx serves the HTTP side and relays each event from the Redis Streams event log. Run 2026-09-25, same TCK commit.

| Level | Result |
|---|---|
| **MUST** | ✅ 137 passed, **0 failed** (3 runs in a row) |
| **SHOULD** | ✅ 13 passed, **0 failed** |
| **MAY** | ✅ 7 passed, **0 failed** |

The skipped tests are the same as for the plain-PHP SUT. A separate check (`scripts/run-tck-laravel.sh long-task`) streams a 20-second task from a queue worker, with an event every 2 s arriving live, and shows a task started with `returnImmediately` finishing on the worker after its request has ended. Both run in CI (`tck-laravel` job).

**Skipped tests.** These are the tests whose features the SUT doesn't declare, exactly as for the Python SDK's SUT:
- push notifications (30)
- the extended Agent Card (6)
- a required extension (2)
- a few that need a non-streaming agent

**Comparison.** On the same TCK commit, the Python SDK's SUT (`a2a-sdk` 1.1.5) scores:
- MUST: 135 passed, 2 failed
- SHOULD: 4 passed, 3 failed

## Where the PHP SDK deliberately differs to conform

| Requirement | Python SDK | PHP SDK |
|---|---|---|
| CORE-CANCEL-002: cancelling a finished task over HTTP+JSON | `400` | `409 Conflict`, as the TCK requires. The released v1.0.0 spec table says 400; the TCK follows the v1.0 release candidate. The `TASK_NOT_CANCELABLE` reason is the same either way. |
| STREAM-SUB-003: subscribing to a finished task | `InvalidParamsError` | `UnsupportedOperationError` |
| DM-SERIAL-005: unknown fields in a request | rejected | ignored, for forward compatibility |
| CARD-CACHE-001/002: Agent Card caching | no headers | `Cache-Control: max-age`, `ETag`, `Last-Modified`, `304` on `If-None-Match` |
| CORE-SEND-002: sending to a finished task | `InvalidParamsError` | `UnsupportedOperationError` |

## Run it yourself

```bash
git clone https://github.com/a2aproject/a2a-tck
pip install -e ./a2a-tck
A2A_TCK_DIR="$PWD/a2a-tck" scripts/run-tck.sh must     # or should, may, all
```

For the Laravel app: `A2A_TCK_DIR="$PWD/a2a-tck" scripts/run-tck-laravel.sh must`. It builds a Laravel app wired to your checkout on first use (`build/tck-laravel-app`), and starts Redis (if `redis-server` is installed), queue workers, PHP-FPM and nginx the same way.

The script starts the SUT on `127.0.0.1:9999` with its own unprivileged PHP-FPM and nginx instances (configured in a temp directory, so system services are untouched), runs the kit over JSON-RPC and HTTP+JSON, and stops everything. `A2A_TCK_SERVER=php-s` uses PHP's built-in server instead, but see the warning in [Running agents in PHP](../concepts/running-agents-in-php.md#serving-streams): its workers can queue a connection behind a long stream, which makes the multi-stream tests flaky. Reports are written to `a2a-tck/reports/`. CI keeps them as the `tck-reports` artifact of the **CI** workflow.
