# Running agents in PHP

This is the one place where the PHP SDK **has to** differ from the Python one. Read this page before you deploy an agent.

## The problem

In Python, the request handler starts your executor as a background asyncio task. That task keeps running after the HTTP response is sent, and every client streaming the task reads from queues in the same long-running process.

**PHP-FPM and `php -S` can't do that.** Each request runs in its own process, there is no event loop, and nothing is shared between requests except what you put in a database or cache.

## How the SDK handles it

### 1. Your executor runs in a Fiber

`execute()` runs inside a [PHP Fiber](https://www.php.net/manual/en/language.fibers.php). Every `enqueueEvent()` (and every `TaskUpdater` call) pauses the Fiber. The SDK then checks the event, saves the task, publishes the event and hands it to the response. Only after that does your code continue.

So a streaming response is **live**: the client sees "working" before your agent has finished thinking. And a request that must answer early (`returnImmediately`, or a task that paused for input) stops pulling events, sends its response, and finishes the rest afterwards.

You don't need to know any of this to write an executor: write plain synchronous code.

### 2. Tasks and events live in a shared store

| What | Interface | Use under PHP-FPM / `php -S` | Use in tests or long-running servers |
|---|---|---|---|
| Tasks | `TaskStore` | `PdoTaskStore` | `InMemoryTaskStore` |
| Stream events, cancel flags, "is it running" | `QueueManager` | `PdoQueueManager` | `InMemoryQueueManager` |

`PdoQueueManager` keeps an append-only **event log per task**. Every process reads it from a sequence number:
- When one request runs the agent, a `SubscribeToTask` stream in another request sees the same events in the same order.
- Several streams on one task all get identical events.
- A cancel from another request reaches the running executor.

Both PDO classes work with SQLite, PostgreSQL and MySQL and create their tables on first use. SQLite (in WAL mode) is fine on one machine. Use PostgreSQL or MySQL when several machines serve the agent.

### 3. Where the executor runs: `TaskRunner`

| | `InlineTaskRunner` (core, default) | `QueuedTaskRunner` (Laravel bridge) |
|---|---|---|
| Where `execute()` runs | in the web request, in a Fiber | a queue job on your workers |
| `SendMessage` | runs now, answers when the task finishes or pauses | dispatches a job, then relays the task's events until it finishes or pauses |
| `returnImmediately` | answers with the first event, finishes after the response | answers with the first event; the worker finishes the task |
| Streaming | live SSE from this process | the web process relays the event stream |
| Good for | anything that finishes within your request timeout | long tasks |

While a request runs a task, it holds a **run lease** in the `QueueManager`. A second message for the same task waits for the lease (up to 30 s by default) instead of running the executor twice at once. The Python SDK guarantees the same thing.

### 4. Finishing work after the response

`ResponseEmitter` sends the response first, then calls `RequestHandler::runBackgroundWork()`:
- **Normal responses** get a `Content-Length` and are flushed, then the connection is closed with `fastcgi_finish_request()` under PHP-FPM.
- **Streams** are flushed event by event.

If you use your framework's emitter instead, call `runBackgroundWork()` after sending. As a safety net, the runner also registers a shutdown function.

## Cancellation

A running PHP call can't be interrupted from outside. So cancellation is **cooperative**. When a cancel arrives:

1. The cancel flag is set in the `QueueManager`.
2. If the task is running in another request, that request notices the flag at its executor's next `enqueueEvent()`. It then calls your `cancel()`, and throws a `TaskCancelledException` into `execute()`.
3. If nothing stops the task within 10 s (for example, the executor is inside a long `sleep()`), the cancel request calls `cancel()` itself and marks the task CANCELED.

For long loops, check `$context->isCancelled()` and stop early.

## Serving streams

Each open stream holds one PHP worker for its whole length. Plan for that:

- **Enough workers.** With PHP-FPM, size `pm.max_children` for your streams plus your other traffic.
- **Don't serve streams with `php -S` in production.** Even with `PHP_CLI_SERVER_WORKERS`, each built-in-server worker can accept a second connection just before it starts a long request. That connection then waits for the whole stream to end, even while other workers are idle. It's fine for development, but it made the A2A TCK's multi-stream tests fail intermittently (a subscribe waited 12 s for its first byte), which is why the SDK's TCK runs use PHP-FPM behind nginx. PHP-FPM children only accept a connection when they are idle.
- **Keep-alives.** Idle streams send a keep-alive comment every 15 s (`keepAliveSeconds`). That is also how a closed connection is noticed.
- **Idle limit.** `maxSubscribeIdleSeconds` ends a `SubscribeToTask` stream after that long without events. The client can subscribe again. The default is no limit, like Python.
- **Behind nginx,** turn off buffering for the A2A location (the SDK also sends `X-Accel-Buffering: no`):

```nginx
location /a2a/ {
    fastcgi_buffering off;
    fastcgi_read_timeout 1h;
    # ... your usual fastcgi_pass config
}
```

## Long-running servers

Under RoadRunner, Swoole or a CLI worker, one process serves many requests. There the in-memory store and queue manager work, but the inline runner still runs one executor at a time per process. A runner for these servers is planned after 1.0.
