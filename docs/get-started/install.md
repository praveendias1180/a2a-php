# Install

## Requirements

- PHP **8.2** or newer
- Composer
- For the client: any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (Guzzle, Symfony HttpClient, …). Laravel already ships one.

## The SDK

```bash
composer require praveendias1180/a2a-php
```

!!! note "Pre-1.0"
    Versions are `0.x` until 1.0, so minor releases may still change the API. Pin a minor version (`^0.1`) and read the [changelog](../project/changelog.md) before upgrading.

### Faster protobuf (optional)

The wire types run on the pure-PHP protobuf runtime, with no extension needed. For heavy traffic, install the C extension; the SDK uses it automatically:

```bash
pecl install protobuf
```

## Laravel

The Laravel bridge arrives in phase 4 ([roadmap](../project/roadmap.md)). It will be:

```bash
composer require praveendias1180/a2a-laravel
```

The service provider registers itself through package discovery.

## Check it works

```php
use A2A\Types\Task;

$task = new Task();
$task->mergeFromJsonString('{"id":"task-1","status":{"state":"TASK_STATE_COMPLETED"}}');

echo $task->serializeToJsonString();
// {"id":"task-1","status":{"state":"TASK_STATE_COMPLETED"}}
```
