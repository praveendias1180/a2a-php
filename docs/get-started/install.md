# Install

## Requirements

- PHP **8.2** or newer
- Composer
- For the client: any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (Guzzle, Symfony HttpClient, …). Laravel already ships one.

## The SDK

```bash
composer require praveendias1180/a2a-php
```

!!! note "Versions"
    From 1.0 the SDK follows [Semantic Versioning](../reference/backward-compatibility.md): `^1.0` gets you every 1.x fix and feature without breaking changes. Install `praveendias1180/a2a-php` and `praveendias1180/a2a-laravel` at the same major version.

### Faster protobuf (optional)

The wire types run on the pure-PHP protobuf runtime, with no extension needed. For heavy traffic, install the C extension; the SDK uses it automatically:

```bash
pecl install protobuf
```

## Laravel

```bash
composer require praveendias1180/a2a-laravel
php artisan vendor:publish --tag=a2a-migrations
php artisan migrate
```

The service provider registers itself through package discovery. Publish the config too (`--tag=a2a-config`) to change the runner, queue or storage. Laravel 12 and 13 are tested (11 is allowed, but Composer blocks its releases for security advisories). See the [Laravel guide](../guides/laravel.md).

## Check it works

```php
use A2A\Types\Task;

$task = new Task();
$task->mergeFromJsonString('{"id":"task-1","status":{"state":"TASK_STATE_COMPLETED"}}');

echo $task->serializeToJsonString();
// {"id":"task-1","status":{"state":"TASK_STATE_COMPLETED"}}
```
