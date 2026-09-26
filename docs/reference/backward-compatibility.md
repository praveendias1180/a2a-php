# Backward compatibility

From **1.0.0**, both packages follow [Semantic Versioning](https://semver.org/). This page says exactly what that promise covers, so you know what you can rely on across `1.x`.

## The short version

- **Patch releases** (`1.0.x`) fix bugs. Nothing you use changes.
- **Minor releases** (`1.x.0`) add features. Code written against `1.0` keeps working.
- **Major releases** (`2.0.0`) may break things. Each one comes with an [upgrade guide](../guides/upgrading.md).
- `praveendias1180/a2a-php` and `praveendias1180/a2a-laravel` are **released together with the same version number**. Require the same major version of both, e.g. `^1.0` and `^1.0`.

## What the promise covers

| Area | Covered |
|---|---|
| **Public classes, interfaces and enums** | Every class in `src/` and `packages/laravel/src/` that isn't marked `@internal`. Names, namespaces, and public method signatures don't change within `1.x`. New optional parameters may be added at the end of a method's parameter list |
| **Interfaces you implement** | `AgentExecutor`, `TaskStore`, `QueueManager`, `TaskRunner`, `PushNotificationConfigStore`, `PushNotificationSender`, `RequestContextBuilder`, `ServerCallContextBuilder`, `IdGenerator`, `EventQueue`, `ClientCallInterceptor`, `CredentialService`, `HttpSender`, `User`, `AgentCardProvider`. No new abstract methods are added to these in `1.x`, so your implementations keep working |
| **Exceptions** | The error classes, their hierarchy (`A2AError` and its children, `A2AClientError`, `SignatureVerificationError`), and which error each situation raises |
| **Laravel configuration** | The keys in `config/a2a.php` and the `A2A_*` environment variables. New keys get defaults, so a published config file from `1.0` keeps working |
| **Laravel surface** | `Route::a2a()`, the `A2A` facade, the artisan command names and options, and the published migration's tables and columns. New migrations are additive |
| **Database schema** | The tables used by `PdoTaskStore`, `PdoQueueManager` and `PdoPushNotificationConfigStore`. Changes within `1.x` only add tables, columns or indexes, and ship as migrations |
| **Wire behaviour** | What the SDK sends and accepts over JSON-RPC and HTTP+JSON for A2A **1.0**, and for **0.3** when the compatibility switch is on. The server keeps passing the official A2A TCK MUST level (see [Conformance](conformance.md)) |
| **Supported versions** | PHP 8.2+ and Laravel 12/13 for all of `1.x`. Dropping a PHP or Laravel version that is still under upstream security support needs a major release |

## What it doesn't cover

- **Anything marked `@internal`** in its docblock. These are implementation details (for example `EventConsumer`, the SSE parser, the 0.3 conversion classes and the Laravel controller). They can change in any release. They are left out of the [API reference](api.md). The one exception: `ActiveTaskRegistry` is used by the Laravel bridge, so it stays compatible within `1.x`, in case you run bridge `1.0` with core `1.1`.
- **The layout of generated code.** The classes in `A2A\Types` (and `A2A\Compat\V0_3\Types`) are generated from the official `a2a.proto`. Their class names, getters and setters follow the proto and stay stable. The metadata classes (`GPBMetadata`, `Meta`), internal properties and file layout don't.
- **The A2A specification itself.** When the A2A Project releases a new spec version, a minor release adds support for it. If the spec removes or changes something, the SDK follows the spec, with a deprecation period where the spec allows one.
- **Behaviour that was a bug.** If the SDK doesn't do what the spec or these docs say, a fix is a patch release, even if some code relied on the bug. Changes like this are called out in the changelog.
- **Log messages, exception message text, and the exact timing** of retries, keep-alives and polling.
- **`@internal` test helpers** and anything under `tests/`, `tck/`, `scripts/` and `examples/`. The examples are kept working, but they're examples.
- **Classes you extend.** Most classes are `final`. The few open ones (`BaseClient`, `DefaultServerCallContextBuilder`, `A2AManager` and the exceptions) are meant to be extended, but protected members may change in minor releases. Prefer composition where you can.

## Deprecations

Something that will go away in `2.0` is first marked `@deprecated` in a minor release, with the replacement named in the docblock and the changelog. It keeps working until `2.0`.

## Where this SDK deliberately differs from the spec text

- `TaskNotCancelableError` is sent as **HTTP 409** over REST. That's what the official TCK and its bundled copy of the v1.0.0 spec require. The current spec text and the Python SDK use `400`. If the TCK changes, we'll follow in a minor release and note it in the changelog.

## Documentation versions

The docs site describes the **latest release**. It's rebuilt from the newest `v*` tag, never from unreleased code on `main`. There's one version of the site while there's one major version. Side-by-side versions (`1.x`, `2.x`, `dev`) will arrive with `2.0` or with Zensical's native versioning, whichever comes first. The tool available today (the `mike` fork) would move every page under a version folder and break existing links.
