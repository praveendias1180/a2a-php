<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\ServerCallContext;
use A2A\Types\ListTasksRequest;
use A2A\Types\ListTasksResponse;
use A2A\Types\Task;

/**
 * Persists tasks. Every call is scoped by the owner the store resolves from
 * the ServerCallContext, so one user never sees another user's tasks.
 *
 * Mirrors a2a-python: TaskStore in src/a2a/server/tasks/task_store.py
 */
interface TaskStore
{
    public function save(Task $task, ServerCallContext $context): void;

    public function get(string $taskId, ServerCallContext $context): ?Task;

    /**
     * Tasks matching the request's filters, newest status first, one page at
     * a time.
     */
    public function list(ListTasksRequest $params, ServerCallContext $context): ListTasksResponse;

    public function delete(string $taskId, ServerCallContext $context): void;
}
