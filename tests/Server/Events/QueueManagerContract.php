<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Events;

use A2A\Server\Events\PublishedEvent;
use A2A\Server\Events\QueueManager;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every QueueManager must have: an ordered per-task event log,
 * the cancel flag and run leases. PHP-specific (Python's QueueManager holds
 * live asyncio queues instead); the in-memory behaviour mirrors
 * tests/server/events/test_inmemory_queue_manager.py where it applies.
 */
abstract class QueueManagerContract extends TestCase
{
    abstract protected function createManager(): QueueManager;

    public function testPublishAndReadInOrder(): void
    {
        $manager = $this->createManager();
        $first = $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_WORKING));
        $second = $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_COMPLETED));

        self::assertGreaterThan($first, $second);
        $events = $manager->read('task-1', 0);
        self::assertSame([$first, $second], array_keys($events));
        self::assertSame(TaskState::TASK_STATE_WORKING, self::state($events[$first]));
        self::assertSame(TaskState::TASK_STATE_COMPLETED, self::state($events[$second]));
    }

    public function testReadAfterASequenceNumber(): void
    {
        $manager = $this->createManager();
        $first = $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_WORKING));
        $second = $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_COMPLETED));

        self::assertSame([$second], array_keys($manager->read('task-1', $first)));
        self::assertSame([], $manager->read('task-1', $second));
        self::assertSame($second, $manager->lastSequence('task-1'));
    }

    public function testTasksAreSeparate(): void
    {
        $manager = $this->createManager();
        $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_WORKING));
        $other = $manager->publish('task-2', self::statusEvent('task-2', TaskState::TASK_STATE_WORKING));

        self::assertSame([$other], array_keys($manager->read('task-2', 0)));
        self::assertSame(0, $manager->lastSequence('unknown'));
        self::assertSame([], $manager->read('unknown', 0));
    }

    public function testReadWaitsUpToTheTimeoutWhenEmpty(): void
    {
        $manager = $this->createManager();
        $start = microtime(true);

        self::assertSame([], $manager->read('task-1', 0, 0.2));
        self::assertGreaterThanOrEqual(0.19, microtime(true) - $start);
    }

    public function testCancelFlag(): void
    {
        $manager = $this->createManager();
        self::assertFalse($manager->isCancelRequested('task-1'));

        $manager->requestCancel('task-1');
        $manager->requestCancel('task-1');

        self::assertTrue($manager->isCancelRequested('task-1'));
        self::assertFalse($manager->isCancelRequested('task-2'));
    }

    public function testRunLeases(): void
    {
        $manager = $this->createManager();
        self::assertFalse($manager->hasActiveRunLease('task-1'));

        self::assertTrue($manager->acquireRunLease('task-1', 60));
        self::assertTrue($manager->hasActiveRunLease('task-1'));
        self::assertFalse($manager->acquireRunLease('task-1', 60), 'a live lease blocks a second run');
        self::assertTrue($manager->acquireRunLease('task-2', 60), 'leases are per task');

        $manager->releaseRunLease('task-1');
        self::assertFalse($manager->hasActiveRunLease('task-1'));
        self::assertTrue($manager->acquireRunLease('task-1', 60));
    }

    public function testExpiredLeaseCanBeTakenOver(): void
    {
        $manager = $this->createManager();
        self::assertTrue($manager->acquireRunLease('task-1', 0));
        usleep(5_000);

        self::assertFalse($manager->hasActiveRunLease('task-1'));
        self::assertTrue($manager->acquireRunLease('task-1', 60));
    }

    public function testCancelAndLeaseDontInterfere(): void
    {
        $manager = $this->createManager();
        $manager->acquireRunLease('task-1', 60);
        $manager->requestCancel('task-1');
        $manager->releaseRunLease('task-1');

        self::assertTrue($manager->isCancelRequested('task-1'));
        self::assertFalse($manager->hasActiveRunLease('task-1'));
    }

    public function testEventsKeepTheTaskSnapshot(): void
    {
        $manager = $this->createManager();
        $task = Fixtures::task('task-1', TaskState::TASK_STATE_COMPLETED, 'ctx-1', 1000);
        $manager->publish('task-1', new PublishedEvent(new TaskStatusUpdateEvent([
            'task_id' => 'task-1',
            'context_id' => 'ctx-1',
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_COMPLETED]),
        ]), $task));

        $event = array_values($manager->read('task-1', 0))[0];
        self::assertTrue($event->isTerminal());
        self::assertSame($task->serializeToJsonString(), $event->task?->serializeToJsonString());
    }

    protected static function statusEvent(string $taskId, int $state): PublishedEvent
    {
        return new PublishedEvent(new TaskStatusUpdateEvent([
            'task_id' => $taskId,
            'context_id' => 'ctx-1',
            'status' => new TaskStatus(['state' => $state]),
        ]));
    }

    private static function state(PublishedEvent $event): ?int
    {
        return $event->event instanceof TaskStatusUpdateEvent ? $event->event->getStatus()?->getState() : null;
    }
}
