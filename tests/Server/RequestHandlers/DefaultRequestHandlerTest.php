<?php

declare(strict_types=1);

namespace A2A\Tests\Server\RequestHandlers;

use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\Events\PublishedEvent;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\InMemoryPushNotificationConfigStore;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\AgentCard;
use A2A\Types\Artifact;
use A2A\Types\CancelTaskRequest;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Errors\ExtendedAgentCardNotConfiguredError;
use A2A\Utils\Errors\InternalError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\PushNotificationNotSupportedError;
use A2A\Utils\Errors\TaskNotCancelableError;
use A2A\Utils\Errors\TaskNotFoundError;
use A2A\Utils\Errors\UnsupportedOperationError;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors a2a-python tests/server/request_handlers/
 * test_default_request_handler_v2.py for the behaviour both SDKs share, plus
 * the PHP-specific paths (QueueManager subscribe, deferred work).
 */
final class DefaultRequestHandlerTest extends TestCase
{
    private InMemoryTaskStore $store;

    private InMemoryQueueManager $queues;

    protected function setUp(): void
    {
        $this->store = new InMemoryTaskStore();
        $this->queues = new InMemoryQueueManager();
    }

    public function testGetTask(): void
    {
        $task = Fixtures::task('t-1', TaskState::TASK_STATE_WORKING);
        $task->setHistory([Fixtures::userMessage('a', 'm1'), Fixtures::userMessage('b', 'm2'), Fixtures::userMessage('c', 'm3')]);
        $this->store->save($task, Fixtures::callContext());
        $handler = $this->handler();

        self::assertCount(3, $handler->onGetTask(new GetTaskRequest(['id' => 't-1']), Fixtures::callContext())->getHistory());
        $limited = $handler->onGetTask(new GetTaskRequest(['id' => 't-1', 'history_length' => 1]), Fixtures::callContext());
        self::assertSame(['m3'], array_map(static fn(Message $m): string => $m->getMessageId(), iterator_to_array($limited->getHistory())));
    }

