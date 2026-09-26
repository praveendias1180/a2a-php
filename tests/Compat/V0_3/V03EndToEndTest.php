<?php

declare(strict_types=1);

namespace A2A\Tests\Compat\V0_3;

use A2A\Client\BaseClient;
use A2A\Client\Client;
use A2A\Client\ClientConfig;
use A2A\Client\ClientFactory;
use A2A\Compat\V0_3\CompatJsonRpcTransport;
use A2A\Compat\V0_3\CompatRestTransport;
use A2A\Helpers\ProtoHelpers;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\Router;
use A2A\Server\Routes\Routes;
use A2A\Server\Tasks\InMemoryPushNotificationConfigStore;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Tests\Client\Support\Reflect;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Tests\Server\Support\InProcessHttpSender;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\CancelTaskRequest;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\Part;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskState;
use A2A\Utils\Errors\TaskNotCancelableError;
use A2A\Utils\Errors\TaskNotFoundError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP client against the PHP server, both speaking A2A v0.3: the card
 * only offers v0.3 interfaces, so ClientFactory picks the Compat\V0_3
 * transports, and the server answers through its v0.3 adapters. In one
 * process; the cross-SDK versions run in scripts/run-python-interop.sh.
 */
final class V03EndToEndTest extends TestCase
{
    private DefaultRequestHandler $handler;

    private Router $router;

    private AgentCard $card;

