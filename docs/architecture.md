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

In Python, `DefaultRequestHandler` starts the executor as an asyncio task. That task keeps running after `SendMessage` returns, and many streams can listen to it. **PHP-FPM can't do that.** Everything is one request, one process, then exit. So we put execution behind one interface:

```php
interface TaskRunner {
    /** Start running the executor for this task. May run inline or elsewhere. */
    public function start(RequestContext $ctx, AgentExecutor $executor): void;
    public function cancel(string $taskId, ServerCallContext $call): void;
}
```

Events always go through a `QueueManager` and are always saved to the `TaskStore`, so any process can rebuild the state of any task.

| Runner | Where | `SendMessage` (blocking) | `returnImmediately` | `SendStreamingMessage` | `SubscribeToTask` from another request | Cancel |
|---|---|---|---|---|---|---|
| **`InlineTaskRunner`** (core default) | same request | runs the executor now, returns when it's final or paused | runs until the **first** event, sends the response, then finishes the work after the response has gone out (`fastcgi_finish_request()` when available). Tasks that take minutes need a real queue | runs inline; each event is flushed as SSE while `execute()` runs | works only for tasks in the same process; otherwise it replays saved state and closes | `CancellationToken` flag |
| **`QueuedTaskRunner`** (Laravel) | a queue job (`RunAgentExecutor`) on a Horizon/supervisor worker | request process waits on the Redis event stream until the task is final or paused (with a timeout) | sends a job, returns the `SUBMITTED` task at once | request process reads the Redis stream (`XREAD BLOCK`) and writes SSE | same as streaming: any web process can attach to the stream | sets the cancel flag in the store + Redis; the executor checks `isCancelled()`; the job ends |
| **Long-running** (later) | RoadRunner / Swoole / ReactPHP / amphp | true concurrency, like Python | same | same | same | same |

**Rules the Python docstrings already state, which we keep:**
- `execute()` is never called twice at once for the same task. Laravel enforces this with a per-task cache lock.
- An exception thrown from `execute()` → the task moves to `FAILED`.
- After `execute()` returns, the executor must not touch the context or the queue.
- For `INPUT_REQUIRED`, publish the status and return. The next message with that `taskId` calls `execute()` again.

**Event ordering (a spec MUST):**
- Redis Streams keep order per task (one stream key per task).
- SSE writes flush after each event.
- The spec's "every stream gets the same events" rule works because every subscriber reads the same stream from its start point.

**SSE on PHP-FPM behind nginx:**
- Send `X-Accel-Buffering: no`, turn off output buffering, and `flush()` each event.
- Send a keep-alive comment every ~15s.
- Document the nginx `fastcgi_buffering off` setting for the SSE location.
- The Laravel bridge uses `response()->stream()` / `StreamedResponse`.

## 4. Storage

| Store | Core | Laravel |
|---|---|---|
| `TaskStore` | `InMemoryTaskStore`, `PdoTaskStore` (one `a2a_tasks` table: id, context_id, owner, status_state, status_ts, protocol_version, task_json, last_updated) | `EloquentTaskStore` on the app's connection + publishable migration |
| `PushNotificationConfigStore` | InMemory, Pdo | Eloquent, **tokens stored encrypted** (`encrypted` cast) |
| `QueueManager` | InMemory | Redis Streams (`a2a:task:{id}`), with a TTL after the task goes final |

The Python `DatabaseTaskStore` columns (including the `owner` and `protocol_version` migrations) are the model for our schema, so both SDKs store the same thing.

## 5. Transports

- **Server:** `JsonRpcDispatcher` and `RestDispatcher` are PSR-15 handlers. `Routes::agentCard()` serves `/.well-known/agent-card.json` with `Cache-Control`. Each handler:
  1. Reads `A2A-Version` and runs the version check.
  2. Builds `ServerCallContext` through the builder, which gets the auth user.
  3. Calls `RequestHandler`.
  4. Turns exceptions into spec error bodies.
- **Client:** any PSR-18 client. Streaming needs the raw response body stream, so we detect Guzzle / Symfony HttpClient and use their streaming mode. We ship `EventStreamParser` for SSE.
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
