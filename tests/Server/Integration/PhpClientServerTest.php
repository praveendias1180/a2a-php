<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Integration;

use A2A\Client\Client;
use A2A\Client\ClientConfig;
use A2A\Client\ClientFactory;
use A2A\Helpers\ProtoHelpers;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\Routes;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Tests\Server\Support\InProcessHttpSender;
use A2A\Types\CancelTaskRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\Part;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskState;
use A2A\Utils\Errors\TaskNotCancelableError;
use A2A\Utils\Errors\TaskNotFoundError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SDK's own client against the SDK's own server, in one process, over
 * both bindings. The cross-process and cross-SDK versions of this run in CI
 * (scripts/run-python-interop.sh and the A2A TCK job).
 */
final class PhpClientServerTest extends TestCase
{
    private DefaultRequestHandler $handler;

    private \A2A\Server\Routes\Router $router;

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
        $this->handler = new DefaultRequestHandler(
            agentExecutor: $executor,
            taskStore: new InMemoryTaskStore(),
            agentCard: Fixtures::agentCard(),
            queueManager: new InMemoryQueueManager(),
            subscribePollSeconds: 0.01,
            maxSubscribeIdleSeconds: 0.1,
        );
        $this->router = Routes::router($this->handler, Fixtures::agentCard(), jsonRpcPath: '/a2a/jsonrpc', restPrefix: '/a2a/rest');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bindings(): iterable
    {
        yield 'JSON-RPC' => ['JSONRPC'];
        yield 'HTTP+JSON' => ['HTTP+JSON'];
    }

    #[DataProvider('bindings')]
    public function testCardResolution(string $binding): void
    {
        $client = ClientFactory::createClient(Fixtures::BASE_URL, new ClientConfig(httpClient: $this->sender(), supportedProtocolBindings: [$binding]));

        self::assertInstanceOf(Client::class, $client);
    }

    #[DataProvider('bindings')]
    public function testStreamingSend(string $binding): void
    {
        $events = iterator_to_array($this->client($binding)->sendMessage(Fixtures::sendRequest(Fixtures::userMessage('world'))), false);

        self::assertSame(['task', 'status_update', 'artifact_update', 'status_update'], array_map(static fn(StreamResponse $e): string => $e->getPayload(), $events));
        $artifact = $events[2]->getArtifactUpdate()?->getArtifact();
        self::assertNotNull($artifact);
        self::assertSame('Hello, world', ProtoHelpers::getArtifactText($artifact));
    }

    #[DataProvider('bindings')]
    public function testBlockingSendGetAndList(string $binding): void
    {
        $client = $this->client($binding, streaming: false);
        $task = $this->sendForTask($client, 'there');

        self::assertSame(TaskState::TASK_STATE_COMPLETED, $task->getStatus()?->getState());
        self::assertSame($task->getId(), $client->getTask(new GetTaskRequest(['id' => $task->getId()]))->getId());

        $listed = $client->listTasks(new ListTasksRequest(['context_id' => $task->getContextId()]));
        self::assertSame([$task->getId()], array_map(static fn(Task $t): string => $t->getId(), iterator_to_array($listed->getTasks(), false)));
    }

    #[DataProvider('bindings')]
    public function testReturnImmediately(string $binding): void
    {
        $client = $this->client($binding, streaming: false);
        $request = Fixtures::sendRequest(Fixtures::userMessage('later'), new SendMessageConfiguration(['return_immediately' => true]));

        $events = iterator_to_array($client->sendMessage($request), false);
        $task = $events[0]->getTask();
        self::assertNotNull($task);
        self::assertSame(TaskState::TASK_STATE_SUBMITTED, $task->getStatus()?->getState());

        // The server finished the work after answering.
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $client->getTask(new GetTaskRequest(['id' => $task->getId()]))->getStatus()?->getState());
    }

    #[DataProvider('bindings')]
    public function testMultiTurnCancelAndSubscribe(string $binding): void
    {
        $client = $this->client($binding, streaming: false);
        $task = $this->sendForTask($client, 'wait for me');
        self::assertSame(TaskState::TASK_STATE_INPUT_REQUIRED, $task->getStatus()?->getState());

        $subscribed = iterator_to_array($this->client($binding)->subscribe(new SubscribeToTaskRequest(['id' => $task->getId()])), false);
        self::assertSame($task->getId(), $subscribed[0]->getTask()?->getId());

        $cancelled = $client->cancelTask(new CancelTaskRequest(['id' => $task->getId()]));
        self::assertSame(TaskState::TASK_STATE_CANCELED, $cancelled->getStatus()?->getState());

        $this->expectException(TaskNotCancelableError::class);
        $client->cancelTask(new CancelTaskRequest(['id' => $task->getId()]));
    }

    #[DataProvider('bindings')]
    public function testErrorsReachTheClientAsTypedExceptions(string $binding): void
    {
        $this->expectException(TaskNotFoundError::class);
        $this->client($binding)->getTask(new GetTaskRequest(['id' => 'nope']));
    }

    private function client(string $binding, bool $streaming = true): Client
    {
        return ClientFactory::createClient(Fixtures::BASE_URL, new ClientConfig(streaming: $streaming, httpClient: $this->sender(), supportedProtocolBindings: [$binding]));
    }

    private function sender(): InProcessHttpSender
    {
        return new InProcessHttpSender($this->router, $this->handler);
    }

    private function sendForTask(Client $client, string $text): Task
    {
        $events = iterator_to_array($client->sendMessage(Fixtures::sendRequest(Fixtures::userMessage($text))), false);
        $task = end($events) instanceof StreamResponse ? end($events)->getTask() : null;
        self::assertInstanceOf(Task::class, $task);

        return $task;
    }
}
