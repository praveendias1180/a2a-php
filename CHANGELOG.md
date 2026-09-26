# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Push notifications.** `PushNotificationSender` + `BasePushNotificationSender`: every task update is POSTed to the task's webhooks as a `StreamResponse`, with `Authorization` (from the config's `authentication`), `X-A2A-Notification-Token` and a stable `X-A2A-Notification-Id`; retries network errors, 408, 425, 429 and 5xx with exponential backoff (honours `Retry-After`); never follows redirects. Wired into `DefaultRequestHandler` (`pushSender:`), sent after each update is saved, in order; a failing sender never fails the task.
- SSRF protection for webhooks: URLs are checked when a config is created and again before every attempt; with Guzzle (ext-curl) or Symfony HttpClient the connection is pinned to the checked address (DNS-rebinding safe). `PushUrlValidator::resolve()` and `allowedHosts` (for a local receiver in development). `HttpRequest` gains `pinnedAddress` and `followRedirects`; `PinsAddresses` marks senders that can pin.
- `PdoPushNotificationConfigStore` (SQLite, PostgreSQL, MySQL) with optional `encrypt`/`decrypt` closures; `PushNotificationConfigStore::getInfoForDispatch()` (every owner's configs for a task).
- **Agent Card signing** (`Utils\Signing`, needs `firebase/php-jwt`): `createAgentCardSigner()`, `createSignatureVerifier()`, `canonicalizeAgentCard()`, `cleanEmpty()` and the `SignatureVerificationError` family, ported from Python's `utils/signing.py`. `Routes::agentCard(..., signer:)` / `Routes::router(..., cardSigner:)` serve the card signed (a copy, signed once per process). Cross-SDK check in CI: Python-signed cards verify in PHP and PHP-signed cards verify in Python (ES256, HS256).
- **Extensions.** Required extensions are enforced (`ExtensionSupportRequiredError` before the executor runs), requested extensions the card declares are activated, and activated extensions are echoed in the `A2A-Extensions` response header on JSON-RPC and HTTP+JSON. `ServerCallContext::$activatedExtensions`, `RequestContext::activatedExtensions()` / `isExtensionActive()` / `activateExtension()`, `Extensions\Common::activatableExtensions()` / `missingRequiredExtensions()`. Example extension: `examples/extensions/TimestampExtension.php`.
- Laravel: push notifications sent from a queue job (`SendPushNotification`; credentials never enter the queue payload), `a2a.push` config (queue, attempts, backoff, timeout, allowed hosts), webhook URLs checked on create; Agent Card signing via `a2a.signing` (public and extended card).
- TCK: `tck/sut-agent.php` and the Laravel TCK app take `A2A_SUT_PROFILE` (`minimal`, `full`, `required-extension`); `scripts/run-tck.sh` and `scripts/run-tck-laravel.sh` run every profile, so the push-notification, extended-card and required-extension requirements now run instead of being skipped.
- Docs: guides for push notifications, card signing and extensions.

### Security
- The card-signature verifier never uses PEM or OpenSSH public-key material as an HMAC secret, so a card forged with `HS256` and the public key is rejected even when the allow-list mixes HMAC and public-key algorithms (the same guard PyJWT has).

### Changed
- `PushNotificationConfigStore` gained `getInfoForDispatch()`: custom stores must implement it.
- `DefaultRequestHandler`'s `pushUrlValidator` also accepts a `PushUrlValidator` instance.
- The Laravel bridge now checks webhook URLs when a push config is created (private and unresolvable hosts are rejected).

## [0.2.0] - 2026-09-25

Adds the Laravel bridge, released as `praveendias1180/a2a-laravel` 0.2.0 (both packages are versioned together from here on). The official A2A TCK passes at the MUST level against a queued Laravel app on PHP-FPM + nginx.

### Added
- **Laravel bridge** (`praveendias1180/a2a-laravel`, `packages/laravel`), for Laravel 12 and 13 (11 allowed, untested):
  - `Route::a2a('/a2a', agentCard: ..., executor: ...)` mounts the Agent Card, JSON-RPC and HTTP+JSON, fills the card's interfaces in from the routes, and is `route:cache` safe. Agents can also come from `config/a2a.php`.
  - `QueuedTaskRunner` + `RunAgentExecutor` job: executors run on queue workers while the web request streams their events live; one run per task at a time (run lease); executor errors fail the call the same way as with the inline runner.
  - `RedisQueueManager` (Redis Streams, `XREAD BLOCK`, atomic sequence numbers, expiring keys), or the core `PdoQueueManager` on the app's connection.
  - Tasks in the core `PdoTaskStore` on the app's connection; push-notification configs in the database, encrypted with the app key. Owner scoping from the authenticated Laravel user.
  - Security schemes in the card map to route middleware (`a2a.security_schemes`); CSRF is off for the protocol routes.
  - `A2A::client()` facade; artisan `a2a:make-executor`, `a2a:card`, `a2a:prune`, `a2a:tck`; publishable config and migration.
  - `scripts/run-tck-laravel.sh`: the official A2A TCK against a real Laravel app on PHP-FPM + nginx with the queued runner and queue workers (also a CI job), and a 20 s long-task proof.
- `ResponseEmitter::streamSse()`: the SSE pump, public so framework bridges stream exactly like the plain-PHP emitter.
- `examples/laravel/`: the Laravel examples shown in the docs, run by the test suite.
- Docs: Laravel guides (install and first agent, queued execution, streaming behind nginx, storage and pruning).

### Changed
- `praveendias1180/a2a-laravel` requires `praveendias1180/a2a-php` `^0.2`.
- `tck/TckAgentExecutor.php`: the TCK executor moved out of `tck/sut-agent.php`, so the Laravel TCK app reuses it.

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

[Unreleased]: https://github.com/praveendias1180/a2a-php/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/praveendias1180/a2a-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/praveendias1180/a2a-php/releases/tag/v0.1.0
