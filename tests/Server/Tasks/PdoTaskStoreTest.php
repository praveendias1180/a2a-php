<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Tasks\PdoTaskStore;
use A2A\Server\Tasks\TaskStore;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\TaskState;

/**
 * The TaskStore contract against SQLite. Mirrors a2a-python
 * tests/server/tasks/test_database_task_store.py.
 */
final class PdoTaskStoreTest extends TaskStoreContract
{
    protected function createStore(): TaskStore
    {
        return new PdoTaskStore(new \PDO('sqlite::memory:'));
    }

    public function testTwoConnectionsToOneFileShareTasks(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'a2a-store-');
        self::assertIsString($file);
        try {
            $writer = new PdoTaskStore(new \PDO('sqlite:' . $file));
            $reader = new PdoTaskStore(new \PDO('sqlite:' . $file));

            $writer->save(Fixtures::task('shared', TaskState::TASK_STATE_WORKING), Fixtures::callContext());

            self::assertSame('shared', $reader->get('shared', Fixtures::callContext())?->getId());
        } finally {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testTablePrefixAndProtocolVersionColumn(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $store = new PdoTaskStore($pdo, tablePrefix: 'agent_');
        $store->save(Fixtures::task('t', TaskState::TASK_STATE_WORKING), Fixtures::callContext(version: '1.0'));

        $statement = $pdo->query('SELECT owner, status_state, protocol_version FROM agent_tasks');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        self::assertSame(['owner' => '', 'status_state' => TaskState::TASK_STATE_WORKING, 'protocol_version' => '1.0'], $row);
    }

    public function testRejectsUnsafeTablePrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoTaskStore(new \PDO('sqlite::memory:'), tablePrefix: 'x; DROP TABLE y; --');
    }

    public function testRejectsUnsupportedDrivers(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed');
        }
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('getAttribute')->willReturn('oci');

        $this->expectException(\InvalidArgumentException::class);
        new PdoTaskStore($pdo);
    }
}
