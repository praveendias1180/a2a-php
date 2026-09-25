# A2A PHP SDK

A PHP implementation of the [A2A (Agent2Agent) protocol](https://a2a-protocol.org/latest/specification/), built in the **same shape as the official [Python SDK](https://github.com/a2aproject/a2a-python)**. If you know the Python SDK, you already know this one: the same classes in the same places, with `camelCase` methods.

> **Status: early development (phase 1 of 7).** The wire types are generated and tested. The client and server are not written yet. Don't use it in production. See the [roadmap](#roadmap).

## Packages

| Package | What it is |
|---|---|
| [`praveendias1180/a2a-php`](https://packagist.org/packages/praveendias1180/a2a-php) | The SDK itself. Works with any framework, built on PSR-7/15/17/18. This repo. |
| [`praveendias1180/a2a-laravel`](https://packagist.org/packages/praveendias1180/a2a-laravel) | Laravel bridge: routes, queued task runner, Redis streaming, Eloquent stores. Lives in [`packages/laravel`](packages/laravel) and is published as a read-only split. |

Requires PHP 8.2+.

```bash
composer require praveendias1180/a2a-php
```

## Protocol support

| A2A spec | Status |
|---|---|
| 1.0 | in progress; target of the first release |
| 0.3 | planned as a compatibility layer (phase 6) |

Types are generated from the official [`a2a.proto`](https://github.com/a2aproject/A2A/blob/v1.0.0/specification/a2a.proto) (v1.0.0, the same pin as the Python SDK). JSON on the wire is standard ProtoJSON.

## What it will look like

A server agent, as in the Python SDK's hello-world sample:

```php
use A2A\Server\AgentExecution\{AgentExecutor, RequestContext};
use A2A\Server\Events\EventQueue;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\Part;

final class HelloExecutor implements AgentExecutor
{
    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        $updater = new TaskUpdater($eventQueue, $context->taskId(), $context->contextId());
        $updater->startWork();
        $updater->addArtifact([new Part(['text' => 'Hello, ' . $context->getUserInput()])], name: 'response', lastChunk: true);
        $updater->complete();
    }

    public function cancel(RequestContext $context, EventQueue $eventQueue): void
    {
        (new TaskUpdater($eventQueue, $context->taskId(), $context->contextId()))->cancel();
    }
}
```

The wire types work today:

```php
use A2A\Types\Task;

$task = new Task();
$task->mergeFromJsonString('{"id":"task-1","status":{"state":"TASK_STATE_COMPLETED"}}');
echo $task->getStatus()->getState();   // 3 (TaskState::TASK_STATE_COMPLETED)
echo $task->serializeToJsonString();   // exact A2A v1.0 JSON
```

## Roadmap

| # | Phase | Done when |
|---|---|---|
| 0 | Skeleton, CI, generated types | ✅ |
| 1 | Types + utilities (errors, helpers, validators) | spec JSON examples round-trip; Python utils tests ported |
| 2 | Client (JSON-RPC + REST + SSE) | the full flow works against the official Python sample server |
| 3 | Server core | the [A2A TCK](https://github.com/a2aproject/a2a-tck) passes at the MUST level |
| 4 | Laravel bridge | the TCK passes against a Laravel app on php-fpm + nginx with queued execution |
| 5 | Push notifications, card signing, PDO stores, extensions | the TCK passes at the SHOULD level |
| 6 | v0.3 compatibility | a 0.3 client works against a 1.0 server |
| 7 | 1.0.0 | stable release |

Design notes: [`docs/architecture.md`](docs/architecture.md). The class-by-class mapping to the Python SDK: [`docs/python-sdk-mapping.md`](docs/python-sdk-mapping.md).

## Development

```bash
composer install
composer test        # PHPUnit
composer analyse     # PHPStan (level max)
composer cs          # code style check
composer generate    # regenerate generated/ from a2a.proto (needs Node for npx)
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Apache-2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE). This project is not affiliated with the A2A Project or the Linux Foundation.
