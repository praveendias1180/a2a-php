<?php

declare(strict_types=1);

namespace App\A2A;

use A2A\Helpers\ProtoHelpers;
use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\Part;

// --8<-- [start:executor]
// What `php artisan a2a:make-executor Hello` generates, with a reply filled in.
final class HelloExecutor implements AgentExecutor
{
    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        $message = $context->message();
        if ($context->currentTask() === null && $message !== null) {
            $eventQueue->enqueueEvent(ProtoHelpers::newTaskFromUserMessage($message));
        }

        $updater = new TaskUpdater($eventQueue, (string) $context->taskId(), (string) $context->contextId());
        $updater->startWork();
        $updater->addArtifact([new Part(['text' => 'Hello, ' . $context->getUserInput()])], name: 'response', lastChunk: true);
        $updater->complete();
    }

    public function cancel(RequestContext $context, EventQueue $eventQueue): void
    {
        (new TaskUpdater($eventQueue, (string) $context->taskId(), (string) $context->contextId()))->cancel();
    }
}
// --8<-- [end:executor]