    public function testGetTaskErrors(): void
    {
        $handler = $this->handler();
        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onGetTask(new GetTaskRequest(['id' => 'missing']), Fixtures::callContext()));
        $this->assertThrows(InvalidParamsError::class, static fn() => $handler->onGetTask(new GetTaskRequest(['id' => 'x', 'history_length' => -1]), Fixtures::callContext()));
        $this->assertThrows(InvalidParamsError::class, static fn() => $handler->onGetTask(new GetTaskRequest(), Fixtures::callContext()), 'id is required');
    }

    public function testOtherUsersTasksAreInvisible(): void
    {
        $handler = $this->handler();
        $task = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext('alice'));
        self::assertInstanceOf(Task::class, $task);
        $bob = Fixtures::callContext('bob');

        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onGetTask(new GetTaskRequest(['id' => $task->getId()]), $bob));
        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onCancelTask(new CancelTaskRequest(['id' => $task->getId()]), $bob));
        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage('x', null, $task->getId())), $bob));
        self::assertCount(0, $handler->onListTasks(new ListTasksRequest(), $bob)->getTasks());
    }

    public function testListTasksStripsArtifactsUnlessAskedAndAppliesHistoryLength(): void
    {
        $task = Fixtures::task('t-1', TaskState::TASK_STATE_COMPLETED, 'c', 1);
        $task->setArtifacts([new Artifact(['artifact_id' => 'a', 'parts' => [new Part(['text' => 'x'])]])]);
        $task->setHistory([Fixtures::userMessage('a', 'm1'), Fixtures::userMessage('b', 'm2')]);
        $this->store->save($task, Fixtures::callContext());
        $handler = $this->handler();

        $plain = $handler->onListTasks(new ListTasksRequest(), Fixtures::callContext())->getTasks()[0];
        self::assertCount(0, $plain->getArtifacts());
        self::assertCount(2, $plain->getHistory());

        $full = $handler->onListTasks(new ListTasksRequest(['include_artifacts' => true, 'history_length' => 1]), Fixtures::callContext())->getTasks()[0];
        self::assertCount(1, $full->getArtifacts());
        self::assertCount(1, $full->getHistory());

        $this->assertThrows(InvalidParamsError::class, static fn() => $handler->onListTasks(new ListTasksRequest(['page_size' => 101]), Fixtures::callContext()));
    }

    public function testSendMessageBlocksUntilTheTaskFinishes(): void
    {
        $handler = $this->handler(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
            $u->startWork();
            $u->addArtifact([new Part(['text' => 'answer'])], name: 'response');
            $u->complete();
        })));

        $result = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage('question', 'm-1')), Fixtures::callContext());

        self::assertInstanceOf(Task::class, $result);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $result->getStatus()?->getState());
        self::assertSame('answer', $result->getArtifacts()[0]->getParts()[0]->getText());
        self::assertSame(['m-1'], array_map(static fn(Message $m): string => $m->getMessageId(), iterator_to_array($result->getHistory())));
    }

    public function testSendMessageReturnsAMessageReply(): void
    {
        $handler = $this->handler(new CallbackExecutor(static function (RequestContext $c, EventQueue $q): void {
            $q->enqueueEvent(new Message(['message_id' => 'reply', 'role' => 2, 'parts' => [new Part(['text' => 'direct'])]]));
        }));

        $result = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());

        self::assertInstanceOf(Message::class, $result);
        self::assertSame('reply', $result->getMessageId());
    }

    public function testSendMessageStopsAtAnInterruptedState(): void
    {
        $after = new \A2A\Tests\Server\Support\Flag();
        $handler = $this->handler(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u) use ($after): void {
            $u->requiresInput();
            $after->set = true;
        })));

        $result = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());

        self::assertSame(TaskState::TASK_STATE_INPUT_REQUIRED, $result instanceof Task ? $result->getStatus()?->getState() : null);
        self::assertFalse($after->set, 'nothing more runs before the response');
        $handler->runBackgroundWork();
        self::assertTrue($after->set, 'the executor finishes after the response');
    }

    public function testReturnImmediatelyAnswersAtOnceAndFinishesLater(): void
    {
        $handler = $this->handler(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
            $u->startWork();
            $u->complete();
        })));
        $request = Fixtures::sendRequest(Fixtures::userMessage(), new SendMessageConfiguration(['return_immediately' => true]));

        $result = $handler->onMessageSend($request, Fixtures::callContext());

        self::assertInstanceOf(Task::class, $result);
        self::assertSame(TaskState::TASK_STATE_SUBMITTED, $result->getStatus()?->getState());
        self::assertTrue($this->queues->hasActiveRunLease($result->getId()), 'still running');

        $handler->runBackgroundWork();

        self::assertSame(TaskState::TASK_STATE_COMPLETED, $this->store->get($result->getId(), Fixtures::callContext())?->getStatus()?->getState());
        self::assertFalse($this->queues->hasActiveRunLease($result->getId()));
    }

    public function testFailedTaskIsReturnedBeforeTheExecutorError(): void
    {
        $handler = $this->handler(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
            $u->failed();
        })));

        $result = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());

        self::assertSame(TaskState::TASK_STATE_FAILED, $result instanceof Task ? $result->getStatus()?->getState() : null);
    }

    public function testExecutorErrorPropagates(): void
    {
        $handler = $this->handler(new CallbackExecutor(static function (): void {
            throw new InternalError('agent broke');
        }));

        $this->expectException(InternalError::class);
        $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());
    }

    public function testFollowUpContinuesTheTaskAndInfersItsContext(): void
    {
        $handler = $this->handler(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u, RequestContext $c): void {
            str_contains($c->getUserInput(), 'more') ? $u->requiresInput() : $u->complete();
        })));
        $first = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage('need more')), Fixtures::callContext());
        self::assertInstanceOf(Task::class, $first);
        $handler->runBackgroundWork();

        $second = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage('thanks', null, $first->getId())), Fixtures::callContext());

        self::assertInstanceOf(Task::class, $second);
        self::assertSame($first->getId(), $second->getId());
        self::assertSame($first->getContextId(), $second->getContextId());
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $second->getStatus()?->getState());
        self::assertCount(2, $second->getHistory());
    }

    public function testSendMessageErrors(): void
    {
        $this->store->save(Fixtures::task('done', TaskState::TASK_STATE_COMPLETED, 'ctx-1'), Fixtures::callContext());
        $this->store->save(Fixtures::task('open', TaskState::TASK_STATE_INPUT_REQUIRED, 'ctx-1'), Fixtures::callContext());
        $handler = $this->handler();

        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage('x', null, 'missing')), Fixtures::callContext()));
        $this->assertThrows(UnsupportedOperationError::class, static fn() => $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage('x', null, 'done')), Fixtures::callContext()));
        $this->assertThrows(InvalidParamsError::class, static fn() => $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage('x', null, 'open', 'other-ctx')), Fixtures::callContext()));
        $this->assertThrows(InvalidParamsError::class, static fn() => $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage(), new SendMessageConfiguration(['history_length' => -1])), Fixtures::callContext()));
    }

    public function testTaskIdMismatchInAgentResponseIsAnInternalError(): void
    {
        $handler = $this->handler(new CallbackExecutor(static function (RequestContext $c, EventQueue $q): void {
            $q->enqueueEvent(Fixtures::task('someone-elses-id', TaskState::TASK_STATE_COMPLETED, (string) $c->contextId()));
        }));

        $this->expectException(\A2A\Utils\Errors\A2AError::class);
        $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());
    }

    public function testStreamYieldsEveryEvent(): void
    {
        $handler = $this->handler(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
            $u->startWork();
            $u->addArtifact([new Part(['text' => 'x'])]);
            $u->complete();
        })));

        $events = iterator_to_array($handler->onMessageSendStream(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext()), false);

        self::assertSame(['Task', 'TaskStatusUpdateEvent', 'TaskArtifactUpdateEvent', 'TaskStatusUpdateEvent'], array_map(static fn(object $e): string => substr(strrchr($e::class, '\\') ?: '', 1), array_filter($events)));
    }

    public function testStreamingRequiresTheCapability(): void
    {
        $handler = $this->handler(card: Fixtures::agentCard(streaming: false));

        $this->assertThrows(UnsupportedOperationError::class, static fn() => $handler->onMessageSendStream(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext())->current());
        $this->assertThrows(UnsupportedOperationError::class, static fn() => $handler->onSubscribeToTask(new SubscribeToTaskRequest(['id' => 'x']), Fixtures::callContext())->current());
    }

    public function testCancel(): void
    {
        $this->store->save(Fixtures::task('open', TaskState::TASK_STATE_INPUT_REQUIRED, 'ctx-1'), Fixtures::callContext());
        $this->store->save(Fixtures::task('done', TaskState::TASK_STATE_COMPLETED, 'ctx-1'), Fixtures::callContext());
        $executor = new CallbackExecutor();
        $handler = $this->handler($executor);

        $task = $handler->onCancelTask(new CancelTaskRequest(['id' => 'open']), Fixtures::callContext());

        self::assertSame(TaskState::TASK_STATE_CANCELED, $task->getStatus()?->getState());
        self::assertSame(1, $executor->cancelCalls);
        self::assertTrue($this->queues->isCancelRequested('open'));
        $this->assertThrows(TaskNotCancelableError::class, static fn() => $handler->onCancelTask(new CancelTaskRequest(['id' => 'done']), Fixtures::callContext()));
        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onCancelTask(new CancelTaskRequest(['id' => 'missing']), Fixtures::callContext()));
    }

    public function testCancelWaitsForATaskRunningElsewhere(): void
    {
        $this->store->save(Fixtures::task('busy', TaskState::TASK_STATE_WORKING, 'ctx-1'), Fixtures::callContext());
        $this->queues->acquireRunLease('busy', 60);
        $executor = new CallbackExecutor();
        $handler = $this->handler($executor, cancelTimeoutSeconds: 0.2);

        // Nobody stops it within the timeout, so the cancel request does.
        $task = $handler->onCancelTask(new CancelTaskRequest(['id' => 'busy']), Fixtures::callContext());

        self::assertSame(TaskState::TASK_STATE_CANCELED, $task->getStatus()?->getState());
        self::assertSame(1, $executor->cancelCalls);
    }

    public function testSubscribeYieldsTheTaskThenEventsFromOtherRequestsUntilTerminal(): void
    {
        $this->store->save(Fixtures::task('t-1', TaskState::TASK_STATE_INPUT_REQUIRED, 'ctx-1'), Fixtures::callContext());
        $queues = $this->queues;
        $handler = $this->handler();

        $stream = $handler->onSubscribeToTask(new SubscribeToTaskRequest(['id' => 't-1']), Fixtures::callContext());
        $first = $stream->current();
        self::assertInstanceOf(Task::class, $first);

        // Another request (process) moves the task along.
        $queues->publish('t-1', new PublishedEvent(self::statusEvent('t-1', TaskState::TASK_STATE_WORKING), Fixtures::task('t-1', TaskState::TASK_STATE_WORKING)));
        $queues->publish('t-1', new PublishedEvent(self::statusEvent('t-1', TaskState::TASK_STATE_COMPLETED), Fixtures::task('t-1', TaskState::TASK_STATE_COMPLETED)));

        $rest = [];
        for ($stream->next(); $stream->valid(); $stream->next()) {
            $rest[] = $stream->current();
        }

        self::assertCount(2, $rest);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $rest[1] instanceof TaskStatusUpdateEvent ? $rest[1]->getStatus()?->getState() : null);
    }

    public function testSubscribeErrorsAndIdleLimit(): void
    {
        $this->store->save(Fixtures::task('done', TaskState::TASK_STATE_COMPLETED), Fixtures::callContext());
        $this->store->save(Fixtures::task('quiet', TaskState::TASK_STATE_INPUT_REQUIRED), Fixtures::callContext());
        $handler = $this->handler(keepAliveSeconds: 0.05, maxSubscribeIdleSeconds: 0.3);

        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onSubscribeToTask(new SubscribeToTaskRequest(['id' => 'missing']), Fixtures::callContext())->current());
        $this->assertThrows(UnsupportedOperationError::class, static fn() => $handler->onSubscribeToTask(new SubscribeToTaskRequest(['id' => 'done']), Fixtures::callContext())->current());

        $events = iterator_to_array($handler->onSubscribeToTask(new SubscribeToTaskRequest(['id' => 'quiet']), Fixtures::callContext()), false);
        self::assertInstanceOf(Task::class, $events[0]);
        self::assertContains(null, $events, 'keep-alive ticks while idle');
    }

    public function testPushNotificationConfigs(): void
    {
        $this->store->save(Fixtures::task('t-1', TaskState::TASK_STATE_WORKING), Fixtures::callContext());
        $handler = $this->handler(card: Fixtures::agentCard(push: true), pushStore: new InMemoryPushNotificationConfigStore(), pushUrlValidator: static fn(string $url): bool => str_starts_with($url, 'https://'));
        $ctx = Fixtures::callContext();

        $created = $handler->onCreateTaskPushNotificationConfig(new TaskPushNotificationConfig(['task_id' => 't-1', 'id' => 'cfg-1', 'url' => 'https://hooks.example/a2a']), $ctx);
        self::assertSame('cfg-1', $created->getId());
        self::assertSame('https://hooks.example/a2a', $handler->onGetTaskPushNotificationConfig(new GetTaskPushNotificationConfigRequest(['task_id' => 't-1', 'id' => 'cfg-1']), $ctx)->getUrl());
        self::assertCount(1, $handler->onListTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest(['task_id' => 't-1']), $ctx)->getConfigs());

        $handler->onDeleteTaskPushNotificationConfig(new DeleteTaskPushNotificationConfigRequest(['task_id' => 't-1', 'id' => 'cfg-1']), $ctx);
        self::assertCount(0, $handler->onListTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest(['task_id' => 't-1']), $ctx)->getConfigs());

        $this->assertThrows(InvalidParamsError::class, static fn() => $handler->onCreateTaskPushNotificationConfig(new TaskPushNotificationConfig(['task_id' => 't-1', 'url' => 'http://10.0.0.1/']), $ctx));
        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onCreateTaskPushNotificationConfig(new TaskPushNotificationConfig(['task_id' => 'missing', 'url' => 'https://x']), $ctx));
        $this->assertThrows(TaskNotFoundError::class, static fn() => $handler->onGetTaskPushNotificationConfig(new GetTaskPushNotificationConfigRequest(['task_id' => 't-1', 'id' => 'nope']), $ctx));
    }

    public function testPushNotificationsNotSupported(): void
    {
        $handler = $this->handler();

        $this->assertThrows(PushNotificationNotSupportedError::class, static fn() => $handler->onCreateTaskPushNotificationConfig(new TaskPushNotificationConfig(['task_id' => 't', 'url' => 'https://x']), Fixtures::callContext()));
        $this->assertThrows(PushNotificationNotSupportedError::class, static fn() => $handler->onListTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest(['task_id' => 't']), Fixtures::callContext()));
    }

    public function testExtendedAgentCard(): void
    {
        $this->assertThrows(UnsupportedOperationError::class, fn() => $this->handler()->onGetExtendedAgentCard(new GetExtendedAgentCardRequest(), Fixtures::callContext()));
        $this->assertThrows(ExtendedAgentCardNotConfiguredError::class, fn() => $this->handler(card: Fixtures::agentCard(extended: true))->onGetExtendedAgentCard(new GetExtendedAgentCardRequest(), Fixtures::callContext()));

        $extended = Fixtures::agentCard(extended: true);
        $extended->setName('Extended');
        $handler = new DefaultRequestHandler(
            new CallbackExecutor(),
            $this->store,
            Fixtures::agentCard(extended: true),
            $this->queues,
            extendedAgentCard: $extended,
            extendedCardModifier: static function (AgentCard $card, ServerCallContext $context): AgentCard {
                $copy = new AgentCard();
                $copy->mergeFrom($card);
                $copy->setDescription('for ' . $context->user->userName());

                return $copy;
            },
        );

        $card = $handler->onGetExtendedAgentCard(new GetExtendedAgentCardRequest(), Fixtures::callContext('alice'));
        self::assertSame('Extended', $card->getName());
        self::assertSame('for alice', $card->getDescription());
    }

    private function handler(
        ?CallbackExecutor $executor = null,
        ?AgentCard $card = null,
        ?InMemoryPushNotificationConfigStore $pushStore = null,
        ?\Closure $pushUrlValidator = null,
        float $cancelTimeoutSeconds = 10.0,
        float $keepAliveSeconds = 15.0,
        ?float $maxSubscribeIdleSeconds = null,
    ): DefaultRequestHandler {
        return new DefaultRequestHandler(
            agentExecutor: $executor ?? new CallbackExecutor(),
            taskStore: $this->store,
            agentCard: $card ?? Fixtures::agentCard(),
            queueManager: $this->queues,
            pushConfigStore: $pushStore,
            pushUrlValidator: $pushUrlValidator,
            keepAliveSeconds: $keepAliveSeconds,
            cancelTimeoutSeconds: $cancelTimeoutSeconds,
            subscribePollSeconds: 0.02,
            maxSubscribeIdleSeconds: $maxSubscribeIdleSeconds,
        );
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private function assertThrows(string $expected, \Closure $call, string $message = ''): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e, $message);

            return;
        }
        self::fail("Expected {$expected}. {$message}");
    }

    private static function statusEvent(string $taskId, int $state): TaskStatusUpdateEvent
    {
        return new TaskStatusUpdateEvent(['task_id' => $taskId, 'context_id' => 'ctx-1', 'status' => new TaskStatus(['state' => $state])]);
    }
}
