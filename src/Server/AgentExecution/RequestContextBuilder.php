<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\ServerCallContext;
use A2A\Types\SendMessageRequest;
use A2A\Types\Task;

/**
 * Builds the RequestContext handed to the executor.
 *
 * Mirrors a2a-python: RequestContextBuilder in
 * src/a2a/server/agent_execution/request_context_builder.py
 */
interface RequestContextBuilder
{
    public function build(
        ServerCallContext $context,
        ?SendMessageRequest $params = null,
        ?string $taskId = null,
        ?string $contextId = null,
        ?Task $task = null,
    ): RequestContext;
}
