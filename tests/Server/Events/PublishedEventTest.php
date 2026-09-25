<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Events;

use A2A\Server\Events\PublishedEvent;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\Artifact;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishedEventTest extends TestCase
{
    /**
     * @return iterable<string, array{Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent}>
     */
    public static function events(): iterable
    {
        yield 'task' => [Fixtures::task('t', TaskState::TASK_STATE_SUBMITTED, 'c', 1)];
        yield 'message' => [Fixtures::userMessage('hi', 'm')];
        yield 'status' => [new TaskStatusUpdateEvent(['task_id' => 't', 'context_id' => 'c', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_WORKING])])];
        yield 'artifact' => [new TaskArtifactUpdateEvent(['task_id' => 't', 'context_id' => 'c', 'artifact' => new Artifact(['artifact_id' => 'a', 'parts' => [new Part(['text' => 'x'])]]), 'append' => true])];
    }

    #[DataProvider('events')]
    public function testJsonRoundTrip(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
    {
        $original = new PublishedEvent($event, Fixtures::task('t', TaskState::TASK_STATE_WORKING));
        $decoded = PublishedEvent::fromJson($original->toJson());

        self::assertInstanceOf($event::class, $decoded->event);
        self::assertSame($event->serializeToJsonString(), $decoded->event->serializeToJsonString());
        self::assertSame($original->task?->serializeToJsonString(), $decoded->task?->serializeToJsonString());
    }

    public function testWithoutTask(): void
    {
        $decoded = PublishedEvent::fromJson((new PublishedEvent(Fixtures::userMessage()))->toJson());

        self::assertNull($decoded->task);
        self::assertFalse($decoded->isTerminal());
    }

    public function testIsTerminalFromTaskOrStatusEvent(): void
    {
        $working = Fixtures::task('t', TaskState::TASK_STATE_WORKING);
        $done = Fixtures::task('t', TaskState::TASK_STATE_REJECTED);
        $status = static fn(int $s): TaskStatusUpdateEvent => new TaskStatusUpdateEvent(['task_id' => 't', 'context_id' => 'c', 'status' => new TaskStatus(['state' => $s])]);

        self::assertTrue((new PublishedEvent($status(TaskState::TASK_STATE_WORKING), $done))->isTerminal());
        self::assertFalse((new PublishedEvent($status(TaskState::TASK_STATE_COMPLETED), $working))->isTerminal(), 'the task snapshot wins');
        self::assertTrue((new PublishedEvent($status(TaskState::TASK_STATE_CANCELED)))->isTerminal());
    }

    public function testRejectsMalformedPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PublishedEvent::fromJson('{"nope": true}');
    }
}
