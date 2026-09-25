# Architecture

How the PHP SDK maps the Python SDK's design onto PHP, and where it has to differ.

## 1. Packages

| Package | Depends on | Contains |
|---|---|---|
| **core** (`praveendias1180/a2a-php`) | `php ^8.2`, `google/protobuf`, `psr/http-message`, `psr/http-server-handler`, `psr/http-factory`, `psr/http-client`, `psr/log`, `psr/event-dispatcher`, `psr/clock` | Types, client, server, in-memory + PDO stores, JSON-RPC + REST dispatchers, SSE, push sender, signing, examples, TCK agent |
| **laravel** (`praveendias1180/a2a-laravel`) | core, `illuminate/support ^11\|^12\|^13` | Service provider, `config/a2a.php`, `Route::a2a()` macro, Eloquent stores + migrations, queue-backed `TaskRunner`, Redis `QueueManager`, auth through guards, artisan commands, the `A2A` facade for the client |
| **grpc** (later) | core, `grpc/grpc` | gRPC client transport. A gRPC server needs RoadRunner or Swoole. |

**Why split:** Python uses pip extras. Composer's closest equivalent is `suggest`, but code that depends on a missing package still breaks. Keeping Laravel out of core means Symfony, Slim or plain-PHP users can use core. A Symfony bundle could then come from the community.

**Repo layout:** one repo, `praveendias1180/a2a-php`. The **core package is the repo root** (`src/`, `generated/`, `tests/`), because Packagist only reads a repo's root `composer.json`. The Laravel bridge lives in `packages/laravel` and is mirrored to the read-only repo `praveendias1180/a2a-laravel` on every push to `main` and every tag, with `git subtree split` and an SSH deploy key that can write to that one repo only (`.github/workflows/split.yml`). One PR can change both, and they release together.

## 2. Types

- `buf generate` runs against the pinned `a2a.proto` (a git submodule or a vendored copy of `a2aproject/A2A/specification`). A buf managed-mode override maps `lf.a2a.v1` to the `A2A\Types` namespace.
- The generated code is **committed** (`generated/`), so users don't need `protoc`. CI regenerates it and fails on any diff.
- The wire format is ProtoJSON: `serializeToJsonString()` / `mergeFromJsonString()`. It handles the `oneof` fields, `Struct`/`Value` for `data` and `metadata`, the enum names and camelCase keys correctly. This is why we use generated protobuf classes rather than hand-written DTOs.
- **Speed:** the pure-PHP protobuf runtime works everywhere. `ext-protobuf` is faster and goes under `suggest`.
- **Ease of use:** the generated classes use `new Part(['text' => 'hi'])` and getters/setters. `Helpers\ProtoHelpers` adds short helpers such as `Part::text('hi')`-style factories, `ProtoHelpers::agentMessage(...)` and `ProtoHelpers::userMessage(...)`, mirroring Python's helpers.

## 3. The runtime problem, and how we handle it

In Python, `DefaultRequestHandler` starts the executor as an asyncio task. That task keeps running after `SendMessage` returns, and many streams can listen to it. **PHP-FPM can't do that.** Everything is one request, one process, then exit. Three pieces solve it (built in phase 3).

**1. The executor runs in a Fiber (`ActiveTask`).** Each `enqueueEvent()` suspends the Fiber. The `EventConsumer` then:
- checks the event (the same rules as Python: one Message *or* task mode, nothing after a terminal state),
- applies it through the `TaskManager`,
- publishes it to the `QueueManager`,
- and the event is yielded to the caller.

Then the Fiber resumes. A caller that stops pulling early (`returnImmediately`, an interrupted state) hands the rest to `TaskRunner::defer()`, which runs after the response.

**2. Execution goes behind one interface, `TaskRunner`:**

```php
interface TaskRunner {
    /** Run one request against the task; yield each event as it is processed. */
    public function run(ActiveTask $activeTask, RequestContext $context): \Generator;
    /** Work to finish after the HTTP response has been sent. */
    public function defer(\Closure $work): void;
    public function runDeferred(): void;
}
```

**3. What processes must share lives in a `QueueManager`,** now a per-task event log plus coordination flags:

```php
interface QueueManager {
    public function publish(string $taskId, PublishedEvent $event): int;          // returns the sequence number
    public function read(string $taskId, int $afterSequence, float $waitSeconds = 0.0): array;
    public function lastSequence(string $taskId): int;
    public function requestCancel(string $taskId): void;
    public function isCancelRequested(string $taskId): bool;
    public function acquireRunLease(string $taskId, int $ttlSeconds): bool;       // "a process is running this task"
    public function releaseRunLease(string $taskId): void;
    public function hasActiveRunLease(string $taskId): bool;
}
```

Implementations: `InMemoryQueueManager` (one process), `PdoQueueManager` (SQLite/PostgreSQL/MySQL, polled), and Redis Streams in the Laravel bridge.

| Runner | Where | `SendMessage` (blocking) | `returnImmediately` | `SendStreamingMessage` | `SubscribeToTask` from another request | Cancel |
|---|---|---|---|---|---|---|
| **`InlineTaskRunner`** (core default, built) | same request, in a Fiber | runs the executor now, returns when it's final or paused | answers with the first event, finishes the work after the response (`fastcgi_finish_request()` under PHP-FPM) | each event is flushed as SSE while `execute()` runs | reads the task's event log from the `QueueManager` (`PdoQueueManager` across processes) | sets the cancel flag; the running request stops the executor at its next event; after 10 s the cancel request finishes the job itself |
| **`QueuedTaskRunner`** (Laravel, phase 4) | a queue job on a Horizon/supervisor worker | request process waits on the Redis event stream until the task is final or paused (with a timeout) | sends a job, returns the `SUBMITTED` task at once | request process reads the Redis stream (`XREAD BLOCK`) and writes SSE | same as streaming | same flag; the job's executor sees it |
| **Long-running** (later) | RoadRunner / Swoole / ReactPHP / amphp | true concurrency, like Python | same | same | same | same |

