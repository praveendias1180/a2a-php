<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Tasks\CopyingTaskStore;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskStore;

/**
 * Ported from a2a-python tests/server/tasks/test_copying_task_store.py.
 */
final class CopyingTaskStoreTest extends TaskStoreContract
{
    protected function createStore(): TaskStore
    {
        return new CopyingTaskStore(new InMemoryTaskStore(useCopying: false));
    }
}
