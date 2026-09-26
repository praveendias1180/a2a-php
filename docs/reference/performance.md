# Performance

How fast the SDK's hot paths are, measured with [`scripts/benchmark.php`](https://github.com/praveendias1180/a2a-php/blob/main/scripts/benchmark.php). It has no extra dependencies, so you can run it on your own server:

```bash
php scripts/benchmark.php            # 2 s per case
php scripts/benchmark.php --seconds=5
```

The script refuses to time anything if the benchmark agent doesn't complete its task, so the numbers are never an error path.

## Results

Measured 2026-09-26 on an AWS Lightsail instance: Intel Xeon Platinum 8259CL @ 2.50 GHz, 4 vCPU, 15 GiB RAM, PHP 8.4.25 (CLI, no JIT), **pure-PHP protobuf runtime** (`google/protobuf`, no `ext-protobuf`).

| Case | Operations per second | Time per operation |
|---|---:|---:|
| ProtoJSON encode a `Task` (`serializeToJsonString`) | 931 | 1,074 µs |
| ProtoJSON decode a `Task` (`mergeFromJsonString`) | 2,876 | 348 µs |
| SSE parse, 4 events (`EventStreamParser`) | 169,024 | 5.9 µs |
| JSON-RPC `SendMessage`, full request (in-memory stores) | 834 | 1,199 µs |
| JSON-RPC `SendStreamingMessage`, full SSE body | 663 | 1,509 µs |

What each case does:

- **ProtoJSON encode / decode:** a completed `Task` with 10 history messages and one artifact with 3 parts (3.8 KB of JSON).
- **SSE parse:** the client's stream parser reading a real 4-event stream (1.1 KB) from the server below.
- **JSON-RPC `SendMessage`:** the whole server path in one process. A PSR-7 request goes through `JsonRpcDispatcher` and `DefaultRequestHandler` to a small agent that publishes a Task, a status update and an artifact; in-memory stores; a JSON response. No network.
- **`SendStreamingMessage`:** the same, producing the full SSE body.

## What the numbers mean

- **The SDK adds about 1–1.5 ms per request** in-process. Your agent's own work (an LLM call is typically 500 ms or more) and the network will dominate.
- **Protobuf JSON is the biggest cost.** The pure-PHP runtime works everywhere. For heavy traffic, install the C extension (`pecl install protobuf`); the SDK uses it automatically, with no code changes.
- **Stores and queues change the picture.** These numbers use in-memory stores. With `PdoTaskStore` / `PdoQueueManager` or Redis, each request adds a few database round trips.

## ext-protobuf

`ext-protobuf` wasn't measured on the machine above: building it needs the PHP development headers, which are a system package. The manual [Benchmark workflow](https://github.com/praveendias1180/a2a-php/actions/workflows/benchmark.yml) runs both runtimes side by side on a GitHub runner, and the results appear in that run's summary.
