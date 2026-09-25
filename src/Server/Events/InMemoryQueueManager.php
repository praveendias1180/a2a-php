<?php

declare(strict_types=1);

namespace A2A\Server\Events;

/**
 * A QueueManager that lives in one PHP process.
 *
 * Right for tests and long-running servers (RoadRunner, Swoole, a CLI
 * worker). Under PHP-FPM or `php -S` every request is a new process, so
 * subscribers in another request never see these events: use
 * PdoQueueManager (or the Laravel bridge's Redis one) there.
 *
 * Mirrors a2a-python: InMemoryQueueManager in
 * src/a2a/server/events/in_memory_queue_manager.py
 */
final class InMemoryQueueManager implements QueueManager
{
    /** @var array<string, array<int, PublishedEvent>> */
    private array $logs = [];

    private int $sequence = 0;

    /** @var array<string, true> */
    private array $cancelled = [];

    /** @var array<string, float> task id => lease expiry (unix time) */
    private array $leases = [];

    public function publish(string $taskId, PublishedEvent $event): int
    {
        $this->logs[$taskId][++$this->sequence] = $event;

        return $this->sequence;
    }

    public function read(string $taskId, int $afterSequence, float $waitSeconds = 0.0): array
    {
        $events = array_filter(
            $this->logs[$taskId] ?? [],
            static fn(int $sequence): bool => $sequence > $afterSequence,
            ARRAY_FILTER_USE_KEY,
        );
        if ($events === [] && $waitSeconds > 0) {
            // Nothing else can publish while this process sleeps, but a
            // caller polling in a loop still expects the wait to happen.
            usleep((int) ($waitSeconds * 1_000_000));
        }

        return $events;
    }

    public function lastSequence(string $taskId): int
    {
        $keys = array_keys($this->logs[$taskId] ?? []);

        return $keys === [] ? 0 : max($keys);
    }

    public function requestCancel(string $taskId): void
    {
        $this->cancelled[$taskId] = true;
    }

    public function isCancelRequested(string $taskId): bool
    {
        return isset($this->cancelled[$taskId]);
    }

    public function acquireRunLease(string $taskId, int $ttlSeconds): bool
    {
        if ($this->hasActiveRunLease($taskId)) {
            return false;
        }
        $this->leases[$taskId] = microtime(true) + $ttlSeconds;

        return true;
    }

    public function releaseRunLease(string $taskId): void
    {
        unset($this->leases[$taskId]);
    }

    public function hasActiveRunLease(string $taskId): bool
    {
        return isset($this->leases[$taskId]) && $this->leases[$taskId] > microtime(true);
    }
}
