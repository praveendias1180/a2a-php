<?php

declare(strict_types=1);

namespace A2A\Server\Events;

/**
 * Shares task events and coordination flags between requests.
 *
 * Mirrors a2a-python: QueueManager in src/a2a/server/events/queue_manager.py,
 * with a different shape because PHP differs: Python keeps live asyncio
 * queues in one long-running process, while PHP-FPM and `php -S` run every
 * request in its own short-lived process. So instead of in-memory queues,
 * a QueueManager here is an append-only, per-task **event log** that any
 * process can read from a sequence number, plus two flags other processes
 * need: "cancel was requested" and "an executor is running this task".
 *
 * - InMemoryQueueManager: one process only (tests, long-running servers).
 * - PdoQueueManager: SQLite/PostgreSQL/MySQL, shared by all PHP processes.
 * - The Laravel bridge adds a Redis Streams version.
 */
interface QueueManager
{
    /**
     * Appends an event to the task's log and returns its sequence number.
     * Sequence numbers only grow, so readers can resume from the last one
     * they saw.
     */
    public function publish(string $taskId, PublishedEvent $event): int;

    /**
     * Events with a sequence number greater than $afterSequence, oldest
     * first, keyed by sequence number. When there are none yet, waits up to
     * $waitSeconds for one to arrive (0 returns at once).
     *
     * @return array<int, PublishedEvent>
     */
    public function read(string $taskId, int $afterSequence, float $waitSeconds = 0.0): array;

    /**
     * The newest sequence number in the task's log (0 when empty).
     */
    public function lastSequence(string $taskId): int;

    public function requestCancel(string $taskId): void;

    public function isCancelRequested(string $taskId): bool;

    /**
     * Marks the task as being run by this process for up to $ttlSeconds.
     * Returns false when another process holds a live lease.
     */
    public function acquireRunLease(string $taskId, int $ttlSeconds): bool;

    public function releaseRunLease(string $taskId): void;

    /**
     * True while some process holds an unexpired run lease on the task.
     */
    public function hasActiveRunLease(string $taskId): bool;
}
