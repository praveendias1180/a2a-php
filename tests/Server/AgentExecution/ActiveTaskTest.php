<?php

declare(strict_types=1);

namespace A2A\Tests\Server\AgentExecution;

use A2A\Server\AgentExecution\ActiveTask;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\AgentExecution\TaskCancelledException;
use A2A\Server\Events\EventQueue;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\Events\PublishedEvent;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskManager;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Task;
use A2A\Types\TaskState;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Errors\InvalidAgentResponseError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\TaskNotFoundError;
use PHPUnit\Framework\TestCase;

/**
 * ActiveTask drives the executor inside a Fiber. Behaviour mirrors a2a-python
 * tests/server/agent_execution/test_active_task.py where it applies; the
 * Fiber and cross-process cancellation cases are PHP-specific.
 */
final class ActiveTaskTest extends TestCase
{
    private InMemoryTaskStore $store;

    private InMemoryQueueManager $queues;

    protected function setUp(): void
    {
        $this->store = new InMemoryTaskStore();
        $this->queues = new InMemoryQueueManager();
    }

    public function testRunYieldsEachEventWhileTheExecutorIsStillRunning(): void
    {
        $log = [];
        $executor = new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u) use (&$log): void {
            $log[] = 'executor: working';
            $u->startWork();
            $log[] = 'executor: artifact';
            $u->addArtifact([new Part(['text' => 'out'])], artifactId: 'a');
            $log[] = 'executor: complete';
            $u->complete();
        }));
        [$active, $request] = $this->create($executor);

        foreach ($active->run($request) as $event) {
            $log[] = 'consumer: ' . self::describe($event);
        }

        self::assertSame([
            'consumer: Task SUBMITTED',
            'executor: working',
            'consumer: status WORKING',
            'executor: artifact',
            'consumer: artifact a',
            'executor: complete',
            'consumer: status COMPLETED',
        ], $log);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $this->store->get((string) $request->taskId(), Fixtures::callContext())?->getStatus()?->getState());
        self::assertCount(4, $this->queues->read((string) $request->taskId(), 0), 'every event is published for other subscribers');
    }

    public function testEachPublishedEventCarriesTheTaskSnapshot(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
            $u->startWork();
            $u->complete();
        })));

        $states = [];
        foreach ($active->run($request) as $event) {
            $states[] = Fixtures::stateName($event->task);
        }

        self::assertSame(['TASK_STATE_SUBMITTED', 'TASK_STATE_WORKING', 'TASK_STATE_COMPLETED'], $states);
    }

    public function testMessageOnlyReply(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(static function (RequestContext $c, EventQueue $q): void {
            $q->enqueueEvent(new Message(['message_id' => 'reply', 'role' => 2, 'parts' => [new Part(['text' => 'hi'])]]));
        }));

        $events = iterator_to_array($active->run($request), false);

        self::assertCount(1, $events);
        self::assertInstanceOf(Message::class, $events[0]->event);
        self::assertNull($events[0]->task);
        self::assertNull($this->store->get((string) $request->taskId(), Fixtures::callContext()), 'a message-only reply creates no task');
    }

    public function testMessageAfterTaskIsRejected(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u, RequestContext $c, EventQueue $q): void {
            $q->enqueueEvent(new Message(['message_id' => 'late', 'role' => 2]));
        })));

        $this->expectException(InvalidAgentResponseError::class);
        $this->expectExceptionMessage('Received Message object in task mode');
        iterator_to_array($active->run($request), false);
    }

    public function testTwoMessagesAreRejected(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(static function (RequestContext $c, EventQueue $q): void {
            $q->enqueueEvent(new Message(['message_id' => 'one', 'role' => 2]));
            $q->enqueueEvent(new Message(['message_id' => 'two', 'role' => 2]));
        }));

        $this->expectException(InvalidAgentResponseError::class);
        $this->expectExceptionMessage('Multiple Message objects received.');
        iterator_to_array($active->run($request), false);
    }

    public function testStatusUpdateBeforeTaskIsRejected(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(static function (RequestContext $c, EventQueue $q): void {
            (new TaskUpdater($q, (string) $c->taskId(), (string) $c->contextId()))->startWork();
        }));

        $this->expectException(InvalidAgentResponseError::class);
        $this->expectExceptionMessage('Agent should enqueue Task before TaskStatusUpdateEvent event');
        iterator_to_array($active->run($request), false);
    }

    public function testEventAfterTerminalStateIsRejected(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u, RequestContext $c, EventQueue $q): void {
            $u->complete();
            (new TaskUpdater($q, (string) $c->taskId(), (string) $c->contextId()))->startWork();
        })));

        $this->expectException(InvalidAgentResponseError::class);
        iterator_to_array($active->run($request), false);
    }

    public function testExecutorExceptionMarksTheTaskFailedAndPropagates(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
            $u->startWork();

            throw new \RuntimeException('boom');
        })));

        try {
            iterator_to_array($active->run($request), false);
            self::fail('expected the executor exception');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $taskId = (string) $request->taskId();
        self::assertSame(TaskState::TASK_STATE_FAILED, $this->store->get($taskId, Fixtures::callContext())?->getStatus()?->getState());
        $published = array_values($this->queues->read($taskId, 0));
        self::assertTrue(end($published) instanceof PublishedEvent && end($published)->isTerminal(), 'subscribers elsewhere see FAILED');
    }

    public function testFollowUpMessageIsAddedToHistory(): void
    {
        $store = $this->store;
        $store->save(new Task(['id' => 't-1', 'context_id' => 'c-1', 'status' => new \A2A\Types\TaskStatus(['state' => TaskState::TASK_STATE_INPUT_REQUIRED]), 'history' => [Fixtures::userMessage('first', 'm-1')]]), Fixtures::callContext());
        $executor = new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
            $u->complete();
        }));
        [$active, $request] = $this->create($executor, Fixtures::userMessage('second', 'm-2', 't-1', 'c-1'));

        iterator_to_array($active->run($request), false);

        $history = $store->get('t-1', Fixtures::callContext())?->getHistory();
        self::assertSame(['m-1', 'm-2'], array_map(static fn(Message $m): string => $m->getMessageId(), iterator_to_array($history ?? [])));
    }

    public function testCancelRequestedWhileRunningStopsTheExecutor(): void
    {
        $queues = $this->queues;
        $saw = new \A2A\Tests\Server\Support\Flag();
        $executor = new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u, RequestContext $c) use ($queues, $saw): void {
            $u->startWork();
            // Another request (process) asks to cancel while we work.
            $queues->requestCancel((string) $c->taskId());
            try {
                $u->addArtifact([new Part(['text' => 'never published'])]);
            } catch (TaskCancelledException) {
                $saw->set = true;

                throw new TaskCancelledException('stopping');
            }
        }));
        [$active, $request] = $this->create($executor);

        $events = iterator_to_array($active->run($request), false);

        self::assertTrue($saw->set, 'the executor was told it was cancelled');
        self::assertSame(1, $executor->cancelCalls);
        self::assertSame(['Task SUBMITTED', 'status WORKING'], array_map(self::describe(...), $events));
        self::assertSame(TaskState::TASK_STATE_CANCELED, $this->store->get((string) $request->taskId(), Fixtures::callContext())?->getStatus()?->getState());
        self::assertTrue($request->isCancelled());
    }

    public function testCancelOfAnIdleTask(): void
    {
        $this->store->save(Fixtures::task('t-1', TaskState::TASK_STATE_INPUT_REQUIRED, 'c-1'), Fixtures::callContext());
        $executor = new CallbackExecutor();
        $active = new ActiveTask($executor, 't-1', new TaskManager($this->store, Fixtures::callContext(), 't-1', 'c-1'), $this->queues);

        $task = $active->cancel(Fixtures::callContext());

        self::assertSame(1, $executor->cancelCalls);
        self::assertSame(TaskState::TASK_STATE_CANCELED, $task->getStatus()?->getState());
        self::assertTrue(array_values($this->queues->read('t-1', 0))[0]->isTerminal());
    }

    public function testCancelMarksTheTaskCanceledEvenIfTheExecutorDoesNot(): void
    {
        $this->store->save(Fixtures::task('t-1', TaskState::TASK_STATE_WORKING, 'c-1'), Fixtures::callContext());
        $executor = new CallbackExecutor(onCancel: static function (): void {});
        $active = new ActiveTask($executor, 't-1', new TaskManager($this->store, Fixtures::callContext(), 't-1', 'c-1'), $this->queues);

        self::assertSame(TaskState::TASK_STATE_CANCELED, $active->cancel(Fixtures::callContext())->getStatus()?->getState());
        self::assertSame(TaskState::TASK_STATE_CANCELED, $this->store->get('t-1', Fixtures::callContext())?->getStatus()?->getState());
    }

    public function testCancelFailureMarksTheTaskFailed(): void
    {
        $this->store->save(Fixtures::task('t-1', TaskState::TASK_STATE_WORKING, 'c-1'), Fixtures::callContext());
        $executor = new CallbackExecutor(onCancel: static function (): void {
            throw new \RuntimeException('cannot cancel');
        });
        $active = new ActiveTask($executor, 't-1', new TaskManager($this->store, Fixtures::callContext(), 't-1', 'c-1'), $this->queues);

        try {
            $active->cancel(Fixtures::callContext());
            self::fail('expected the cancel error');
        } catch (\RuntimeException $e) {
            self::assertSame('cannot cancel', $e->getMessage());
        }
        self::assertSame(TaskState::TASK_STATE_FAILED, $this->store->get('t-1', Fixtures::callContext())?->getStatus()?->getState());
    }

    public function testStartChecks(): void
    {
        $this->store->save(Fixtures::task('done', TaskState::TASK_STATE_COMPLETED), Fixtures::callContext());
        $manager = static fn(InMemoryTaskStore $s, string $id): TaskManager => new TaskManager($s, Fixtures::callContext(), $id, null);

        $new = new ActiveTask(new CallbackExecutor(), 'new', $manager($this->store, 'new'), $this->queues);
        self::assertNull($new->start(Fixtures::callContext(), createTaskIfMissing: true));

        try {
            (new ActiveTask(new CallbackExecutor(), 'missing', $manager($this->store, 'missing'), $this->queues))->start(Fixtures::callContext());
            self::fail('expected TaskNotFoundError');
        } catch (TaskNotFoundError) {
        }

        $this->expectException(InvalidParamsError::class);
        (new ActiveTask(new CallbackExecutor(), 'done', $manager($this->store, 'done'), $this->queues))->start(Fixtures::callContext());
    }

    public function testSuspendingTheSdkFiberWithANonEventIsAnError(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(static function (): void {
            \Fiber::suspend('not an event');
        }));

        $this->expectException(InvalidAgentResponseError::class);
        iterator_to_array($active->run($request), false);
    }

    public function testEventsEnqueuedFromAnotherFiberAreStillProcessed(): void
    {
        [$active, $request] = $this->create(new CallbackExecutor(static function (RequestContext $c, EventQueue $q): void {
            $message = $c->message();
            self::assertNotNull($message);
            $q->enqueueEvent(\A2A\Helpers\ProtoHelpers::newTaskFromUserMessage($message));
            // A library running its own Fiber publishes the result.
            $inner = new \Fiber(static function () use ($q, $c): void {
                (new TaskUpdater($q, (string) $c->taskId(), (string) $c->contextId()))->complete();
            });
            $inner->start();
        }));

        $events = array_map(self::describe(...), iterator_to_array($active->run($request), false));

        self::assertSame(['Task SUBMITTED', 'status COMPLETED'], $events);
    }

    /**
     * @return array{ActiveTask, RequestContext}
     */
    private function create(CallbackExecutor $executor, ?Message $message = null): array
    {
        $message ??= Fixtures::userMessage();
        $request = new RequestContext(Fixtures::callContext(), Fixtures::sendRequest($message), $message->getTaskId() ?: null, $message->getContextId() ?: null);
        $taskId = (string) $request->taskId();
        $active = new ActiveTask(
            $executor,
            $taskId,
            new TaskManager($this->store, Fixtures::callContext(), $taskId, $request->contextId(), $request->message()),
            $this->queues,
        );

        return [$active, $request];
    }

    private static function describe(PublishedEvent $published): string
    {
        $event = $published->event;

        return match (true) {
            $event instanceof Task => 'Task ' . substr(Fixtures::stateName($event), strlen('TASK_STATE_')),
            $event instanceof TaskStatusUpdateEvent => 'status ' . substr(\A2A\Server\Tasks\TaskStates::name($event->getStatus()?->getState() ?? 0), strlen('TASK_STATE_')),
            $event instanceof \A2A\Types\TaskArtifactUpdateEvent => 'artifact ' . $event->getArtifact()?->getArtifactId(),
            default => 'message',
        };
    }
}