**Rules the Python docstrings already state, which we keep:**
- `execute()` is never called twice at once for the same task. The run lease enforces it across processes.
- An exception thrown from `execute()` moves the task to `FAILED`. The change is published, so subscribers elsewhere stop waiting.
- After `execute()` returns, the executor must not touch the context or the queue.
- For `INPUT_REQUIRED`, publish the status and return. The next message with that `taskId` calls `execute()` again.

**Event ordering (a spec MUST):**
- Every event gets a growing sequence number in the task's log.
- SSE writes flush after each event.
- The spec's "every stream gets the same events" rule works because every subscriber reads the same log from its start point.

**SSE on PHP-FPM behind nginx:**
- `ResponseEmitter` turns off output buffering, sends `X-Accel-Buffering: no` and flushes each event.
- Idle streams send a keep-alive comment every 15 s (`keepAliveSeconds`). `maxSubscribeIdleSeconds` optionally ends quiet subscriptions so abandoned connections can't pin workers.
- Set nginx `fastcgi_buffering off` for the SSE location.
- The Laravel bridge will use `response()->stream()` / `StreamedResponse`.

## 4. Storage

| Store | Core | Laravel |
|---|---|---|
| `TaskStore` | `InMemoryTaskStore`, `PdoTaskStore` (one `{prefix}tasks` table: id, context_id, owner, status_state, status_timestamp (µs), protocol_version, task_json) | `EloquentTaskStore` on the app's connection + publishable migration |
| `PushNotificationConfigStore` | InMemory (Pdo in phase 5) | Eloquent, **tokens stored encrypted** (`encrypted` cast) |
| `QueueManager` | InMemory, `PdoQueueManager` (`{prefix}task_events`, `{prefix}task_flags`) | Redis Streams (`a2a:task:{id}`), with a TTL after the task goes final |

The Python `DatabaseTaskStore` columns (including the `owner` and `protocol_version` migrations) are the model for our schema, so both SDKs store the same thing. `PdoQueueManager::prune()` deletes old events; run it from a scheduled job.

## 5. Transports

- **Server:** `JsonRpcDispatcher` and `RestDispatcher` are PSR-15 handlers. `Routes::agentCard()` serves `/.well-known/agent-card.json` with `Cache-Control`. Each handler:
  1. Reads `A2A-Version` and runs the version check.
  2. Builds `ServerCallContext` through the builder, which gets the auth user.
  3. Calls `RequestHandler`.
  4. Turns exceptions into spec error bodies.
- **Client (built in phase 2):** sending goes through `Client\Http\HttpSender`, because PSR-18 hands back complete responses and so can't stream SSE. `HttpSenderFactory` wraps what you have: Guzzle and Symfony HttpClient stream live, any other PSR-18 client works with buffered streams, and with nothing given it picks Guzzle, then Symfony, then any PSR-18 client php-http/discovery finds. Guzzle's streaming requests go out as HTTP/1.0, because PHP's `http://` wrapper, which Guzzle streams through, holds a chunked HTTP/1.1 body back until it ends. `Sse\EventStreamParser` reads SSE incrementally, handling lines and CRLF pairs split across chunks.
- **gRPC:** later, in its own package.

## 6. Security built in (not left to users)

- Push URLs pass `PushUrlValidator` (resolve DNS, then check every address against the private ranges, so DNS rebinding can't get round it) before we save or call them. Same for fetching `url` Parts.
- Tasks are scoped to their owner in every store query. A task owned by someone else gives `TaskNotFoundError`, never "forbidden".
- Card signing and checking: JWS + RFC 8785 JCS. The client verifies signatures when the card has them.
- The Laravel bridge maps card `securitySchemes` to guards (`auth:sanctum`, a bearer token, or OAuth through Passport).

## 7. Laravel developer experience (target)

```php
// routes/api.php
Route::a2a('/a2a', agentCard: HelloAgentCard::class, executor: HelloExecutor::class)
    ->middleware('auth:sanctum');   // JSON-RPC at /a2a/jsonrpc, REST at /a2a/rest, card at /.well-known/agent-card.json

// app/A2A/HelloExecutor.php  (php artisan a2a:make-executor Hello)
final class HelloExecutor implements AgentExecutor {
    public function execute(RequestContext $ctx, EventQueue $queue): void {
        $u = new TaskUpdater($queue, $ctx->taskId(), $ctx->contextId());
        $u->startWork();
        $u->addArtifact([ProtoHelpers::textPart('Hello, '.$ctx->getUserInput())], name: 'response', lastChunk: true);
        $u->complete();
    }
    public function cancel(RequestContext $ctx, EventQueue $queue): void {
        (new TaskUpdater($queue, $ctx->taskId(), $ctx->contextId()))->cancel();
    }
}

// Client side
foreach (A2A::client('https://agent.example.com')->sendMessage(ProtoHelpers::userMessage('hi')) as $event) { ... }
```

Artisan commands:
- `a2a:make-executor`
- `a2a:card` (prints and validates the card)
- `a2a:tck` (starts the SUT agent for a local TCK run)
- `a2a:prune` (deletes old final tasks)

## 8. Tooling standards

| Area | Tool |
|---|---|
| Static analysis | PHPStan level max (generated code excluded) |
| Style | PHP-CS-Fixer (PER-CS 2.0) |
| Mutation testing | Infection, run on core only |
| Tests | PHPUnit 11 |
| Test matrix | PHP 8.2–8.5 × lowest/highest dependency versions |
