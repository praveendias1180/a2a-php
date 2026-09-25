# A2A PHP SDK

A PHP implementation of the [A2A (Agent2Agent) protocol](https://a2a-protocol.org/latest/specification/), built in the **same shape as the official [Python SDK](https://github.com/a2aproject/a2a-python)**. If you know the Python SDK, you already know this one: the same classes in the same places, with `camelCase` methods.

**Targets A2A 1.0**, the current spec. Types are generated from the official `a2a.proto`, and the server passes the official A2A test kit (TCK) at the MUST, SHOULD and MAY levels over JSON-RPC and HTTP+JSON, checked in CI on every push. Works with any framework (PSR-7/15/17/18), with a Laravel bridge.

[![CI](https://github.com/praveendias1180/a2a-php/actions/workflows/ci.yml/badge.svg)](https://github.com/praveendias1180/a2a-php/actions/workflows/ci.yml)
[![A2A TCK](https://img.shields.io/badge/A2A_TCK-MUST_137%2F137-brightgreen)](https://praveendias1180.github.io/a2a-php/reference/conformance/)

📖 **Documentation: <https://praveendias1180.github.io/a2a-php/>**

> **Status: early development (phase 4 of 7).** The wire types, utilities, client and server are done. The server passes the official A2A TCK, and the SDK interoperates with the official Python SDK in both directions. The Laravel bridge is next. The API may still change before 1.0. See the [roadmap](#roadmap).

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

## Call an agent (works today)

```php
use A2A\Client\ClientFactory;
use A2A\Helpers\ProtoHelpers;
use A2A\Types\{Role, SendMessageRequest};

$client = ClientFactory::createClient('https://agent.example.com'); // reads the Agent Card

$request = new SendMessageRequest(['message' => ProtoHelpers::newTextMessage('hello', role: Role::ROLE_USER)]);

foreach ($client->sendMessage($request) as $event) {   // streams (SSE) when the agent supports it
    if ($event->hasArtifactUpdate()) {
        echo ProtoHelpers::getArtifactText($event->getArtifactUpdate()->getArtifact()), PHP_EOL;
    }
}
```

JSON-RPC and HTTP+JSON, every A2A operation, tested in CI against the official Python SDK's sample agent. Works with Guzzle or Symfony HttpClient (live streaming) or any PSR-18 client. More in [Call an agent](https://praveendias1180.github.io/a2a-php/get-started/call-an-agent/).

## Serve an agent (coming in phase 3)

The server API, as in the Python SDK's hello-world sample:

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

## Roadmap

| # | Phase | Done when |
|---|---|---|
| 0 | Skeleton, CI, generated types | ✅ |
| 1 | Types + utilities (errors, helpers, validators) | ✅ |
| 2 | Client (JSON-RPC + REST + SSE) | ✅ |
| 3 | Server core | ✅ |
| 4 | Laravel bridge | the TCK passes against a Laravel app on php-fpm + nginx with queued execution |
| 5 | Push notifications, card signing, extensions (the PDO stores arrived early, in phase 3) | the TCK passes at the SHOULD level |
| 6 | v0.3 compatibility | a 0.3 client works against a 1.0 server |
| 7 | 1.0.0 | stable release |

Design notes: [Architecture](https://praveendias1180.github.io/a2a-php/architecture/). The class-by-class mapping to the Python SDK: [Python → PHP mapping](https://praveendias1180.github.io/a2a-php/python-sdk-mapping/).

## Development

```bash
composer install
composer test        # PHPUnit
composer analyse     # PHPStan (level max)
composer cs          # code style check
composer generate    # regenerate generated/ from a2a.proto (needs Node for npx)
```

Docs site (in `docs/`, built with [Zensical](https://zensical.org)):

```bash
pip install zensical
zensical serve       # http://127.0.0.1:8000/a2a-php/
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Apache-2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE). This project is not affiliated with the A2A Project or the Linux Foundation.
