<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\ResultAggregator;
use A2A\Server\Tasks\TaskManager;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/server/tasks/test_result_aggregator.py
 * (iterables instead of an EventConsumer, a continuation closure instead of
 * a background asyncio task).
 */
final class ResultAggregatorTest extends TestCase
{
    private TaskManager $taskManager;

    private ResultAggregator $aggregator;

    protected function setUp(): void
    {
        $this->taskManager = new TaskManager(new InMemoryTaskStore(), Fixtures::callContext(), null, null);
        $this->aggregator = new ResultAggregator($this->taskManager);
    }

    public function testCurrentResultIsTheMessageOrTheTask(): void
    {
        self::assertNull($this->aggregator->currentResult());

        $this->aggregator->consumeAll([Fixtures::task('t', TaskState::TASK_STATE_WORKING)]);
        self::assertInstanceOf(Task::class, $this->aggregator->currentResult());

        $message = Fixtures::userMessage();
        $this->aggregator->consumeAll([$message]);
        self::assertSame($message, $this->aggregator->currentResult());
    }

    public function testConsumeAndEmitProcessesEachEvent(): void
    {
        $events = [Fixtures::task('t', TaskState::TASK_STATE_SUBMITTED), self::statusEvent('t', TaskState::TASK_STATE_COMPLETED)];

        $emitted = iterator_to_array($this->aggregator->consumeAndEmit($events), false);

        self::assertSame($events, $emitted);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $this->taskManager->getTask()?->getStatus()?->getState());
    }

    public function testConsumeAllReturnsFirstMessage(): void
    {
        $message = Fixtures::userMessage();
        $unreached = new class ([$message, self::statusEvent('t', TaskState::TASK_STATE_WORKING)]) extends \ArrayIterator {};

        self::assertSame($message, $this->aggregator->consumeAll($unreached));
    }

    public function testConsumeAllReturnsTaskAtEnd(): void
    {
        $result = $this->aggregator->consumeAll([Fixtures::task('t', TaskState::TASK_STATE_SUBMITTED), self::statusEvent('t', TaskState::TASK_STATE_COMPLETED)]);

        self::assertInstanceOf(Task::class, $result);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $result->getStatus()?->getState());
    }

    public function testBreakOnMessage(): void
    {
        $message = Fixtures::userMessage();

        [$result, $interrupted, $continuation] = $this->aggregator->consumeAndBreakOnInterrupt(new \ArrayIterator([$message]));

        self::assertSame($message, $result);
        self::assertFalse($interrupted);
        self::assertNull($continuation);
    }

    public function testBreakOnAuthRequiredAndContinueLater(): void
    {
        $seen = [];
        $events = new \ArrayIterator([
            Fixtures::task('t', TaskState::TASK_STATE_SUBMITTED),
            self::statusEvent('t', TaskState::TASK_STATE_AUTH_REQUIRED),
            self::statusEvent('t', TaskState::TASK_STATE_COMPLETED),
        ]);

        [$result, $interrupted, $continuation] = $this->aggregator->consumeAndBreakOnInterrupt(
            $events,
            eventCallback: static function (Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event) use (&$seen): void {
                $seen[] = $event::class;
            },
        );

        self::assertTrue($interrupted);
        self::assertInstanceOf(Task::class, $result);
        self::assertSame(TaskState::TASK_STATE_AUTH_REQUIRED, $result->getStatus()?->getState());
        self::assertNotNull($continuation);
        self::assertCount(2, $seen);

        $continuation();

        self::assertCount(3, $seen);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $this->taskManager->getTask()?->getStatus()?->getState());
    }

    public function testNonBlockingBreaksAfterFirstEvent(): void
    {
        [$result, $interrupted, $continuation] = $this->aggregator->consumeAndBreakOnInterrupt(
            new \ArrayIterator([Fixtures::task('t', TaskState::TASK_STATE_SUBMITTED), self::statusEvent('t', TaskState::TASK_STATE_COMPLETED)]),
            blocking: false,
        );

        self::assertTrue($interrupted);
        self::assertSame(TaskState::TASK_STATE_SUBMITTED, $result instanceof Task ? $result->getStatus()?->getState() : null);
        self::assertNotNull($continuation);
        $continuation();
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $this->taskManager->getTask()?->getStatus()?->getState());
    }

    public function testBlockingRunsToTheEnd(): void
    {
        [$result, $interrupted, $continuation] = $this->aggregator->consumeAndBreakOnInterrupt(
            new \ArrayIterator([Fixtures::task('t', TaskState::TASK_STATE_SUBMITTED), self::statusEvent('t', TaskState::TASK_STATE_COMPLETED)]),
        );

        self::assertFalse($interrupted);
        self::assertNull($continuation);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $result instanceof Task ? $result->getStatus()?->getState() : null);
    }

    private static function statusEvent(string $taskId, int $state): TaskStatusUpdateEvent
    {
        return new TaskStatusUpdateEvent(['task_id' => $taskId, 'context_id' => 'ctx-1', 'status' => new TaskStatus(['state' => $state])]);
    }
}
