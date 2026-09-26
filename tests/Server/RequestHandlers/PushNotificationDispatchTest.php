<?php

declare(strict_types=1);

namespace A2A\Tests\Server\RequestHandlers;

use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Tasks\BasePushNotificationSender;
use A2A\Server\Tasks\InMemoryPushNotificationConfigStore;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\PushNotificationSender;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Tests\Client\Support\FakeHttpSender;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\CancelTaskRequest;
use A2A\Types\Part;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\PushUrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * The request handler sends every task-mode event to the push sender, in
 * order, as Python's ActiveTask does (tests/server/agent_execution and the
 * push parts of test_default_request_handler_v2.py).
 */
final class PushNotificationDispatchTest extends TestCase
{
    /** @var list<array{string, string}> task id, event kind */
    private array $sent = [];

    public function testEveryTaskEventIsPushedInOrder(): void
    {
        $handler = $this->handler($this->recorder(), static function (TaskUpdater $updater): void {
            $updater->startWork();
            $updater->addArtifact([new Part(['text' => 'hi'])], name: 'answer');
            $updater->complete();
        });

        $task = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());

        self::assertInstanceOf(Task::class, $task);
        self::assertSame(['task', 'status', 'artifact', 'status'], array_column($this->sent, 1));
        self::assertSame([$task->getId()], array_values(array_unique(array_column($this->sent, 0))));
    }

    public function testAMessageReplyIsNotPushed(): void
    {
        $executor = new CallbackExecutor(static function (RequestContext $context, EventQueue $queue): void {
            $queue->enqueueEvent(\A2A\Helpers\ProtoHelpers::newTextMessage('direct answer'));
        });
        $handler = new DefaultRequestHandler($executor, new InMemoryTaskStore(), Fixtures::agentCard(push: true), new InMemoryQueueManager(), pushSender: $this->recorder());

        $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());

        self::assertSame([], $this->sent);
    }

    public function testCancelIsPushed(): void
    {
        $handler = $this->handler($this->recorder(), static function (TaskUpdater $updater): void {
            $updater->requiresInput();
        });
        $task = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());
        self::assertInstanceOf(Task::class, $task);
        $this->sent = [];

        $handler->onCancelTask(new CancelTaskRequest(['id' => $task->getId()]), Fixtures::callContext());

        self::assertSame(['status'], array_column($this->sent, 1));
    }

    public function testAFailingSenderNeverFailsTheTask(): void
    {
        $throwing = new class implements PushNotificationSender {
            public function sendNotification(string $taskId, Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
            {
                throw new \RuntimeException('webhook exploded');
            }
        };
        $handler = $this->handler($throwing, static function (TaskUpdater $updater): void {
            $updater->complete();
        });

        $task = $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), Fixtures::callContext());

        self::assertInstanceOf(Task::class, $task);
        self::assertSame('TASK_STATE_COMPLETED', Fixtures::stateName($task));
    }

    public function testAConfigSentWithTheMessageReceivesTheTasksUpdates(): void
    {
        $store = new InMemoryPushNotificationConfigStore();
        $http = new FakeHttpSender();
        $http->queueBody('')->queueBody('');
        $validator = new PushUrlValidator(static fn(string $host): array => ['93.184.215.14']);
        $sender = new BasePushNotificationSender($store, $http, pushUrlValidator: $validator, sleep: static function (float $s): void {});
        $handler = new DefaultRequestHandler(
            new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $updater): void {
                $updater->complete();
            })),
            new InMemoryTaskStore(),
            Fixtures::agentCard(push: true),
            new InMemoryQueueManager(),
            pushConfigStore: $store,
            pushUrlValidator: $validator,
            pushSender: $sender,
        );
        $configuration = new SendMessageConfiguration([
            'task_push_notification_config' => new TaskPushNotificationConfig(['url' => 'https://hooks.example/a2a', 'token' => 'tok']),
        ]);

        $handler->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage(), $configuration), Fixtures::callContext('alice'));

        self::assertCount(2, $http->requests);
        $first = json_decode((string) $http->requests[0]->body, true);
        $last = json_decode((string) $http->requests[1]->body, true);
        self::assertIsArray($first);
        self::assertIsArray($last);
        self::assertArrayHasKey('task', $first);
        $status = $last['statusUpdate'] ?? null;
        self::assertIsArray($status);
        self::assertSame(['state' => 'TASK_STATE_COMPLETED'], array_intersect_key(is_array($status['status'] ?? null) ? $status['status'] : [], ['state' => true]));
        self::assertSame('tok', $http->requests[1]->headers[BasePushNotificationSender::TOKEN_HEADER] ?? null);
    }

    /**
     * @param \Closure(TaskUpdater): void $steps
     */
    private function handler(PushNotificationSender $sender, \Closure $steps): DefaultRequestHandler
    {
        return new DefaultRequestHandler(
            new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $updater) use ($steps): void {
                $steps($updater);
            })),
            new InMemoryTaskStore(),
            Fixtures::agentCard(push: true),
            new InMemoryQueueManager(),
            pushSender: $sender,
            cancelTimeoutSeconds: 0.2,
        );
    }

    private function recorder(): PushNotificationSender
    {
        return new class (function (string $taskId, string $kind): void {
            $this->sent[] = [$taskId, $kind];
        }) implements PushNotificationSender {
            /**
             * @param \Closure(string, string): void $record
             */
            public function __construct(private readonly \Closure $record) {}

            public function sendNotification(string $taskId, Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
            {
                ($this->record)($taskId, match (true) {
                    $event instanceof Task => 'task',
                    $event instanceof TaskStatusUpdateEvent => 'status',
                    default => 'artifact',
                });
            }
        };
    }
}
