<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskStore;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\TaskState;

final class InMemoryTaskStoreTest extends TaskStoreContract
{
    protected function createStore(): TaskStore
    {
        return new InMemoryTaskStore();
    }

    public function testCustomOwnerResolver(): void
    {
        $store = new InMemoryTaskStore(static fn(ServerCallContext $c): string => $c->tenant);
        $tenantA = Fixtures::callContext();
        $tenantA->tenant = 'a';
        $tenantB = Fixtures::callContext();
        $tenantB->tenant = 'b';

        $store->save(Fixtures::task('t', TaskState::TASK_STATE_WORKING), $tenantA);

        self::assertNotNull($store->get('t', $tenantA));
        self::assertNull($store->get('t', $tenantB));
    }

    public function testWithoutCopyingReturnsTheSameObject(): void
    {
        $store = new InMemoryTaskStore(useCopying: false);
        $task = Fixtures::task('t', TaskState::TASK_STATE_WORKING);
        $store->save($task, Fixtures::callContext());

        self::assertSame($task, $store->get('t', Fixtures::callContext()));
    }
}
