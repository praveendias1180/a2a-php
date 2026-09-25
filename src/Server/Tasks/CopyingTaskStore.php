<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\ServerCallContext;
use A2A\Types\ListTasksRequest;
use A2A\Types\ListTasksResponse;
use A2A\Types\Task;

/**
 * Wraps a store so callers always get and hand over copies, and can't
 * change stored tasks by mutating an object they hold.
 *
 * Mirrors a2a-python: CopyingTaskStoreAdapter in
 * src/a2a/server/tasks/copying_task_store.py
 */
final class CopyingTaskStore implements TaskStore
{
    public function __construct(private readonly TaskStore $store) {}

    public function save(Task $task, ServerCallContext $context): void
    {
        $this->store->save(self::copy($task), $context);
    }

    public function get(string $taskId, ServerCallContext $context): ?Task
    {
        $task = $this->store->get($taskId, $context);

        return $task === null ? null : self::copy($task);
    }

    public function list(ListTasksRequest $params, ServerCallContext $context): ListTasksResponse
    {
        $response = $this->store->list($params, $context);
        $copy = new ListTasksResponse();
        $copy->mergeFrom($response);

        return $copy;
    }

    public function delete(string $taskId, ServerCallContext $context): void
    {
        $this->store->delete($taskId, $context);
    }

    private static function copy(Task $task): Task
    {
        $copy = new Task();
        $copy->mergeFrom($task);

        return $copy;
    }
}
