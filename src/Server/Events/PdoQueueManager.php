<?php

declare(strict_types=1);

namespace A2A\Server\Events;

use A2A\Server\Pdo\PdoDriver;

/**
 * A QueueManager in a SQL database, shared by every PHP process that uses
 * the same database. This is what makes SubscribeToTask, multiple streams on
 * one task and cancelling from another request work under PHP-FPM or
 * `php -S` (each request there is its own process).
 *
 * Tables (created on first use unless $createTables is false):
 * - `{prefix}task_events`: seq, task_id, payload (JSON), created_at
 * - `{prefix}task_flags`: task_id, cancel_requested, lease_expires_at
 *
 * Readers poll every $pollIntervalMs while waiting. For high traffic, use a
 * push-based backend instead (the Laravel bridge's Redis Streams manager).
 *
 * Old events are not deleted automatically; call prune() from a scheduled
 * job.
 *
 * PHP-specific: a2a-python keeps live queues in memory, so it has no
 * database-backed queue manager.
 */
final class PdoQueueManager implements QueueManager
{
    private readonly string $driver;

    private readonly string $events;

    private readonly string $flags;

    private bool $tablesReady;

    public function __construct(
        private readonly \PDO $pdo,
        string $tablePrefix = 'a2a_',
        bool $createTables = true,
        private readonly int $pollIntervalMs = 50,
    ) {
        $this->driver = PdoDriver::prepare($pdo);
        $prefix = PdoDriver::assertIdentifier($tablePrefix . 'x');
        $prefix = substr($prefix, 0, -1);
        $this->events = $prefix . 'task_events';
        $this->flags = $prefix . 'task_flags';
        $this->tablesReady = !$createTables;
    }

