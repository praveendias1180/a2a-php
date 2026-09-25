<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Events;

use A2A\Server\Events\InMemoryEventQueue;
use A2A\Server\Events\QueueClosedException;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\TaskState;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors a2a-python tests/server/events/test_event_queue.py where it
 * applies without asyncio.
 */
final class InMemoryEventQueueTest extends TestCase
{
    public function testEnqueueAndDequeueInOrder(): void
    {
        $queue = new InMemoryEventQueue();
        $first = Fixtures::userMessage('a');
        $second = Fixtures::task('t', TaskState::TASK_STATE_WORKING);
        $queue->enqueueEvent($first);
        $queue->enqueueEvent($second);

        self::assertSame([$first, $second], $queue->events());
        self::assertSame($first, $queue->dequeueEvent());
        self::assertSame($second, $queue->dequeueEvent());
        self::assertNull($queue->dequeueEvent());
        self::assertTrue($queue->isEmpty());
    }

    public function testClosedQueueRejectsEvents(): void
    {
        $queue = new InMemoryEventQueue();
        $queue->close();

        self::assertTrue($queue->isClosed());
        $this->expectException(QueueClosedException::class);
        $queue->enqueueEvent(Fixtures::userMessage());
    }
}
