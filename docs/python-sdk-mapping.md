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
| `utils/_jcs.py`, `signing.py` | `Utils\Jcs`, `Utils\Signing` | core (+ `suggest: web-token/jwt-library`) | RFC 8785 canonicalization + card JWS. |
| `utils/push_url_validator.py` | `Utils\PushUrlValidator` | core | SSRF guard: resolve DNS, reject private/loopback/link-local addresses. |
| `utils/version_validator.py` | `Utils\VersionValidator` | core | Reads `A2A-Version`. |
| `utils/telemetry.py` | `Utils\Telemetry` | core (`suggest: open-telemetry/api`) | No-op unless OTel is installed, same as Python. |
| `extensions/common.py` | `Extensions\*` | core | Parse `A2A-Extensions`, find extensions required by the card. |
| `auth/user.py` | `Auth\User`, `Auth\UnauthenticatedUser` | core | |

### Server

| Python | PHP | Notes |
|---|---|---|
| `server/agent_execution/agent_executor.py` `AgentExecutor` | `Server\AgentExecution\AgentExecutor` (interface) | `execute(RequestContext, EventQueue): void`, `cancel(RequestContext, EventQueue): void`. **The one interface users write.** |
| `…/context.py` `RequestContext` | `Server\AgentExecution\RequestContext` | `getUserInput()`, `taskId()`, `contextId()`, `currentTask()`, `relatedTasks()`, `configuration()`, `callContext()`, `requestedExtensions()`. |
| `…/request_context_builder.py`, `simple_request_context_builder.py` | same names | |
| `…/active_task.py` `ActiveTask`, `EventConsumer` | `Server\AgentExecution\ActiveTask` | Python runs this as an asyncio task. PHP runs it inline or through a `TaskRunner` (see [architecture.md](architecture.md)). **This is the biggest place PHP has to differ.** |
| `…/active_task_registry.py` | `Server\AgentExecution\ActiveTaskRegistry` | Per process. The cross-process version comes from the `QueueManager`. |
| `server/events/event_queue.py` `EventQueue` | `Server\Events\EventQueue` (interface) + `InMemoryEventQueue` | `enqueueEvent(Event)`. |
| `…/queue_manager.py` `QueueManager`, `InMemoryQueueManager` | same | Laravel bridge adds `RedisQueueManager`. |
| `…/event_consumer.py` | `Server\Events\EventConsumer` | Returns a `Generator` of events. |
| `server/tasks/task_store.py` `TaskStore` | `Server\Tasks\TaskStore` (interface) | `save / get / list / delete`, each taking a `ServerCallContext`. |
| `…/inmemory_task_store.py`, `copying_task_store.py` | same | |
| `…/database_task_store.py` (SQLAlchemy) | `Server\Tasks\PdoTaskStore` | core; plain PDO, supports pgsql/mysql/sqlite. The Laravel bridge adds `EloquentTaskStore` + migrations. |
| `…/task_manager.py`, `result_aggregator.py` | same | |
| `…/task_updater.py` `TaskUpdater` | `Server\Tasks\TaskUpdater` | `updateStatus`, `addArtifact`, `complete`, `failed`, `reject`, `submit`, `startWork`, `cancel`, `requiresInput`, `requiresAuth`, `newAgentMessage`. |
| `…/push_notification_config_store.py` + inmemory/database | `Server\Tasks\PushNotificationConfigStore` + `InMemory…`, `Pdo…` | |
| `…/push_notification_sender.py`, `base_push_notification_sender.py` | `Server\Tasks\PushNotificationSender`, `BasePushNotificationSender` | Sends through a PSR-18 client. |
| `server/request_handlers/request_handler.py` `RequestHandler` | `Server\RequestHandlers\RequestHandler` (interface) | `onGetTask`, `onListTasks`, `onCancelTask`, `onMessageSend`, `onMessageSendStream` (returns a Generator), `onSubscribeToTask` (Generator), `on{Create,Get,List,Delete}TaskPushNotificationConfig`, `onGetExtendedAgentCard`. |
| `…/default_request_handler_v2.py` `DefaultRequestHandler(V2)` | `Server\RequestHandlers\DefaultRequestHandler` | We port only V2. Python keeps the older `LegacyRequestHandler` around for its own compatibility; we have no old users to keep working, so we skip it. |
| `…/grpc_handler.py` | `Server\RequestHandlers\GrpcHandler` | grpc package, later. |
| `server/context.py` `ServerCallContext` | `Server\ServerCallContext` | `user`, `state`, `requestedExtensions`, `tenant`. |
| `server/owner_resolver.py`, `id_generator.py` | same | Owner = which user a task belongs to. This is how "not found" and "not allowed" stay the same. |
| `server/routes/jsonrpc_dispatcher.py`, `rest_dispatcher.py` | `Server\Routes\JsonRpcDispatcher`, `RestDispatcher` | **PSR-15 `RequestHandlerInterface`s.** Any framework can mount them. |
| `server/routes/{jsonrpc,rest,agent_card}_routes.py` `create_*_routes()` | `Server\Routes\Routes::jsonRpc()`, `::rest()`, `::agentCard()` | Return PSR-15 handlers keyed by path. |
| `server/routes/fastapi_routes.py` `add_a2a_routes_to_fastapi` | **Laravel bridge:** `Route::a2a(...)` macro | The framework glue lives in the bridge package, not in core. |
| `server/routes/common.py` `ServerCallContextBuilder` | same | Builds the context from the PSR-7 request (auth user, headers). |

### Client

| Python | PHP | Notes |
|---|---|---|
| `client/client.py` `Client`, `ClientConfig`, `ClientCallContext` | same | `sendMessage()` returns a **Generator** of `StreamResponse`, the same shape as Python's async iterator. |
| `client/base_client.py` `BaseClient` | same | |
| `client/client_factory.py` `ClientFactory`, `create_client`, `minimal_agent_card` | `ClientFactory`, `ClientFactory::create()`, `ClientFactory::minimalAgentCard()` | Picks the transport from the card's `supportedInterfaces` + our preferences. |
| `client/card_resolver.py` `A2ACardResolver` | same | Fetches `/.well-known/agent-card.json` and optionally checks the signature. |
| `client/transports/{base,jsonrpc,rest,grpc}.py` | `Client\Transports\{ClientTransport,JsonRpcTransport,RestTransport,GrpcTransport}` | PSR-18 client + PSR-17 factories. SSE is read by our own `Client\Sse\EventStreamParser`. |
| `client/transports/tenant_decorator.py` | same | |
| `client/interceptors.py`, `client/auth/*` | `ClientCallInterceptor`, `AuthInterceptor`, `CredentialService`, `InMemoryContextCredentialStore` | |
| `client/errors.py` | `A2AClientError`, `A2AClientTimeoutError`, `AgentCardResolutionError` | |
| `client/service_parameters.py` | same | Sets `A2A-Version` / `A2A-Extensions`. |

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
| `httpx` | — | PSR-18 (Guzzle / Symfony HttpClient / Laravel's client, whichever the user has) |
