<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Events;

use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\Events\QueueManager;

final class InMemoryQueueManagerTest extends QueueManagerContract
{
    protected function createManager(): QueueManager
    {
        return new InMemoryQueueManager();
    }
}
