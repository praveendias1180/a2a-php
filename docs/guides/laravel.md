# Laravel: install and first agent

The Laravel bridge (`praveendias1180/a2a-laravel`) turns the SDK into a few lines of Laravel: a route macro, an executor class resolved from the container, and config for queues and storage. All protocol behaviour stays in the core SDK, so a Laravel agent passes the same [A2A TCK](../reference/conformance.md) as a plain-PHP one.

Laravel 12 and 13 on PHP 8.2+ (tested in CI). Laravel 11 is allowed by the constraints but untested: Composer blocks every Laravel 11 release for open security advisories.

## Install

```bash
composer require praveendias1180/a2a-laravel
php artisan vendor:publish --tag=a2a-migrations
php artisan migrate
php artisan vendor:publish --tag=a2a-config   # optional: config/a2a.php
```

The migration creates the task table, the event log and the push-config table on your default connection (change it with `a2a.storage.connection`).

## An agent in three files

**1. The executor.** `php artisan a2a:make-executor Hello` writes `app/A2A/HelloExecutor.php`:

```php
--8<-- "examples/laravel/HelloExecutor.php:executor"
```

**2. The card.** A class implementing `AgentCardProvider` (or an array in `config/a2a.php`):

```php
--8<-- "examples/laravel/HelloAgentCard.php:card"
```

**3. The route.** In `routes/api.php` or any routes file:

```php
--8<-- "examples/laravel/routes.php:route"
```

That registers:

| Route | Name | What |
|---|---|---|
| `GET /.well-known/agent-card.json` | `a2a.default.well-known` | the card, for discovery (pass `wellKnown: false` to skip) |
| `GET /a2a/.well-known/agent-card.json` | `a2a.default.card` | the same card under the prefix |
| `POST /a2a/jsonrpc` | `a2a.default.jsonrpc` | the JSON-RPC binding |
| `GET/POST/DELETE /a2a/rest/...` | `a2a.default.rest` | the HTTP+JSON binding |

Leave `supported_interfaces` out of the card and the bridge fills them in from these routes. CSRF protection is left off the protocol routes: A2A clients are programs, not browsers.

Check it:

```bash
php artisan a2a:card        # prints the card as served and validates it
```

## Authentication and task owners

Add middleware to the protocol routes. The card routes stay public, as discovery requires:

```php
Route::a2a('/a2a', agentCard: HelloAgentCard::class, executor: HelloExecutor::class)
    ->middleware('auth:sanctum');
```

Or let the card drive it. Map each security scheme your card declares in `securityRequirements` to middleware:

```php
// config/a2a.php
'security_schemes' => [
    'bearer' => 'auth:sanctum',
],
```

Tasks belong to the authenticated user (the auth identifier of `a2a.guard`, the default guard if null). Another user asking for your task gets "task not found", exactly as if it did not exist. Unauthenticated callers share one scope.

## Several agents

Name them, and give each its own prefix:

```php
Route::a2a('/support', agentCard: SupportCard::class, executor: SupportExecutor::class, agent: 'support');
Route::a2a('/billing', agentCard: BillingCard::class, executor: BillingExecutor::class, agent: 'billing', wellKnown: false);
```

Only one agent can own `/.well-known/agent-card.json`; the others keep their card under their prefix. Agents can also live in `config/a2a.php` under `agents` and be mounted with `Route::a2a('/support', agent: 'support')`. Everything the routes need is stored as strings and arrays, so `php artisan route:cache` works.

## Calling other agents

```php
--8<-- "examples/laravel/CallAgent.php:call"
```

## Artisan commands

| Command | What |
|---|---|
| `a2a:make-executor Name` | writes `app/A2A/NameExecutor.php` |
| `a2a:card [agent]` | prints an agent's card as served and checks it (exit 1 when invalid) |
| `a2a:prune [--days=7]` | deletes finished tasks, their push configs and old database events (see [storage](laravel-storage.md)) |
| `a2a:tck --tck-dir=...` | runs the official A2A TCK against your app |

## Next

- [Queued execution](laravel-queues.md): run executors on queue workers (anything that takes more than a few seconds).
- [Streaming behind nginx and PHP-FPM](laravel-sse-nginx.md)
- [Storage and pruning](laravel-storage.md)
