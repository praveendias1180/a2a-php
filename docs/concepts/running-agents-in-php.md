# Running agents in PHP

This is the one place where the PHP SDK **has to** differ from the Python one. Read this page before you deploy an agent.

## The problem

In Python, the request handler starts your executor as a background asyncio task. That task keeps running after the HTTP response is sent, and several clients can stream its events at once.

**PHP-FPM can't do that.** Each request runs in its own process, and when the request ends the process is done. Nothing keeps running in the background.

## The answer: task runners

The SDK puts "where does the executor run?" behind one interface, `TaskRunner`. All events go through a queue manager and are saved to the task store, so **any process can pick up any task**.

| | `InlineTaskRunner` (core default) | `QueuedTaskRunner` (Laravel) |
|---|---|---|
| Where the executor runs | in the web request | a queue job on your workers (Horizon or supervisor) |
| `SendMessage` (blocking) | runs now, returns when done or paused | waits on the task's Redis stream |
| `returnImmediately` | sends the first event, then finishes after the response (`fastcgi_finish_request`) | returns the submitted task right away |
| Streaming | SSE flushed as your executor publishes | the web process reads the Redis stream and writes SSE |
| Re-attaching from another request | replays the stored state | full live stream |
| Good for | short agents, simple hosting | anything that takes more than a few seconds |

Long-running servers (RoadRunner, Swoole, ReactPHP) will get their own runner after 1.0. They behave like Python.

## Cancellation

A running PHP call can't be interrupted from outside. So cancellation is **cooperative**: check `$context->isCancelled()` in long loops and stop when it's true. The SDK sets the flag in the task store, so it works across processes.

## Streaming behind nginx

SSE needs nothing buffered along the way:

```nginx
location /a2a/ {
    fastcgi_buffering off;   # or send the X-Accel-Buffering: no header (the SDK does)
    fastcgi_read_timeout 1h;
    # ... your usual fastcgi_pass config
}
```

The SDK sends a keep-alive comment every 15 seconds so proxies don't close idle streams.

!!! note "Status"
    Runners land in phase 3 (inline) and phase 4 (queued). The design is in [Architecture](../architecture.md#3-the-runtime-problem-and-how-we-handle-it).
