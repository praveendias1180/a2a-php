<?php

declare(strict_types=1);

namespace App\A2A;

use A2A\Helpers\ProtoHelpers;
use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\Part;

/**
 * "long N": works for N seconds, publishing a progress artifact every 2 s
 * that names the process running it. Used by long-task-proof.php.
 */
final class LongTaskExecutor implements AgentExecutor
{
    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        $message = $context->message();
        if ($context->currentTask() === null && $message !== null) {
            $eventQueue->enqueueEvent(ProtoHelpers::newTaskFromUserMessage($message));
        }
        $updater = new TaskUpdater($eventQueue, (string) $context->taskId(), (string) $context->contextId());
        $updater->startWork();

        $seconds = preg_match('/^long (\d+)$/', $context->getUserInput(), $m) === 1 ? (int) $m[1] : 20;
        for ($elapsed = 2; $elapsed <= $seconds; $elapsed += 2) {
            sleep(2);
            $updater->addArtifact(
                [new Part(['text' => sprintf('%ds done, worker pid %d', $elapsed, getmypid())])],
                artifactId: 'progress',
                name: 'progress',
                append: $elapsed > 2,
            );
        }
        $updater->complete($updater->newAgentMessage([new Part(['text' => "finished after {$seconds}s in pid " . getmypid()])]));
    }

    public function cancel(RequestContext $context, EventQueue $eventQueue): void
    {
        (new TaskUpdater($eventQueue, (string) $context->taskId(), (string) $context->contextId()))->cancel();
    }
}
