# Conformance

The SDK is checked with the official **[A2A Technology Compatibility Kit (TCK)](https://github.com/a2aproject/a2a-tck)**, run against `tck/sut-agent.php` (a port of the TCK's own Python system under test) on every push. The **MUST** level is a required CI check.

## Latest results

A2A spec 1.0 · TCK commit `263b9cf` · run 2026-09-26 · PHP-FPM (16 children) behind nginx, SQLite stores

Some TCK requirements exclude each other ("push operations fail when push isn't supported" vs "push notifications are delivered"), and the TCK client never sends `A2A-Extensions`, so an agent that requires an extension would fail every other test. The SUT therefore runs in three **profiles** (`A2A_SUT_PROFILE`), each against a fresh server:

| Profile | Capabilities | What it covers |
|---|---|---|
| `minimal` | streaming | everything, including "push / extended card not supported" (CORE-CAP-001, CORE-CAP-003) |
| `full` | streaming, **push notifications**, an extended card that is declared but not configured | push-notification CRUD and delivery (PUSH-*), CARD-EXT-002 |
| `required-extension` | streaming, `urn:a2a:tck:required-extension` marked required | CORE-CAP-004 (only those tests run) |

| Level | `minimal` | `full` | `required-extension` | Distinct tests passed |
|---|---|---|---|---|
| **MUST** | 137 passed · 0 failed | **154 passed · 0 failed** | 2 passed · 0 failed | ✅ **161**, 0 failed |
| **SHOULD** | 13 passed · 0 failed | 13 passed · 0 failed | — | ✅ 13, 0 failed |
| **MAY** | 7 passed · 0 failed | 7 passed · 0 failed | — | ✅ 7, 0 failed |

Counts are TCK test cases over JSON-RPC and HTTP+JSON. Before push notifications and extensions (v0.2.0) the SUT passed 137 MUST tests; the 24 that moved from skipped to passed are PUSH-CREATE-001/002, PUSH-GET-001/002, PUSH-LIST-001, PUSH-DEL-001/002, PUSH-DELIVER-001/002/003, CARD-EXT-002 and CORE-CAP-004, each on both transports.

**Still skipped, and why:**
- gRPC (the SDK doesn't offer a gRPC transport yet): every `grpc` test.
- CARD-EXT-001 (an *authenticated* extended card): the TCK client sends no credentials, so the precondition can't hold. The SDK serves the extended card only to authenticated callers, as the spec requires.
- CORE-CAP-002 and the `-32004` variant: they need an agent that does **not** stream.
- Two task-lifecycle scenarios where the TCK's own agent answers with a Message in task mode (same for the Python SUT).
- Two Content-Type probes that skip when the server accepts a lenient Content-Type (not a MUST).

### Laravel bridge

The same TCK against a real Laravel 13 app using `praveendias1180/a2a-laravel` with the **queued runner**: every executor runs on a `php artisan queue:work` process (8 workers, Redis queue), while PHP-FPM (16 children) behind nginx serves the HTTP side and relays each event from the Redis Streams event log. Push notifications are sent from queue jobs. Same profiles, run 2026-09-26, same TCK commit.

| Level | `minimal` | `full` | `required-extension` |
|---|---|---|---|
| **MUST** | ✅ 137 passed · 0 failed | ✅ **154 passed · 0 failed** | ✅ 2 passed · 0 failed |

A separate check (`scripts/run-tck-laravel.sh long-task`) streams a 20-second task from a queue worker, with an event every 2 s arriving live, and shows a task started with `returnImmediately` finishing on the worker after its request has ended. Both run in CI (`tck-laravel` job).

**Comparison.** On the same TCK commit, the Python SDK's SUT (`a2a-sdk` 1.1.5, push notifications off) scores:
- MUST: 135 passed, 2 failed
- SHOULD: 4 passed, 3 failed

### Cross-SDK checks

Besides the TCK, CI runs the official Python SDK against this one (`scripts/run-python-interop.sh`): the PHP client against the Python sample server, the Python client against the PHP server, and Agent Card signatures made by each SDK verified by the other (ES256 and HS256, with byte-identical canonical JSON).

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

Both scripts run every profile; `A2A_TCK_PROFILES="full"` picks some. For the Laravel app: `A2A_TCK_DIR="$PWD/a2a-tck" scripts/run-tck-laravel.sh must`. It builds a Laravel app wired to your checkout on first use (`build/tck-laravel-app`), and starts Redis (if `redis-server` is installed), queue workers, PHP-FPM and nginx the same way.

The script starts the SUT on `127.0.0.1:9999` with its own unprivileged PHP-FPM and nginx instances (configured in a temp directory, so system services are untouched), runs the kit over JSON-RPC and HTTP+JSON, and stops everything. `A2A_TCK_SERVER=php-s` uses PHP's built-in server instead, but see the warning in [Running agents in PHP](../concepts/running-agents-in-php.md#serving-streams): its workers can queue a connection behind a long stream, which makes the multi-stream tests flaky. Reports are written to `a2a-tck/reports/`. CI keeps them as the `tck-reports` artifact of the **CI** workflow.
