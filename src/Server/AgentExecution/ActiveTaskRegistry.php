<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\Events\QueueManager;
use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\TaskManager;
use A2A\Server\Tasks\TaskStore;
use A2A\Types\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creates the ActiveTask for a request.
 *
 * Mirrors a2a-python: ActiveTaskRegistry in
 * src/a2a/server/agent_execution/active_task_registry.py. Python keeps one
 * live ActiveTask per task in its long-running process; a PHP process
 * usually serves one request, so this builds a fresh ActiveTask each time
 * and the QueueManager carries what must be shared between processes.
 */
final class ActiveTaskRegistry
{
    public function __construct(
        private readonly AgentExecutor $agentExecutor,
        private readonly TaskStore $taskStore,
        private readonly QueueManager $queueManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function create(
        string $taskId,
        ServerCallContext $callContext,
        ?string $contextId = null,
        ?Message $initialMessage = null,
    ): ActiveTask {
        return new ActiveTask(
            $this->agentExecutor,
            $taskId,
            new TaskManager($this->taskStore, $callContext, $taskId, $contextId, $initialMessage),
            $this->queueManager,
            $this->logger,
        );
    }
}
