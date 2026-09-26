# Upgrading

Find the version you're on, then work down the list. Each step only lists the things **you** might have to change. Everything else is in the [changelog](../project/changelog.md).

Always upgrade both packages together; they share a version number:

```bash
composer require praveendias1180/a2a-php:^1.0 praveendias1180/a2a-laravel:^1.0   # drop a2a-laravel if you don't use Laravel
```

## 0.3 → 1.0

1.0 is the stability release. The API didn't change shape, but its boundary is now explicit:

- **`@internal` classes.** Classes marked `@internal` are implementation details, and the [backward-compatibility promise](../reference/backward-compatibility.md) doesn't cover them. If your code uses one directly, switch to the public API before you rely on 1.x:

    | If you used | Use instead |
    |---|---|
    | `Client\Sse\EventStreamParser`, `SseEvent` | `Client::sendMessage()` / `subscribe()`, which parse the stream for you |
    | `Client\Http\HttpSenderFactory` | pass your HTTP client (Guzzle, Symfony HttpClient, any PSR-18 client, or an `HttpSender`) as `ClientConfig`'s `httpClient` |
    | `Server\AgentExecution\EventConsumer`, `ActiveTaskRegistry` | `DefaultRequestHandler` with a `TaskRunner` |
    | `Server\RequestHandlers\ResponseHelpers`, `Server\Routes\Common` | `Routes::jsonRpc()`, `Routes::rest()`, `ResponseEmitter` |
    | `Utils\Jcs` | `Utils\Signing::canonicalizeAgentCard()` |
    | `Compat\V0_3\*` (conversions, adapters, transports) | the `enableV03Compat` switch (server) or nothing at all (the client picks 0.3 automatically) |
    | Laravel `A2ARoutes`, `A2AController`, `LaravelUser`, `SerializedUser`, the console command classes | `Route::a2a()`, the `A2A` facade, `auth()->user()`, `php artisan a2a:*` |

- **Laravel:** `a2a-laravel` 1.0 requires `a2a-php` `^1.0`. There are no new migrations and no renamed config keys.

## 0.2 → 0.3

- **Custom push-config stores** must implement the new `PushNotificationConfigStore::getInfoForDispatch()`. The built-in stores already do.
- **Push URLs are checked by default** (private, loopback and unresolvable hosts are refused) when a config is created and before every delivery. For a local test webhook, pass `pushUrlValidator: null` or a `PushUrlValidator` with `allowedHosts`. In Laravel, set `a2a.push.allowed_hosts`.
- **Card signing** needs `firebase/php-jwt` (`composer require firebase/php-jwt`) if you use it; it's optional otherwise.
- **Laravel config:** new keys `signing`, `push` and `v0_3_compat`. If you published `config/a2a.php` earlier, copy them from the package's config file (the defaults are used when they're missing).
- **Core (non-Laravel) push notifications with PDO:** call `PdoPushNotificationConfigStore::createTable()` once, like the other PDO stores.
- **A2A 0.3 clients** can now reach your server if you turn on `enableV03Compat` (`A2A_V0_3_COMPAT=true` in Laravel). It's off by default.

## 0.1 → 0.2

- **Nothing to change in core code.** 0.2 adds the Laravel bridge (`praveendias1180/a2a-laravel`, new in 0.2) and makes `ResponseEmitter::streamSse()` public.
- The test-kit executor moved from `tck/sut-agent.php` to `tck/TckAgentExecutor.php`, if you copied it.

!!! note "Status codes"
    Since 0.1, the REST binding returns `TaskNotCancelableError` as HTTP **409**, which the official TCK requires. The current spec text says 400. Check the `TASK_NOT_CANCELABLE` ErrorInfo reason rather than the status code if you talk to several SDKs.

## The policy from here

- `1.x` follows SemVer. See [Backward compatibility](../reference/backward-compatibility.md) for what's covered.
- Deprecations are announced in a minor release, keep working, and are removed only in the next major.
- A new A2A spec version is added in a minor release. The previous spec version stays supported for at least one major version.
- PHP and Laravel versions are supported while they get upstream security fixes.
