<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\IdGenerator;
use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\TaskStore;
use A2A\Types\SendMessageRequest;
use A2A\Types\Task;

/**
 * The default builder. Optionally loads the tasks the message refers to
 * (`referenceTaskIds`) as related tasks.
 *
 * Mirrors a2a-python: SimpleRequestContextBuilder in
 * src/a2a/server/agent_execution/simple_request_context_builder.py
 */
final class SimpleRequestContextBuilder implements RequestContextBuilder
{
    public function __construct(
        private readonly bool $shouldPopulateReferredTasks = false,
        private readonly ?TaskStore $taskStore = null,
        private readonly ?IdGenerator $taskIdGenerator = null,
        private readonly ?IdGenerator $contextIdGenerator = null,
    ) {}

    public function build(
        ServerCallContext $context,
        ?SendMessageRequest $params = null,
        ?string $taskId = null,
        ?string $contextId = null,
        ?Task $task = null,
    ): RequestContext {
        $related = [];
        $message = $params?->getMessage();
        if ($this->taskStore !== null && $this->shouldPopulateReferredTasks && $message !== null) {
            foreach ($message->getReferenceTaskIds() as $referenceId) {
                $found = $this->taskStore->get($referenceId, $context);
                if ($found !== null) {
                    $related[] = $found;
                }
            }
        }

        return new RequestContext(
            callContext: $context,
            request: $params,
            taskId: $taskId,
            contextId: $contextId,
            currentTask: $task,
            relatedTasks: $related,
            taskIdGenerator: $this->taskIdGenerator,
            contextIdGenerator: $this->contextIdGenerator,
        );
    }
}
