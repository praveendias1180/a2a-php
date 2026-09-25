# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-09-25

First release: A2A 1.0 client and server for PHP 8.2+. The server passes the official A2A TCK at the MUST level (137 passed) over JSON-RPC and HTTP+JSON. The Laravel bridge is not included yet (phase 4).

### Added
- Wire types generated from the A2A v1.0.0 `a2a.proto` (`A2A\Types\*`).
- `A2A\Utils\Constants` and `A2A\Utils\TransportProtocol`.
- ProtoJSON round-trip tests against the specification's JSON shapes.
- Laravel bridge package skeleton (`packages/laravel`).
- Errors (`A2A\Utils\Errors\*`): one exception per A2A and JSON-RPC error, with the
  same JSON-RPC codes, HTTP statuses, gRPC statuses and ErrorInfo reasons as the
  Python SDK (`ErrorMapping`).
- `A2A\Utils\ErrorHandlers`: REST error payloads and JSON-RPC error objects, including
  `google.rpc.ErrorInfo` and `google.rpc.BadRequest` details. Unlike the Python
  SDK, a non-A2A exception becomes JSON-RPC `-32603 "Internal error"` without its
  message, so internal details never reach the caller (REST already hid them).
- `A2A\Utils\ProtoUtils`: stream-response wrapping, `Struct`/`Value` ↔ PHP conversion,
  REST query-parameter parsing, and REQUIRED-field validation driven by a table
  generated from `a2a.proto` (`A2A\Types\Meta\RequiredFields`,
  `scripts/generate-required-fields.php`).
- `A2A\Utils\TaskUtils` (history length, page size, page tokens), `JsonUtils`,
  `VersionValidator` (`A2A-Version` header) and `PushUrlValidator` (SSRF guard for
  push-notification URLs, with an injectable resolver).
- `A2A\Utils\Jcs`: RFC 8785 JSON canonicalization, checked against the a2a-jcs-v01
  vector corpus and against ECMAScript number formatting.
- `A2A\Helpers\ProtoHelpers` and `AgentCardHelpers`, `A2A\Extensions\Common`,
  `A2A\Auth\User` and `UnauthenticatedUser`.
- The client (`A2A\Client\*`), in the shape of the Python SDK's `a2a.client`:
  `ClientFactory` (with `createClient()` and `minimalAgentCard()`), `BaseClient`,
  `ClientConfig`, `ClientCallContext`, `A2ACardResolver` (including the pre-1.0 card
  field mapping), interceptors (`ClientCallInterceptor`, `BeforeArgs`, `AfterArgs`),
  `AuthInterceptor` with `InMemoryContextCredentialStore`, service parameters, and the
  client errors (`A2AClientError`, `A2AClientTimeoutError`, `AgentCardResolutionError`).
- JSON-RPC and HTTP+JSON transports (`JsonRpcTransport`, `RestTransport`,
  `TenantTransportDecorator`) with every A2A operation. Errors from the agent come back
  as the matching `A2A\Utils\Errors\*` exception. Streams are PHP Generators.
- `A2A\Client\Http\HttpSender` with senders for Guzzle and Symfony HttpClient (live
  SSE streaming) and any PSR-18 client (buffered), picked by `HttpSenderFactory`; an
  incremental SSE parser (`A2A\Client\Sse\EventStreamParser`).
- Interop tests against the official Python SDK's sample agent (a2a-sdk 1.1.5): every
  client operation over JSON-RPC and HTTP+JSON, with Guzzle, Symfony HttpClient and a
  PSR-18 client (`scripts/run-python-interop.sh`, CI job `python-interop`).
- The server (`A2A\Server\*`), in the shape of the Python SDK's `a2a.server`:
  `AgentExecutor`, `RequestContext` (with `isCancelled()`), `RequestContextBuilder`,
  `SimpleRequestContextBuilder`, `ActiveTask`, `EventConsumer`, `ActiveTaskRegistry`,
  `EventQueue`, `InMemoryEventQueue`, `TaskStore`, `InMemoryTaskStore`,
  `CopyingTaskStore`, `TaskManager`, `TaskUpdater`, `ResultAggregator`,
  `PushNotificationConfigStore` (in-memory), `RequestHandler`, `DefaultRequestHandler`
  (a port of Python's `DefaultRequestHandlerV2`), `ServerCallContext`, `OwnerResolver`
  and `IdGenerator`/`UuidGenerator`.
- The executor runs in a PHP Fiber: every enqueued event is checked, saved, published
  and streamed before the executor continues. `TaskRunner` / `InlineTaskRunner` decide
  where it runs, hold a per-task run lease and finish deferred work after the response.
- `QueueManager` as a per-task event log with cancel flags and run leases, so separate
  PHP processes can subscribe to, stream and cancel the same task:
  `InMemoryQueueManager` and `PdoQueueManager` (SQLite, PostgreSQL, MySQL).
- `PdoTaskStore` (SQLite, PostgreSQL, MySQL; owner scoping, keyset pagination), brought
  forward from phase 5.
- PSR-15 handlers: `JsonRpcDispatcher`, `RestDispatcher` (with tenant prefixes),
  `AgentCardHandler` (`Cache-Control`, `ETag`, `Last-Modified`, 304), `Router`,
  `Routes`; `ResponseEmitter` (per-event SSE flushing, disconnect detection, background
  work after the response), `ServerRequestFactory`, `Sse\SseStream`,
  `DefaultServerCallContextBuilder`.
- `examples/hello-world` (a port of the Python SDK's sample agent) and `tck/sut-agent.php`.
- Conformance: the official A2A TCK passes at the MUST (137/137), SHOULD and MAY levels
  over JSON-RPC and HTTP+JSON (`scripts/run-tck.sh`, CI job `tck`), and the official
  Python SDK client works against the PHP server over both transports.

### Changed
- `TaskNotCancelableError` maps to HTTP 409 (the A2A TCK's CORE-CANCEL-002; the released
  v1.0.0 spec table and the Python SDK say 400). The ErrorInfo reason is unchanged.
- `examples/call-an-agent.php`, shown on the docs site and run in CI.

### Changed
- Requires `php-http/discovery` (finds a PSR-18 client when none is given) and
  `psr/http-factory` ^1.1.

[Unreleased]: https://github.com/praveendias1180/a2a-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/praveendias1180/a2a-php/releases/tag/v0.1.0
