# A2A PHP SDK

A PHP implementation of the [A2A (Agent2Agent) protocol](https://a2a-protocol.org/latest/specification/), built in the **same shape as the official [Python SDK](https://github.com/a2aproject/a2a-python)**. If you know the Python SDK, you already know this one: the same classes in the same places, with `camelCase` methods.

**Targets A2A 1.0**, the current spec. Types are generated from the official `a2a.proto`, and the server passes the official A2A test kit (TCK) at the MUST, SHOULD and MAY levels over JSON-RPC and HTTP+JSON, checked in CI on every push, both as plain PHP and as a Laravel app with queued execution. Works with any framework (PSR-7/15/17/18), with a Laravel bridge.

[![CI](https://github.com/praveendias1180/a2a-php/actions/workflows/ci.yml/badge.svg)](https://github.com/praveendias1180/a2a-php/actions/workflows/ci.yml)
[![A2A TCK](https://img.shields.io/badge/A2A_TCK-MUST_137%2F137-brightgreen)](https://praveendias1180.github.io/a2a-php/reference/conformance/)

📖 **Documentation: <https://praveendias1180.github.io/a2a-php/>**

> **Status: early development (phase 7 of 7).** The wire types, utilities, client, server, Laravel bridge, push notifications, Agent Card signing, extensions and A2A 0.3 compatibility are done. The server passes the official A2A TCK (plain PHP, and a Laravel app running executors on queue workers), and the SDK interoperates with the official Python SDK in both directions, including card signatures, and with the last A2A 0.3 release of the Python SDK. 1.0 is next. The API may still change before 1.0. See the [roadmap](#roadmap).

## Packages

| Package | What it is |
|---|---|
| [`praveendias1180/a2a-php`](https://packagist.org/packages/praveendias1180/a2a-php) | The SDK itself. Works with any framework, built on PSR-7/15/17/18. This repo. |
| [`praveendias1180/a2a-laravel`](https://github.com/praveendias1180/a2a-laravel) | Laravel bridge: `Route::a2a()`, queued execution on your workers with live SSE streaming (Redis Streams or the database), owner-scoped storage, artisan commands. Lives in [`packages/laravel`](packages/laravel) and is published as a read-only split. |

Requires PHP 8.2+.

```bash
composer require praveendias1180/a2a-php
```

## Protocol support

| A2A spec | Status |
|---|---|
| 1.0 | **supported since v0.1.0.** The server passes the official A2A TCK at the MUST, SHOULD and MAY levels over JSON-RPC and HTTP+JSON; the client interoperates with the official Python SDK in both directions. |
| 0.3 | **supported through a compatibility layer** (`Compat\V0_3`, like Python's `a2a.compat.v0_3`): the client talks to 0.3 agents automatically, and servers answer 0.3 clients with `enableV03Compat` (Laravel: `A2A_V0_3_COMPAT=true`). Tested both ways against a2a-sdk 0.3.26. See [the guide](https://praveendias1180.github.io/a2a-php/guides/a2a-0-3/). |

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

## Serve an agent

The SDK's [`examples/hello-world`](examples/hello-world) (a port of the Python SDK's `hello_world_agent.py`, run in CI against the official Python client and the A2A TCK):

```php
final class HelloExecutor implements AgentExecutor
{
    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        $userMessage = $context->message();
        $taskId = $context->taskId();
        $contextId = $context->contextId();
        if ($userMessage === null || $taskId === null || $contextId === null) {
            return;
        }

        $eventQueue->enqueueEvent(new Task([
            'id' => $taskId,
            'context_id' => $contextId,
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_SUBMITTED]),
            'history' => [$userMessage],
        ]));

        $updater = new TaskUpdater($eventQueue, $taskId, $contextId);
        $updater->startWork($updater->newAgentMessage([new Part(['text' => 'Processing your question...'])]));

        $reply = $this->parseInput($context->getUserInput());
        sleep(1);

        // Python tracks running tasks in a set; a PHP request can be
        // cancelled from another process, so ask the context instead.
        if ($context->isCancelled()) {
            return;
        }

        $updater->addArtifact([new Part(['text' => $reply])], name: 'response', lastChunk: true);
        $updater->complete();
    }

    // cancel() and parseInput(): see examples/hello-world/HelloExecutor.php
}
```

Serving it (`examples/hello-world/server.php`, with the card defined above that point):

```php
$pdo = new PDO('sqlite:' . (getenv('A2A_DB') ?: sys_get_temp_dir() . '/a2a-php-hello-world.sqlite'));
$handler = new DefaultRequestHandler(
    agentExecutor: new HelloExecutor(),
    taskStore: new PdoTaskStore($pdo),
    agentCard: $agentCard,
    queueManager: new PdoQueueManager($pdo),
);

$router = Routes::router($handler, $agentCard, jsonRpcPath: '/a2a/jsonrpc', restPrefix: '/a2a/rest');
(new ResponseEmitter($handler))->emit($router->handle($request));
```

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:41241 examples/hello-world/server.php
```

Every PHP request is its own process, so tasks and events live in a shared database (SQLite here). More in [Your first agent](https://praveendias1180.github.io/a2a-php/get-started/first-agent/).

### In Laravel

```bash
composer require praveendias1180/a2a-laravel
php artisan vendor:publish --tag=a2a-migrations && php artisan migrate
php artisan a2a:make-executor Hello
```

```php
// routes/api.php (from examples/laravel)
Route::a2a('/a2a', agentCard: HelloAgentCard::class, executor: HelloExecutor::class);
```

That mounts the Agent Card, JSON-RPC and HTTP+JSON. Set `A2A_RUNNER=queued` and the executor runs on your queue workers while the web request streams its events live. The [Laravel guide](https://praveendias1180.github.io/a2a-php/guides/laravel/) has the rest.

## Roadmap

| # | Phase | Done when |
|---|---|---|
| 0 | Skeleton, CI, generated types | ✅ |
| 1 | Types + utilities (errors, helpers, validators) | ✅ |
| 2 | Client (JSON-RPC + REST + SSE) | ✅ |
| 3 | Server core | ✅ |
| 4 | Laravel bridge | ✅ |
| 5 | Push notifications, card signing, extensions (the PDO stores arrived early, in phase 3) | ✅ |
| 6 | v0.3 compatibility | ✅ |
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