    protected function setUp(): void
    {
        $executor = new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u, RequestContext $c): void {
            if (str_contains($c->getUserInput(), 'wait')) {
                $u->requiresInput($u->newAgentMessage([new Part(['text' => 'what next?'])]));

                return;
            }
            $u->startWork();
            $u->addArtifact([new Part(['text' => 'Hello, ' . $c->getUserInput()])], name: 'response', lastChunk: true);
            $u->complete();
        }));
        $this->card = Fixtures::agentCard(push: true);
        $this->card->setSupportedInterfaces([
            new AgentInterface(['url' => Fixtures::BASE_URL . '/a2a/jsonrpc', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '0.3']),
            new AgentInterface(['url' => Fixtures::BASE_URL . '/a2a/rest', 'protocol_binding' => 'HTTP+JSON', 'protocol_version' => '0.3']),
        ]);
        $this->handler = new DefaultRequestHandler(
            agentExecutor: $executor,
            taskStore: new InMemoryTaskStore(),
            agentCard: $this->card,
            queueManager: new InMemoryQueueManager(),
            pushConfigStore: new InMemoryPushNotificationConfigStore(),
            subscribePollSeconds: 0.01,
            maxSubscribeIdleSeconds: 0.1,
        );
        $this->router = Routes::router($this->handler, $this->card, jsonRpcPath: '/a2a/jsonrpc', restPrefix: '/a2a/rest', enableV03Compat: true);
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function bindings(): iterable
    {
        yield 'JSON-RPC' => ['JSONRPC', CompatJsonRpcTransport::class];
        yield 'HTTP+JSON' => ['HTTP+JSON', CompatRestTransport::class];
    }

    /**
     * @param class-string $transportClass
     */
    #[DataProvider('bindings')]
    public function testTheServedCardLeadsTheClientToTheV03Transports(string $binding, string $transportClass): void
    {
        $client = $this->client($binding);

        self::assertInstanceOf($transportClass, Reflect::get($client, 'transport'));
    }

    #[DataProvider('bindings')]
    public function testStreamingSend(string $binding): void
    {
        $events = iterator_to_array($this->client($binding)->sendMessage(Fixtures::sendRequest(Fixtures::userMessage('world'))), false);

        self::assertSame(['task', 'status_update', 'artifact_update', 'status_update'], array_map(static fn(StreamResponse $e): string => $e->getPayload(), $events));
        $artifact = $events[2]->getArtifactUpdate()?->getArtifact();
        self::assertNotNull($artifact);
        self::assertSame('Hello, world', ProtoHelpers::getArtifactText($artifact));
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $events[3]->getStatusUpdate()?->getStatus()?->getState());
    }

    #[DataProvider('bindings')]
    public function testBlockingSendAndGet(string $binding): void
    {
        $client = $this->client($binding, streaming: false);
        $task = $this->sendForTask($client, 'there');

        self::assertSame(TaskState::TASK_STATE_COMPLETED, $task->getStatus()?->getState());
        $got = $client->getTask(new GetTaskRequest(['id' => $task->getId(), 'history_length' => 1]));
        self::assertSame($task->getId(), $got->getId());
        self::assertCount(1, $got->getHistory());
    }

    #[DataProvider('bindings')]
    public function testReturnImmediately(string $binding): void
    {
        $client = $this->client($binding, streaming: false);
        $request = Fixtures::sendRequest(Fixtures::userMessage('later'), new SendMessageConfiguration(['return_immediately' => true]));

        $task = iterator_to_array($client->sendMessage($request), false)[0]->getTask();

        self::assertNotNull($task);
        self::assertSame(TaskState::TASK_STATE_SUBMITTED, $task->getStatus()?->getState());
    }

    #[DataProvider('bindings')]
    public function testMultiTurnSubscribeAndCancel(string $binding): void
    {
        $client = $this->client($binding, streaming: false);
        $task = $this->sendForTask($client, 'wait for me');
        self::assertSame(TaskState::TASK_STATE_INPUT_REQUIRED, $task->getStatus()?->getState());

        $subscribed = iterator_to_array($this->client($binding)->subscribe(new SubscribeToTaskRequest(['id' => $task->getId()])), false);
        self::assertSame($task->getId(), $subscribed[0]->getTask()?->getId());

        self::assertSame(TaskState::TASK_STATE_CANCELED, $client->cancelTask(new CancelTaskRequest(['id' => $task->getId()]))->getStatus()?->getState());

        $this->expectException(TaskNotCancelableError::class);
        $client->cancelTask(new CancelTaskRequest(['id' => $task->getId()]));
    }

    #[DataProvider('bindings')]
    public function testErrorsAreTyped(string $binding): void
    {
        $this->expectException(TaskNotFoundError::class);
        $this->client($binding)->getTask(new GetTaskRequest(['id' => 'nope']));
    }

    #[DataProvider('bindings')]
    public function testPushNotificationConfigs(string $binding): void
    {
        $client = $this->client($binding, streaming: false);
        $task = $this->sendForTask($client, 'wait for push');
        $config = new TaskPushNotificationConfig(['task_id' => $task->getId(), 'id' => 'cfg-1', 'url' => 'https://example.com/hook', 'token' => 'secret']);

        $created = $client->createTaskPushNotificationConfig($config);
        self::assertSame('cfg-1', $created->getId());
        self::assertSame($task->getId(), $created->getTaskId());
        self::assertSame('https://example.com/hook', $created->getUrl());

        $got = $client->getTaskPushNotificationConfig(new GetTaskPushNotificationConfigRequest(['task_id' => $task->getId(), 'id' => 'cfg-1']));
        self::assertSame('secret', $got->getToken());

        $listed = $client->listTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest(['task_id' => $task->getId()]));
        self::assertCount(1, $listed->getConfigs());

        $client->deleteTaskPushNotificationConfig(new DeleteTaskPushNotificationConfigRequest(['task_id' => $task->getId(), 'id' => 'cfg-1']));
        self::assertCount(0, $client->listTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest(['task_id' => $task->getId()]))->getConfigs());
    }

    #[DataProvider('bindings')]
    public function testListTasksIsNotPartOfV03(string $binding): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->client($binding)->listTasks(new \A2A\Types\ListTasksRequest());
    }

    private function client(string $binding, bool $streaming = true): Client
    {
        $client = ClientFactory::createClient(Fixtures::BASE_URL, new ClientConfig(streaming: $streaming, httpClient: new InProcessHttpSender($this->router, $this->handler), supportedProtocolBindings: [$binding]));
        self::assertInstanceOf(BaseClient::class, $client);

        return $client;
    }

    private function sendForTask(Client $client, string $text): Task
    {
        $events = iterator_to_array($client->sendMessage(Fixtures::sendRequest(Fixtures::userMessage($text))), false);
        $task = end($events) instanceof StreamResponse ? end($events)->getTask() : null;
        self::assertInstanceOf(Task::class, $task);

        return $task;
    }
}
