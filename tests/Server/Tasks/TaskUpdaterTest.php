<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Events\InMemoryEventQueue;
use A2A\Server\IdGenerator;
use A2A\Server\IdGeneratorContext;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\ProtoUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/server/tasks/test_task_updater.py.
 */
final class TaskUpdaterTest extends TestCase
{
    private InMemoryEventQueue $queue;

    private TaskUpdater $updater;

    protected function setUp(): void
    {
        $this->queue = new InMemoryEventQueue();
        $this->updater = new TaskUpdater($this->queue, 'test-task-id', 'test-context-id');
    }

    public function testInit(): void
    {
        self::assertSame($this->queue, $this->updater->eventQueue);
        self::assertSame('test-task-id', $this->updater->taskId);
        self::assertSame('test-context-id', $this->updater->contextId);
    }

    public function testUpdateStatusWithoutMessage(): void
    {
        $this->updater->updateStatus(TaskState::TASK_STATE_WORKING);

        $event = $this->onlyStatusEvent();
        self::assertSame('test-task-id', $event->getTaskId());
        self::assertSame('test-context-id', $event->getContextId());
        self::assertSame(TaskState::TASK_STATE_WORKING, $event->getStatus()?->getState());
        self::assertNull($event->getStatus()->getMessage());
        self::assertNotNull($event->getStatus()->getTimestamp());
    }

    public function testUpdateStatusWithMessage(): void
    {
        $message = $this->message();
        $this->updater->updateStatus(TaskState::TASK_STATE_WORKING, $message);

        self::assertSame('Test message', $this->onlyStatusEvent()->getStatus()?->getMessage()?->getParts()[0]->getText());
    }

    public function testUpdateStatusWithExplicitTimestampAndMetadata(): void
    {
        $this->updater->updateStatus(TaskState::TASK_STATE_WORKING, timestamp: '2026-01-02T03:04:05Z', metadata: ['step' => 2]);

        $event = $this->onlyStatusEvent();
        self::assertSame(1767323045, (int) $event->getStatus()?->getTimestamp()?->getSeconds());
        self::assertNotNull($event->getMetadata());
        self::assertSame(['step' => 2], ProtoUtils::fromStruct($event->getMetadata()));
    }

    public function testUpdateStatusRaisesIfTerminalStateReached(): void
    {
        $this->updater->complete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task test-task-id is already in a terminal state.');
        $this->updater->startWork();
    }

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function shortcuts(): iterable
    {
        yield 'complete' => ['complete', TaskState::TASK_STATE_COMPLETED, true];
        yield 'failed' => ['failed', TaskState::TASK_STATE_FAILED, true];
        yield 'reject' => ['reject', TaskState::TASK_STATE_REJECTED, true];
        yield 'cancel' => ['cancel', TaskState::TASK_STATE_CANCELED, true];
        yield 'submit' => ['submit', TaskState::TASK_STATE_SUBMITTED, false];
        yield 'startWork' => ['startWork', TaskState::TASK_STATE_WORKING, false];
        yield 'requiresInput' => ['requiresInput', TaskState::TASK_STATE_INPUT_REQUIRED, false];
        yield 'requiresAuth' => ['requiresAuth', TaskState::TASK_STATE_AUTH_REQUIRED, false];
    }

    #[DataProvider('shortcuts')]
    public function testShortcutWithoutMessage(string $method, int $state, bool $terminal): void
    {
        $this->updater->{$method}();

        $event = $this->onlyStatusEvent();
        self::assertSame($state, $event->getStatus()?->getState());
        self::assertNull($event->getStatus()->getMessage());

        if ($terminal) {
            $this->expectException(\RuntimeException::class);
        }
        $this->updater->updateStatus(TaskState::TASK_STATE_WORKING);
        self::assertFalse($terminal);
    }

    #[DataProvider('shortcuts')]
    public function testShortcutWithMessage(string $method, int $state): void
    {
        $this->updater->{$method}($this->message());

        $event = $this->onlyStatusEvent();
        self::assertSame($state, $event->getStatus()?->getState());
        self::assertSame('Test message', $event->getStatus()->getMessage()?->getParts()[0]->getText());
    }

