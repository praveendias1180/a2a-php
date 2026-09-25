---
title: A2A PHP SDK
description: PHP SDK for the A2A (Agent2Agent) protocol, in the same shape as the official Python SDK.
---

# A2A PHP SDK

Build and call **[A2A](https://a2a-protocol.org/latest/specification/) agents in PHP**. The SDK has the same classes, in the same places, as the official [Python SDK](https://github.com/a2aproject/a2a-python). There is a Laravel bridge for queued, streaming agents.

**Targets A2A 1.0**, the current version of the spec.

!!! warning "Early development"
    The wire types, utilities and client are done; the client is tested against the official Python SDK's sample agent. The server is being built. **Don't use this in production yet.** Progress is on the [roadmap](project/roadmap.md).

<div class="grid cards" markdown>

-   :material-language-python: **Same shape as Python**

    ---

    `AgentExecutor`, `RequestContext`, `TaskUpdater`, `DefaultRequestHandler`, `ClientFactory`… The same names, with `camelCase` methods. If you know the Python SDK, you already know this one.

    [:octicons-arrow-right-24: The mapping](python-sdk-mapping.md)

-   :material-check-decagram: **Spec-exact on the wire**

    ---

    Types are generated from the official `a2a.proto` (v1.0.0), so the JSON on the wire is exactly what the spec defines. From phase 3, conformance is checked in CI with the official A2A test kit.

-   :simple-laravel: **Laravel bridge**

    ---

    `Route::a2a()`, queued execution on your workers, streaming over Redis and SSE, and Eloquent storage. PHP-FPM can't keep work running after a request ends, so the bridge moves long tasks to a queue.

    [:octicons-arrow-right-24: Running agents in PHP](concepts/running-agents-in-php.md)

-   :material-puzzle: **Works with any framework**

    ---

    The core is built only on PSR standards (PSR-7/15/17/18), so it runs under Symfony, Slim, Laravel or plain PHP. The Laravel bridge is a separate package.

</div>

## A taste

An agent that answers "hello", written the same way in both SDKs:

=== "Plain PHP"

    ```php
    final class HelloExecutor implements AgentExecutor
    {
        public function execute(RequestContext $context, EventQueue $eventQueue): void
        {
            $updater = new TaskUpdater($eventQueue, $context->taskId(), $context->contextId());
            $updater->startWork();
            $updater->addArtifact([new Part(['text' => 'Hello, ' . $context->getUserInput()])], name: 'response');
            $updater->complete();
        }

        public function cancel(RequestContext $context, EventQueue $eventQueue): void
        {
            (new TaskUpdater($eventQueue, $context->taskId(), $context->contextId()))->cancel();
        }
    }
    ```

=== "Laravel"

    ```php
    // app/A2A/HelloExecutor.php: the same class as the Plain PHP tab.
    // routes/api.php
    Route::a2a('/a2a', agentCard: HelloAgentCard::class, executor: HelloExecutor::class)
        ->middleware('auth:sanctum');
    ```

=== "Python"

    ```python
    class HelloExecutor(AgentExecutor):
        async def execute(self, context: RequestContext, event_queue: EventQueue) -> None:
            updater = TaskUpdater(event_queue, context.task_id, context.context_id)
            await updater.start_work()
            await updater.add_artifact([Part(text=f'Hello, {context.get_user_input()}')], name='response')
            await updater.complete()

        async def cancel(self, context: RequestContext, event_queue: EventQueue) -> None:
            await TaskUpdater(event_queue, context.task_id, context.context_id).cancel()
    ```

!!! tip "Pick a tab once"
    The **Plain PHP / Laravel / Python** tabs are linked across the whole site. Choose one and every page follows.

## Packages

| Package | Install |
|---|---|
| [`praveendias1180/a2a-php`](https://packagist.org/packages/praveendias1180/a2a-php): the SDK | `composer require praveendias1180/a2a-php` |
| [`praveendias1180/a2a-laravel`](https://github.com/praveendias1180/a2a-laravel): Laravel bridge | available from phase 4 |

Requires PHP 8.2 or newer.
