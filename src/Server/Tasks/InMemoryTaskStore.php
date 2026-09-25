<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\OwnerResolver;
use A2A\Server\ServerCallContext;
use A2A\Types\ListTasksRequest;
use A2A\Types\ListTasksResponse;
use A2A\Types\Task;
use A2A\Utils\Constants;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\TaskUtils;

/**
 * Keeps tasks in a PHP array.
 *
 * Only useful where one process serves many requests (tests, RoadRunner,
 * Swoole, CLI workers). Under PHP-FPM or `php -S` each request is a new
 * process and starts with an empty store: use PdoTaskStore there.
 *
 * Mirrors a2a-python: InMemoryTaskStore in
 * src/a2a/server/tasks/inmemory_task_store.py
 */
final class InMemoryTaskStore implements TaskStore
{
    /** @var array<string, array<string, Task>> owner => task id => task */
    private array $tasks = [];

    /** @var \Closure(ServerCallContext): string */
    private readonly \Closure $ownerResolver;

    /**
     * @param (\Closure(ServerCallContext): string)|null $ownerResolver
     * @param bool $useCopying hand out copies so callers can't mutate stored tasks
     */
    public function __construct(?\Closure $ownerResolver = null, private readonly bool $useCopying = true)
    {
        $this->ownerResolver = $ownerResolver ?? OwnerResolver::default();
    }

    public function save(Task $task, ServerCallContext $context): void
    {
        $owner = ($this->ownerResolver)($context);
        $this->tasks[$owner][$task->getId()] = $this->maybeCopy($task);
    }

    public function get(string $taskId, ServerCallContext $context): ?Task
    {
        $owner = ($this->ownerResolver)($context);
        $task = $this->tasks[$owner][$taskId] ?? null;

        return $task === null ? null : $this->maybeCopy($task);
    }

    public function list(ListTasksRequest $params, ServerCallContext $context): ListTasksResponse
    {
        $owner = ($this->ownerResolver)($context);
        $tasks = array_values($this->tasks[$owner] ?? []);

        if ($params->getContextId() !== '') {
            $tasks = array_values(array_filter($tasks, static fn(Task $t): bool => $t->getContextId() === $params->getContextId()));
        }
        if ($params->getStatus() !== 0) {
            $tasks = array_values(array_filter($tasks, static fn(Task $t): bool => $t->getStatus()?->getState() === $params->getStatus()));
        }
        $after = $params->getStatusTimestampAfter();
        if ($after !== null) {
            $afterMicros = TaskListing::micros($after);
            $tasks = array_values(array_filter($tasks, static function (Task $t) use ($afterMicros): bool {
                $micros = TaskListing::statusMicros($t);

                return $micros !== null && $micros >= $afterMicros;
            }));
        }

        $tasks = TaskListing::sort($tasks);
        $total = count($tasks);

        $start = 0;
        if ($params->getPageToken() !== '') {
            $startId = TaskUtils::decodePageToken($params->getPageToken());
            $start = null;
            foreach ($tasks as $index => $task) {
                if ($task->getId() === $startId) {
                    $start = $index;
                    break;
                }
            }
            if ($start === null) {
                throw new InvalidParamsError(sprintf('Invalid page token: %s', $params->getPageToken()));
            }
        }

        $pageSize = $params->hasPageSize() && $params->getPageSize() > 0 ? $params->getPageSize() : Constants::DEFAULT_LIST_TASKS_PAGE_SIZE;
        $end = $start + $pageSize;
        $page = array_map(fn(Task $t): Task => $this->maybeCopy($t), array_slice($tasks, $start, $pageSize));

        return new ListTasksResponse([
            'tasks' => $page,
            'next_page_token' => $end < $total ? TaskUtils::encodePageToken($tasks[$end]->getId()) : '',
            'page_size' => $pageSize,
            'total_size' => $total,
        ]);
    }

    public function delete(string $taskId, ServerCallContext $context): void
    {
        $owner = ($this->ownerResolver)($context);
        unset($this->tasks[$owner][$taskId]);
        if (($this->tasks[$owner] ?? null) === []) {
            unset($this->tasks[$owner]);
        }
    }

    private function maybeCopy(Task $task): Task
    {
        if (!$this->useCopying) {
            return $task;
        }
        $copy = new Task();
        $copy->mergeFrom($task);

        return $copy;
    }
}