    public function testAddArtifactWithCustomIdAndName(): void
    {
        $this->updater->addArtifact([new Part(['text' => 'Hello'])], artifactId: 'custom-artifact-id', name: 'Custom Artifact');

        $event = $this->onlyArtifactEvent();
        self::assertSame('custom-artifact-id', $event->getArtifact()?->getArtifactId());
        self::assertSame('Custom Artifact', $event->getArtifact()->getName());
        self::assertSame('Hello', $event->getArtifact()->getParts()[0]->getText());
        self::assertFalse($event->getAppend());
        self::assertFalse($event->getLastChunk());
    }

    public function testAddArtifactGeneratesId(): void
    {
        $this->updater->addArtifact([new Part(['text' => 'Hello'])]);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $this->onlyArtifactEvent()->getArtifact()?->getArtifactId());
    }

    public function testAddArtifactUsesCustomIdGenerator(): void
    {
        $generator = new class implements IdGenerator {
            public ?IdGeneratorContext $seen = null;

            public function generate(IdGeneratorContext $context): string
            {
                $this->seen = $context;

                return 'custom-generated-id';
            }
        };
        $updater = new TaskUpdater($this->queue, 'test-task-id', 'test-context-id', artifactIdGenerator: $generator);

        $updater->addArtifact([new Part(['text' => 'Hello'])]);

        self::assertSame('custom-generated-id', $this->onlyArtifactEvent()->getArtifact()?->getArtifactId());
        self::assertSame('test-task-id', $generator->seen?->taskId);
        self::assertSame('test-context-id', $generator->seen->contextId);
    }

    public function testAddArtifactWithAppendLastChunkMetadataAndExtensions(): void
    {
        $this->updater->addArtifact(
            [new Part(['text' => 'chunk'])],
            artifactId: 'a-1',
            metadata: ['k' => 'v'],
            append: true,
            lastChunk: true,
            extensions: ['https://ext.example/v1'],
        );

        $event = $this->onlyArtifactEvent();
        self::assertTrue($event->getAppend());
        self::assertTrue($event->getLastChunk());
        self::assertNotNull($event->getArtifact()?->getMetadata());
        self::assertSame(['k' => 'v'], ProtoUtils::fromStruct($event->getArtifact()->getMetadata()));
        self::assertSame(['https://ext.example/v1'], iterator_to_array($event->getArtifact()->getExtensions()));
    }

    public function testNewAgentMessage(): void
    {
        $message = $this->updater->newAgentMessage([new Part(['text' => 'Test message'])]);

        self::assertSame(Role::ROLE_AGENT, $message->getRole());
        self::assertSame('test-task-id', $message->getTaskId());
        self::assertSame('test-context-id', $message->getContextId());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $message->getMessageId());
        self::assertNull($message->getMetadata());
        self::assertTrue($this->queue->isEmpty(), 'Creating a message must not enqueue it');
    }

    public function testNewAgentMessageWithMetadata(): void
    {
        $message = $this->updater->newAgentMessage([new Part(['text' => 'x'])], ['key' => 'value']);

        self::assertNotNull($message->getMetadata());
        self::assertSame(['key' => 'value'], ProtoUtils::fromStruct($message->getMetadata()));
    }

    public function testNewAgentMessageWithCustomIdGenerator(): void
    {
        $generator = new class implements IdGenerator {
            public function generate(IdGeneratorContext $context): string
            {
                return 'custom-message-id';
            }
        };
        $updater = new TaskUpdater($this->queue, 't', 'c', messageIdGenerator: $generator);

        self::assertSame('custom-message-id', $updater->newAgentMessage([])->getMessageId());
    }

    private function message(): Message
    {
        return new Message(['message_id' => 'm-1', 'role' => Role::ROLE_AGENT, 'parts' => [new Part(['text' => 'Test message'])]]);
    }

    private function onlyStatusEvent(): TaskStatusUpdateEvent
    {
        $events = $this->queue->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(TaskStatusUpdateEvent::class, $events[0]);

        return $events[0];
    }

    private function onlyArtifactEvent(): TaskArtifactUpdateEvent
    {
        $events = $this->queue->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(TaskArtifactUpdateEvent::class, $events[0]);

        return $events[0];
    }
}
