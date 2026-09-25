<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Events;

use A2A\Server\Events\PdoQueueManager;
use A2A\Server\Events\QueueManager;
use A2A\Types\TaskState;

final class PdoQueueManagerTest extends QueueManagerContract
{
    protected function createManager(): QueueManager
    {
        return new PdoQueueManager(new \PDO('sqlite::memory:'), pollIntervalMs: 10);
    }

    public function testTwoProcessesShareTheLogAndFlags(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'a2a-queue-');
        self::assertIsString($file);
        try {
            // Two connections stand in for two PHP processes.
            $publisher = new PdoQueueManager(new \PDO('sqlite:' . $file));
            $subscriber = new PdoQueueManager(new \PDO('sqlite:' . $file));

            $sequence = $publisher->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_WORKING));
            $publisher->requestCancel('task-1');
            $publisher->acquireRunLease('task-1', 60);

            self::assertSame([$sequence], array_keys($subscriber->read('task-1', 0)));
            self::assertTrue($subscriber->isCancelRequested('task-1'));
            self::assertTrue($subscriber->hasActiveRunLease('task-1'));
            self::assertFalse($subscriber->acquireRunLease('task-1', 60));
        } finally {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testPruneDeletesOldEvents(): void
    {
        $manager = new PdoQueueManager(new \PDO('sqlite::memory:'));
        $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_WORKING));

        self::assertSame(0, $manager->prune(3600));
        usleep(2_000);
        self::assertSame(1, $manager->prune(0));
        self::assertSame([], $manager->read('task-1', 0));
    }

    public function testTablesCanBeCreatedExplicitly(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $manager = new PdoQueueManager($pdo, tablePrefix: 'agent_', createTables: false);
        $manager->createTables();

        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'agent_%' ORDER BY name");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $tables = $statement->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['agent_task_events', 'agent_task_flags'], $tables);
    }
}
