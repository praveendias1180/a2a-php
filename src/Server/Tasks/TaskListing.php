<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Types\Task;
use Google\Protobuf\Timestamp;

/**
 * Sorting and timestamp helpers shared by the task stores.
 *
 * @internal
 */
final class TaskListing
{
    private function __construct() {}

    /**
     * The task's status timestamp in microseconds, or null when it has none.
     */
    public static function statusMicros(Task $task): ?int
    {
        $timestamp = $task->getStatus()?->getTimestamp();

        return $timestamp === null ? null : self::micros($timestamp);
    }

    public static function micros(Timestamp $timestamp): int
    {
        return (int) $timestamp->getSeconds() * 1_000_000 + intdiv($timestamp->getNanos(), 1000);
    }

    /**
     * The ListTasks sort key, [has timestamp, timestamp, id]; tasks are listed
     * in DESCENDING order of it. Arrays of the same shape compare element by
     * element, so `<=>` orders keys correctly.
     *
     * @return array{bool, int, string}
     */
    public static function sortKey(Task $task): array
    {
        $micros = self::statusMicros($task);

        return [$micros !== null, $micros ?? 0, $task->getId()];
    }

    /**
     * Orders tasks the way ListTasks returns them: tasks with a status
     * timestamp first, newest first, then by id descending.
     *
     * @param list<Task> $tasks
     *
     * @return list<Task>
     */
    public static function sort(array $tasks): array
    {
        usort($tasks, static fn(Task $a, Task $b): int => self::sortKey($b) <=> self::sortKey($a));

        return $tasks;
    }
}