    public function createTables(): void
    {
        $text = PdoDriver::longText($this->driver);
        $pk = PdoDriver::autoIncrementPrimaryKey($this->driver, 'seq');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->events} ({$pk}, task_id VARCHAR(255) NOT NULL, payload {$text} NOT NULL, created_at BIGINT NOT NULL)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->flags} (task_id VARCHAR(255) NOT NULL PRIMARY KEY, cancel_requested SMALLINT NOT NULL DEFAULT 0, lease_expires_at BIGINT NULL)");
        $index = $this->events . '_task_seq';
        if ($this->driver === PdoDriver::MYSQL) {
            $exists = $this->pdo->query("SHOW INDEX FROM {$this->events} WHERE Key_name = '{$index}'");
            if ($exists !== false && $exists->fetch() === false) {
                $this->pdo->exec("CREATE INDEX {$index} ON {$this->events} (task_id, seq)");
            }
        } else {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$this->events} (task_id, seq)");
        }
        $this->tablesReady = true;
    }

    public function publish(string $taskId, PublishedEvent $event): int
    {
        $this->ensureTables();
        $statement = $this->pdo->prepare("INSERT INTO {$this->events} (task_id, payload, created_at) VALUES (:task_id, :payload, :created_at)");
        $statement->execute([
            'task_id' => $taskId,
            'payload' => $event->toJson(),
            'created_at' => (int) (microtime(true) * 1000),
        ]);

        return (int) $this->pdo->lastInsertId($this->driver === PdoDriver::PGSQL ? $this->events . '_seq_seq' : null);
    }

    public function read(string $taskId, int $afterSequence, float $waitSeconds = 0.0): array
    {
        $this->ensureTables();
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $statement = $this->pdo->prepare("SELECT seq, payload FROM {$this->events} WHERE task_id = :task_id AND seq > :after ORDER BY seq ASC");

        while (true) {
            $statement->execute(['task_id' => $taskId, 'after' => $afterSequence]);
            $events = [];
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (is_array($row) && is_numeric($row['seq'] ?? null) && is_string($row['payload'] ?? null)) {
                    $events[(int) $row['seq']] = PublishedEvent::fromJson($row['payload']);
                }
            }
            if ($events !== [] || microtime(true) >= $deadline) {
                return $events;
            }
            usleep($this->pollIntervalMs * 1000);
        }
    }

    public function lastSequence(string $taskId): int
    {
        $this->ensureTables();
        $statement = $this->pdo->prepare("SELECT MAX(seq) FROM {$this->events} WHERE task_id = :task_id");
        $statement->execute(['task_id' => $taskId]);
        $value = $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function requestCancel(string $taskId): void
    {
        $this->ensureTables();
        $sql = $this->driver === PdoDriver::MYSQL
            ? "INSERT INTO {$this->flags} (task_id, cancel_requested) VALUES (:task_id, 1) ON DUPLICATE KEY UPDATE cancel_requested = 1"
            : "INSERT INTO {$this->flags} (task_id, cancel_requested) VALUES (:task_id, 1) ON CONFLICT (task_id) DO UPDATE SET cancel_requested = 1";
        $this->pdo->prepare($sql)->execute(['task_id' => $taskId]);
    }

    public function isCancelRequested(string $taskId): bool
    {
        $this->ensureTables();
        $statement = $this->pdo->prepare("SELECT cancel_requested FROM {$this->flags} WHERE task_id = :task_id");
        $statement->execute(['task_id' => $taskId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function acquireRunLease(string $taskId, int $ttlSeconds): bool
    {
        $this->ensureTables();
        $now = (int) (microtime(true) * 1000);
        $expires = $now + $ttlSeconds * 1000;

        if ($this->driver === PdoDriver::MYSQL) {
            $statement = $this->pdo->prepare("INSERT INTO {$this->flags} (task_id, lease_expires_at) VALUES (:task_id, :expires) ON DUPLICATE KEY UPDATE lease_expires_at = IF(lease_expires_at IS NULL OR lease_expires_at < :now, VALUES(lease_expires_at), lease_expires_at)");
            $statement->execute(['task_id' => $taskId, 'expires' => $expires, 'now' => $now]);

            // MySQL reports 1 for an insert, 2 for an update and 0 when nothing changed.
            return $statement->rowCount() > 0;
        }

        $statement = $this->pdo->prepare("INSERT INTO {$this->flags} (task_id, lease_expires_at) VALUES (:task_id, :expires) ON CONFLICT (task_id) DO UPDATE SET lease_expires_at = excluded.lease_expires_at WHERE {$this->flags}.lease_expires_at IS NULL OR {$this->flags}.lease_expires_at < :now");
        $statement->execute(['task_id' => $taskId, 'expires' => $expires, 'now' => $now]);

        return $statement->rowCount() > 0;
    }

    public function releaseRunLease(string $taskId): void
    {
        $this->ensureTables();
        $this->pdo->prepare("UPDATE {$this->flags} SET lease_expires_at = NULL WHERE task_id = :task_id")->execute(['task_id' => $taskId]);
    }

    public function hasActiveRunLease(string $taskId): bool
    {
        $this->ensureTables();
        $statement = $this->pdo->prepare("SELECT lease_expires_at FROM {$this->flags} WHERE task_id = :task_id");
        $statement->execute(['task_id' => $taskId]);
        $value = $statement->fetchColumn();

        return is_numeric($value) && (int) $value > (int) (microtime(true) * 1000);
    }

    /**
     * Deletes events older than $olderThanSeconds, and flags of tasks with no
     * remaining events or live lease. Returns the number of events deleted.
     */
    public function prune(int $olderThanSeconds): int
    {
        $this->ensureTables();
        $cutoff = (int) ((microtime(true) - $olderThanSeconds) * 1000);
        $statement = $this->pdo->prepare("DELETE FROM {$this->events} WHERE created_at < :cutoff");
        $statement->execute(['cutoff' => $cutoff]);
        $deleted = $statement->rowCount();
        $this->pdo->prepare("DELETE FROM {$this->flags} WHERE (lease_expires_at IS NULL OR lease_expires_at < :now) AND task_id NOT IN (SELECT task_id FROM {$this->events})")
            ->execute(['now' => (int) (microtime(true) * 1000)]);

        return $deleted;
    }

    private function ensureTables(): void
    {
        if (!$this->tablesReady) {
            $this->createTables();
        }
    }
}
