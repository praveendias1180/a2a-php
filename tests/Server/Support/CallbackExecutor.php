<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Support;

use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Tasks\TaskUpdater;

/**
 * An executor whose execute()/cancel() are closures, so each test can script
 * exactly what the agent publishes.
 */
final class CallbackExecutor implements AgentExecutor
{
    public int $executeCalls = 0;

    public int $cancelCalls = 0;

    /** @var \Closure(RequestContext, EventQueue): void */
    private readonly \Closure $onExecute;

    /** @var \Closure(RequestContext, EventQueue): void */
    private readonly \Closure $onCancel;

    /**
     * @param (\Closure(RequestContext, EventQueue): void)|null $onExecute
     * @param (\Closure(RequestContext, EventQueue): void)|null $onCancel
     */
    public function __construct(?\Closure $onExecute = null, ?\Closure $onCancel = null)
    {
        $this->onExecute = $onExecute ?? static function (RequestContext $context, EventQueue $queue): void {
            $updater = new TaskUpdater($queue, (string) $context->taskId(), (string) $context->contextId());
            if ($context->currentTask() === null && $context->message() !== null) {
                $queue->enqueueEvent(\A2A\Helpers\ProtoHelpers::newTaskFromUserMessage($context->message()));
            }
            $updater->complete();
        };
        $this->onCancel = $onCancel ?? static function (RequestContext $context, EventQueue $queue): void {
            (new TaskUpdater($queue, (string) $context->taskId(), (string) $context->contextId()))->cancel();
        };
    }

    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        ++$this->executeCalls;
        ($this->onExecute)($context, $eventQueue);
    }

    public function cancel(RequestContext $context, EventQueue $eventQueue): void
    {
        ++$this->cancelCalls;
        ($this->onCancel)($context, $eventQueue);
    }
}
