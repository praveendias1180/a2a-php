# Python SDK → PHP mapping

Reference: `a2aproject/a2a-python` @ `0d5473c` (2026-09-24). This is PyPI `a2a-sdk`, 118 test files, Apache-2.0. **This table is the "same shape" contract:** every public Python class gets a PHP class with the same name, in the same position in the tree. Method names change from `snake_case` to `camelCase`. That is the only rename.

**Rule for changes:** when Python adds, renames or removes a public class, update this table first, then the code.

## Package layout

| Python (`src/a2a/…`) | PHP (namespace `A2A\…`) | Package | Notes |
|---|---|---|---|
| `types/a2a_pb2.py` | `Types\*` (generated) | core | Generated from `a2a.proto` with `protoc --php_out`. `php_namespace` is set to `A2A\Types` through buf managed mode, so users don't see `Lf\A2a\V1`. |
| `types/a2a_pb2_grpc.py` | `Types\Grpc\*` (generated) | grpc (later) | |
| `helpers/proto_helpers.py` | `Helpers\ProtoHelpers` | core | `newTaskFromUserMessage`, text/data part builders, `newAgentTextMessage`, … |
| `helpers/agent_card.py` | `Helpers\AgentCardHelpers` | core | |
| `utils/constants.py` | `Utils\Constants` | core | Well-known path, header names, default versions. |
| `utils/errors.py` | `Utils\Errors\*Error` | core | One exception class per A2A error, each carrying its JSON-RPC code + gRPC status. |
| `utils/error_handlers.py`, `grpc_status.py` | `Utils\ErrorHandlers` | core | Exception → JSON-RPC / REST / gRPC error body. |
| `utils/proto_utils.py` | `Utils\ProtoUtils` | core | ProtoJSON encode/decode, `Struct` ↔ PHP array. |
| `utils/json_utils.py` | `Utils\JsonUtils` | core | A separate class, like Python. An empty PHP array means `[]`; use `new \stdClass()` for `{}`. |
| `utils/task.py` | `Utils\TaskUtils` | core | `completedTask()`, apply `historyLength`, … |
| `utils/_jcs.py`, `signing.py` | `Utils\Jcs`, `Utils\Signing` (`createAgentCardSigner()`, `createSignatureVerifier()`, `canonicalizeAgentCard()`, `cleanEmpty()`), `Utils\Signing\{SignatureVerificationError, NoSignatureError, InvalidSignaturesError}` | core (+ `suggest: firebase/php-jwt`, Python's `signing` extra) | RFC 8785 canonicalization + card JWS. HMAC keys must be as long as the hash (RFC 7518). |
| `utils/push_url_validator.py` | `Utils\PushUrlValidator` | core | SSRF guard: resolve DNS, reject private/loopback/link-local addresses. `resolve()` returns the approved addresses so the sender can pin them; `allowedHosts` exempts named hosts (development). |
| `utils/version_validator.py` | `Utils\VersionValidator` | core | Reads `A2A-Version`. |
| `utils/telemetry.py` | `Utils\Telemetry` | core (`suggest: open-telemetry/api`) | No-op unless OTel is installed, same as Python. |
| `extensions/common.py` | `Extensions\Common` | core | Parse `A2A-Extensions`, find extensions by URI. PHP adds `activatableExtensions()` and `missingRequiredExtensions()`, which the DefaultRequestHandler uses to enforce required extensions and activate + echo requested ones (Python leaves that to the app). |
| `auth/user.py` | `Auth\User`, `Auth\UnauthenticatedUser` | core | |

### Server

| Python | PHP | Notes |
|---|---|---|
| `server/agent_execution/agent_executor.py` `AgentExecutor` | `Server\AgentExecution\AgentExecutor` (interface) | `execute(RequestContext, EventQueue): void`, `cancel(RequestContext, EventQueue): void`. **The one interface users write.** |
| `…/context.py` `RequestContext` | `Server\AgentExecution\RequestContext` | `getUserInput()`, `taskId()`, `contextId()`, `message()`, `currentTask()`, `relatedTasks()`, `configuration()`, `callContext()`, `metadata()`, `tenant()`, `requestedExtensions()`. PHP adds `isCancelled()` (cooperative cancellation, see `CancellationToken`) and `activatedExtensions()`, `isExtensionActive()`, `activateExtension()`. |
| `…/request_context_builder.py`, `simple_request_context_builder.py` | same names | |
| `…/active_task.py` `ActiveTask`, `EventConsumer` | `Server\AgentExecution\ActiveTask`, `Server\AgentExecution\EventConsumer` | Python runs the executor as an asyncio task. PHP runs it in a **Fiber**: each `enqueueEvent()` suspends it, the `EventConsumer` checks, saves and publishes the event, then it resumes. `TaskCancelledException` stands in for `asyncio.CancelledError`. **This is the biggest place PHP has to differ.** See [Running agents in PHP](concepts/running-agents-in-php.md). |
| `…/active_task_registry.py` | `Server\AgentExecution\ActiveTaskRegistry` | Builds a fresh `ActiveTask` per request: a PHP process usually serves one request, so what must be shared lives in the `QueueManager`. |
| — | `Server\AgentExecution\TaskRunner`, `InlineTaskRunner` | **PHP-only.** Decides where `execute()` runs (inline in the request here; on a queue worker in the Laravel bridge), holds the per-task run lease, and runs work deferred until after the response. |
| `server/events/event_queue.py` `EventQueue` | `Server\Events\EventQueue` (interface) + `InMemoryEventQueue` | `enqueueEvent(Event)`. |
| `…/queue_manager.py` `QueueManager`, `InMemoryQueueManager` | same, plus `PdoQueueManager` | **Different shape:** a per-task append-only event log readable from a sequence number, plus the cancel flag and run leases, so separate PHP processes can stream and cancel the same task. `PdoQueueManager` uses SQLite/PostgreSQL/MySQL; the Laravel bridge adds Redis. |
| `…/event_consumer.py`, `event_queue_v2.py` | not ported | Python's legacy consumer and its asyncio queue plumbing. The v2 consumer lives in `active_task.py` (see above). `PublishedEvent` is the PHP form of the `(event, updated_task)` pair Python queues for subscribers. |
| `server/tasks/task_store.py` `TaskStore` | `Server\Tasks\TaskStore` (interface) | `save / get / list / delete`, each taking a `ServerCallContext`. |
| `…/inmemory_task_store.py`, `copying_task_store.py` | `InMemoryTaskStore`, `CopyingTaskStore` | In-memory only lives as long as the PHP process: use it in tests and long-running servers, not under PHP-FPM. |
| `…/database_task_store.py` (SQLAlchemy) | `Server\Tasks\PdoTaskStore` | core; plain PDO, supports pgsql/mysql/sqlite, same owner scoping, ordering and page tokens. The Laravel bridge runs it on the app's connection and ships the migration. |
| `…/task_manager.py`, `result_aggregator.py` | same | |
| `…/task_updater.py` `TaskUpdater` | `Server\Tasks\TaskUpdater` | `updateStatus`, `addArtifact`, `complete`, `failed`, `reject`, `submit`, `startWork`, `cancel`, `requiresInput`, `requiresAuth`, `newAgentMessage`. |
| `…/push_notification_config_store.py` + inmemory/database | `Server\Tasks\PushNotificationConfigStore` + `InMemoryPushNotificationConfigStore`, `PdoPushNotificationConfigStore` | `getInfoForDispatch()` is part of the interface (Python gives it a warning default). The PDO store takes optional `encrypt`/`decrypt` closures (Python: a Fernet key). |
| `…/push_notification_sender.py`, `base_push_notification_sender.py` | `Server\Tasks\PushNotificationSender`, `BasePushNotificationSender` | Sends through the client's HttpSender layer (Guzzle, Symfony or any PSR-18 client). Adds the `Authorization` header from the config's `authentication`, retries with backoff, a stable `X-A2A-Notification-Id`, address pinning and no redirects; SSRF screening is on by default. |
| `server/request_handlers/request_handler.py` `RequestHandler` | `Server\RequestHandlers\RequestHandler` (interface) | `onGetTask`, `onListTasks`, `onCancelTask`, `onMessageSend`, `onMessageSendStream` (a Generator), `onSubscribeToTask` (a Generator; may yield `null` keep-alive ticks), `on{Create,Get,List,Delete}TaskPushNotificationConfig`, `onGetExtendedAgentCard`, and PHP's `runBackgroundWork()`. |
| `…/default_request_handler_v2.py` `DefaultRequestHandler(V2)` | `Server\RequestHandlers\DefaultRequestHandler` | We port only V2. Python keeps the older `LegacyRequestHandler` around for its own compatibility; we have no old users to keep working, so we skip it. Differences are listed on [Conformance](reference/conformance.md). |
| `…/response_helpers.py` | `Utils\ErrorHandlers`, `Server\Routes\Common` | Error envelopes come from phase 1's `ErrorHandlers`; `Common` holds the JSON helpers. |
| `…/grpc_handler.py` | `Server\RequestHandlers\GrpcHandler` | grpc package, later. |
| `server/context.py` `ServerCallContext` | `Server\ServerCallContext` | `user`, `state`, `requestedExtensions`, `tenant`, plus `activatedExtensions` (echoed in the `A2A-Extensions` response header). |
| `server/owner_resolver.py`, `id_generator.py` | same | Owner = which user a task belongs to. This is how "not found" and "not allowed" stay the same. |
| `server/routes/jsonrpc_dispatcher.py`, `rest_dispatcher.py` | `Server\Routes\JsonRpcDispatcher`, `RestDispatcher` | **PSR-15 `RequestHandlerInterface`s.** Any framework can mount them. |
| `server/routes/{jsonrpc,rest,agent_card}_routes.py` `create_*_routes()` | `Server\Routes\Routes::jsonRpc()`, `::rest()`, `::agentCard()`, `::router()` | PSR-15 handlers. `AgentCardHandler` adds the caching headers the spec recommends and takes a `signer` that signs a copy of the card once per process (Python has no signing hook on the route: you wrap the signer in your own async `card_modifier`). `Router` combines all three for plain-PHP front controllers. Both dispatchers echo activated extensions in `A2A-Extensions`. |
| — | `Server\Routes\ResponseEmitter`, `ServerRequestFactory`, `Sse\SseStream` | **PHP-only.** Emit responses from plain PHP (flushing SSE per event, noticing disconnects, running background work after the response); build the PSR-7 request from globals; a PSR-7 body that streams SSE from a generator. |
| `server/routes/fastapi_routes.py` `add_a2a_routes_to_fastapi` | **Laravel bridge:** `Route::a2a(...)` macro (`A2A\Laravel\Routing\A2ARoutes`) | The framework glue lives in the bridge package, not in core. |
| `server/routes/common.py` `ServerCallContextBuilder`, `DefaultServerCallContextBuilder` | same | Builds the context from the PSR-7 request: headers, `A2A-Extensions`, and the user your auth middleware stored in the `a2a.user` request attribute. |

### Client

Built in phase 2. Namespace `A2A\Client`.

| Python (`src/a2a/client/…`) | PHP | Notes |
|---|---|---|
| `client.py` `Client`, `ClientConfig`, `ClientCallContext` | `Client` (abstract), `ClientConfig`, `ClientCallContext` | `sendMessage()` / `subscribe()` return a **Generator** of `StreamResponse`, PHP's answer to Python's async iterator. `ClientConfig::$httpClient` replaces `httpx_client` and takes Guzzle, Symfony HttpClient, any PSR-18 client or an `HttpSender`. No `grpc_channel_factory` yet. |
| `base_client.py` `BaseClient` | `BaseClient` | Same config handling and interceptor semantics; `agentCard()` exposes the current card. |
| `client_factory.py` `ClientFactory`, `create_client()`, `minimal_agent_card()` | `ClientFactory`, **`ClientFactory::createClient()`**, `ClientFactory::minimalAgentCard()` | PHP can't have a static and an instance method both named `create()`, so Python's module-level `create_client()` is the static `createClient()`. Instance `create()` / `createFromUrl()` / `register()` as in Python. A card that only offers A2A 0.3 raises `A2AClientError` until the 0.3 layer lands. |
| `card_resolver.py` `A2ACardResolver`, `parse_agent_card()` | `A2ACardResolver`, `A2ACardResolver::parseAgentCard()` | Includes the pre-1.0 card field mapping. `http_kwargs` → `['headers' => [...], 'timeout' => ...]`. |
| `interceptors.py` `ClientCallInterceptor`, `BeforeArgs`, `AfterArgs` | same names | Interface instead of ABC; methods are synchronous. |
| `auth/credentials.py`, `auth/interceptor.py` | `Auth\CredentialService` (interface), `Auth\InMemoryContextCredentialStore`, `Auth\AuthInterceptor` | |
| `errors.py` | `Errors\A2AClientError`, `Errors\A2AClientTimeoutError`, `Errors\AgentCardResolutionError` | All extend `A2A\Utils\Errors\A2AError`, as in Python. |
| `service_parameters.py` `ServiceParametersFactory`, `with_a2a_extensions()` | `ServiceParametersFactory`, `ServiceParameters::withA2aExtensions()` | Updates return the new array (PHP arrays are values) instead of mutating a dict. |
| `transports/base.py` `ClientTransport` | `Transports\ClientTransport` (interface) | |
| `transports/jsonrpc.py`, `transports/rest.py` | `Transports\JsonRpcTransport`, `Transports\RestTransport` | Take an `HttpSender` where Python takes an `httpx.AsyncClient`. Every request carries `A2A-Version: 1.0` (Python sets it on the factory's shared client). |
| `transports/tenant_decorator.py` `TenantTransportDecorator` | `Transports\TenantTransportDecorator` | |
| `transports/http_helpers.py` | `Transports\HttpHelpers` (internal) + `Sse\EventStreamParser` | The SSE parser is incremental (bytes arrive in arbitrary chunks); same rules as Python's `parse_sse_stream()`. |
| `transports/grpc.py` | — | After 1.0. |
| (httpx) | `Http\HttpSender` + `Psr18HttpSender`, `GuzzleHttpSender`, `SymfonyHttpSender`, `HttpSenderFactory` | PHP-only: PSR-18 can't stream, so sending goes through this small interface. Guzzle and Symfony stream live; other PSR-18 clients get the buffered body. Guzzle stream requests go out as HTTP/1.0 (PHP's `http://` wrapper holds chunked HTTP/1.1 bodies back until they end). |

### Compat, tooling, tests

| Python | PHP | Notes |
|---|---|---|
| `compat/v0_3/*` | `Compat\V0_3\*` | Phase 5. Translates 0.3 JSON to and from 1.0 types at the dispatcher edge. |
| `a2a_db_cli.py`, `migrations/` (alembic) | `bin/a2a-db` (schema SQL) + Laravel migrations in the bridge | |
| `samples/hello_world_agent.py`, `cli.py` | `examples/hello-world/server.php`, `examples/cli.php` | Line-for-line port. It is the first thing a Python user looks for. |
| `tck/sut_agent.py` | `tck/sut-agent.php` | The system-under-test agent the A2A TCK runs against in CI. |
| `itk/` | `itk/` | Cross-SDK tests: Python client ↔ PHP server and PHP client ↔ Python server. |
| `tests/` (pytest, 118 files) | `tests/` (PHPUnit), same folder layout | Port test by test. The Python tests are our spec for behaviour the written spec leaves open. |

## Python features that don't carry over directly

| Python | Why it's different in PHP | PHP answer |
|---|---|---|
| `asyncio` tasks, `async for` | PHP-FPM handles one request at a time; there is no event loop | `Generator`s for streams; a `TaskRunner` abstraction for background execution (see [architecture.md](architecture.md)) |
| `asyncio.CancelledError` on cancel | A running PHP call can't be interrupted from outside | Cooperative: a `CancellationToken` in `RequestContext` that executors check (`$context->isCancelled()`), and the store's cancel flag across processes |
| Empty `Struct` on the wire | The pure-PHP protobuf runtime writes an empty `Struct` (e.g. `metadata`) as `[]`, not `{}` | Known runtime quirk. Leave `metadata` unset rather than empty |
| Pydantic models | — | `readonly` classes for SDK-side config objects; protobuf classes for wire types |
| `httpx` | PSR-18 can only return complete responses, so it can't stream SSE | `Client\Http\HttpSender`: Guzzle and Symfony HttpClient stream live; any other PSR-18 client works with buffered streams |
