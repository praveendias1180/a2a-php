<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskManager;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\Artifact;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Errors\InvalidAgentResponseError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\ProtoUtils;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/server/tasks/test_task_manager.py.
 */
final class TaskManagerTest extends TestCase
{
    private InMemoryTaskStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryTaskStore();
    }

    public function testInvalidTaskIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskManager($this->store, Fixtures::callContext(), '', null);
    }

    public function testGetTaskExistingAndNonexistent(): void
    {
        $this->store->save(Fixtures::task('task-abc', TaskState::TASK_STATE_WORKING, 'ctx-1'), Fixtures::callContext());

        $existing = new TaskManager($this->store, Fixtures::callContext(), 'task-abc', 'ctx-1');
        self::assertSame('task-abc', $existing->getTask()?->getId());

        self::assertNull((new TaskManager($this->store, Fixtures::callContext(), 'missing', null))->getTask());
        self::assertNull((new TaskManager($this->store, Fixtures::callContext(), null, null))->getTask());
    }

    public function testSaveTaskEventNewTask(): void
    {
        $manager = new TaskManager($this->store, Fixtures::callContext(), 'task-abc', 'ctx-1');
        $task = Fixtures::task('task-abc', TaskState::TASK_STATE_SUBMITTED, 'ctx-1');

        $manager->saveTaskEvent($task);

        self::assertSame('task-abc', $this->store->get('task-abc', Fixtures::callContext())?->getId());
    }

    public function testSaveTaskEventStatusUpdateMovesStatusMessageToHistory(): void
    {
        $initial = Fixtures::task('task-abc', TaskState::TASK_STATE_SUBMITTED, 'ctx-1');
        $initial->getStatus()?->setMessage(new Message(['message_id' => 'old', 'role' => Role::ROLE_AGENT]));
        $this->store->save($initial, Fixtures::callContext());
        $manager = new TaskManager($this->store, Fixtures::callContext(), 'task-abc', 'ctx-1');

        $newStatus = new TaskStatus(['state' => TaskState::TASK_STATE_WORKING, 'message' => new Message(['message_id' => 'new', 'role' => Role::ROLE_AGENT])]);
        $updated = $manager->saveTaskEvent(new TaskStatusUpdateEvent(['task_id' => 'task-abc', 'context_id' => 'ctx-1', 'status' => $newStatus]));

        self::assertSame(TaskState::TASK_STATE_WORKING, $updated->getStatus()?->getState());
        self::assertSame('new', $updated->getStatus()->getMessage()?->getMessageId());
        self::assertSame(['old'], array_map(static fn(Message $m): string => $m->getMessageId(), iterator_to_array($updated->getHistory())));
    }

    public function testSaveTaskEventArtifactUpdate(): void
    {
        $this->store->save(Fixtures::task('task-abc', TaskState::TASK_STATE_WORKING, 'ctx-1'), Fixtures::callContext());
        $manager = new TaskManager($this->store, Fixtures::callContext(), 'task-abc', 'ctx-1');

        $updated = $manager->saveTaskEvent(new TaskArtifactUpdateEvent([
            'task_id' => 'task-abc',
            'context_id' => 'ctx-1',
            'artifact' => new Artifact(['artifact_id' => 'artifact-id', 'name' => 'artifact-name', 'parts' => [new Part(['text' => 'content'])]]),
        ]));

        self::assertCount(1, $updated->getArtifacts());
        self::assertSame('artifact-id', $updated->getArtifacts()[0]->getArtifactId());
    }

    public function testSaveTaskEventMergesMetadata(): void
    {
        $this->store->save(Fixtures::task('task-abc', TaskState::TASK_STATE_WORKING, 'ctx-1'), Fixtures::callContext());
        $manager = new TaskManager($this->store, Fixtures::callContext(), 'task-abc', 'ctx-1');

        $updated = $manager->saveTaskEvent(new TaskStatusUpdateEvent([
            'task_id' => 'task-abc',
            'context_id' => 'ctx-1',
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_WORKING]),
            'metadata' => ProtoUtils::toStruct(['meta_key_test' => 'meta_value_test']),
        ]));

        self::assertNotNull($updated->getMetadata());
        self::assertSame(['meta_key_test' => 'meta_value_test'], ProtoUtils::fromStruct($updated->getMetadata()));
    }

    public function testEnsureTaskExistingAndNonexistent(): void
    {
        $this->store->save(Fixtures::task('task-abc', TaskState::TASK_STATE_WORKING, 'ctx-1'), Fixtures::callContext());
        $manager = new TaskManager($this->store, Fixtures::callContext(), 'task-abc', 'ctx-1');
        self::assertSame(TaskState::TASK_STATE_WORKING, $manager->ensureTaskId('task-abc', 'ctx-1')->getStatus()?->getState());

        $initial = Fixtures::userMessage('first');
        $fresh = new TaskManager($this->store, Fixtures::callContext(), null, null, $initial);
        $created = $fresh->ensureTask(new TaskStatusUpdateEvent(['task_id' => 'new-task', 'context_id' => 'new-ctx', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_WORKING])]));

        self::assertSame('new-task', $created->getId());
        self::assertSame('new-ctx', $created->getContextId());
        self::assertSame(TaskState::TASK_STATE_SUBMITTED, $created->getStatus()?->getState());
        self::assertSame([$initial->getMessageId()], array_map(static fn(Message $m): string => $m->getMessageId(), iterator_to_array($created->getHistory())));
        self::assertSame('new-task', $fresh->taskId);
        self::assertNotNull($this->store->get('new-task', Fixtures::callContext()));
    }

    public function testSaveTaskEventMismatchedIdsRaise(): void
    {
        $manager = new TaskManager($this->store, Fixtures::callContext(), 'task-abc', 'ctx-1');

        $this->expectException(InvalidParamsError::class);
        $manager->saveTaskEvent(Fixtures::task('wrong-task', TaskState::TASK_STATE_WORKING, 'ctx-1'));
    }

    public function testSaveTaskEventMismatchedContextRaises(): void
    {
        $manager = new TaskManager($this->store, Fixtures::callContext(), 'task-abc', 'ctx-1');

        $this->expectException(InvalidParamsError::class);
        $manager->saveTaskEvent(Fixtures::task('task-abc', TaskState::TASK_STATE_WORKING, 'other-ctx'));
    }

    public function testSaveTaskEventWithoutTaskIdAdoptsTheEventsIds(): void
    {
        $manager = new TaskManager($this->store, Fixtures::callContext(), null, null);

        $manager->saveTaskEvent(Fixtures::task('new-task-id', TaskState::TASK_STATE_WORKING, 'some-context'));

        self::assertSame('new-task-id', $manager->taskId);
        self::assertSame('some-context', $manager->contextId);
        self::assertNotNull($this->store->get('new-task-id', Fixtures::callContext()));
    }

    public function testSaveTaskEventWithNoExistingTaskCreatesIt(): void
    {
        $manager = new TaskManager($this->store, Fixtures::callContext(), null, null);

        $manager->saveTaskEvent(new TaskStatusUpdateEvent(['task_id' => 'event-task-id', 'context_id' => 'some-context', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_COMPLETED])]));

        self::assertSame(TaskState::TASK_STATE_COMPLETED, $this->store->get('event-task-id', Fixtures::callContext())?->getStatus()?->getState());
    }

    public function testUpdateWithMessageMovesStatusMessageToHistoryFirst(): void
    {
        $task = Fixtures::task('t', TaskState::TASK_STATE_INPUT_REQUIRED);
        $task->getStatus()?->setMessage(new Message(['message_id' => 'agent-question', 'role' => Role::ROLE_AGENT]));
        $manager = new TaskManager($this->store, Fixtures::callContext(), 't', 'ctx-1');

        $updated = $manager->updateWithMessage(Fixtures::userMessage('answer', 'user-answer'), $task);

        self::assertSame(['agent-question', 'user-answer'], array_map(static fn(Message $m): string => $m->getMessageId(), iterator_to_array($updated->getHistory())));
        self::assertNull($updated->getStatus()?->getMessage());
    }

    public function testAppendArtifactToTask(): void
    {
        $task = Fixtures::task('task-123', TaskState::TASK_STATE_WORKING);
        $event = static fn(string $id, string $text, bool $append = false, string $name = ''): TaskArtifactUpdateEvent => new TaskArtifactUpdateEvent([
            'task_id' => 'task-123',
            'context_id' => 'ctx-1',
            'artifact' => new Artifact(['artifact_id' => $id, 'name' => $name, 'parts' => [new Part(['text' => $text])]]),
            'append' => $append,
        ]);

        // A new artifact is added.
        TaskManager::appendArtifactToTask($task, $event('artifact-1', 'Hello', name: 'artifact-name'));
        self::assertCount(1, $task->getArtifacts());
        self::assertSame('artifact-name', $task->getArtifacts()[0]->getName());

        // The same id without append replaces it.
        TaskManager::appendArtifactToTask($task, $event('artifact-1', 'Replaced'));
        self::assertCount(1, $task->getArtifacts());
        self::assertSame('Replaced', $task->getArtifacts()[0]->getParts()[0]->getText());

        // append=true adds parts to it.
        TaskManager::appendArtifactToTask($task, $event('artifact-1', ' world', true));
        self::assertSame(['Replaced', ' world'], array_map(static fn(Part $p): string => $p->getText(), iterator_to_array($task->getArtifacts()[0]->getParts())));

        // A different id is a second artifact.
        TaskManager::appendArtifactToTask($task, $event('artifact-2', 'Other'));
        self::assertCount(2, $task->getArtifacts());

        // Appending to an artifact that doesn't exist is the agent's error.
        $this->expectException(InvalidAgentResponseError::class);
        TaskManager::appendArtifactToTask($task, $event('artifact-missing', 'x', true));
    }

    public function testRefreshRereadsTheStore(): void
    {
        $this->store->save(Fixtures::task('t', TaskState::TASK_STATE_WORKING), Fixtures::callContext());
        $manager = new TaskManager($this->store, Fixtures::callContext(), 't', 'ctx-1');
        self::assertSame(TaskState::TASK_STATE_WORKING, $manager->getTask()?->getStatus()?->getState());

        // Another process changes the task.
        $this->store->save(Fixtures::task('t', TaskState::TASK_STATE_CANCELED), Fixtures::callContext());

        self::assertSame(TaskState::TASK_STATE_WORKING, $manager->getTask()->getStatus()->getState(), 'cached');
        self::assertSame(TaskState::TASK_STATE_CANCELED, $manager->refresh()?->getStatus()?->getState());
    }

    public function testProcessIgnoresMessages(): void
    {
        $manager = new TaskManager($this->store, Fixtures::callContext(), 't', 'ctx-1');
        $message = Fixtures::userMessage();

        self::assertSame($message, $manager->process($message));
        self::assertNull($this->store->get('t', Fixtures::callContext()));
    }

    public function testTaskHistoryIsPreservedFromStoredTask(): void
    {
        $task = new Task(['id' => 't', 'context_id' => 'c', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_WORKING]), 'history' => [Fixtures::userMessage('one', 'm1')]]);
        $this->store->save($task, Fixtures::callContext());
        $manager = new TaskManager($this->store, Fixtures::callContext(), 't', 'c');

        $updated = $manager->saveTaskEvent(new TaskStatusUpdateEvent(['task_id' => 't', 'context_id' => 'c', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_COMPLETED])]));

        self::assertCount(1, $updated->getHistory());
    }
}
