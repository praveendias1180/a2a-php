<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Integration;

use A2A\Client\A2ACardResolver;
use A2A\Client\Client;
use A2A\Client\ClientConfig;
use A2A\Client\ClientFactory;
use A2A\Helpers\ProtoHelpers;
use A2A\Types\CancelTaskRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\Role;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskState;
use A2A\Utils\Errors\TaskNotFoundError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The PHP client against the official Python SDK's sample server
 * (a2a-python samples/hello_world_agent.py): every client operation, over
 * JSON-RPC and HTTP+JSON, with each HTTP client the SDK supports.
 *
 * Skipped unless A2A_PYTHON_SERVER_URL is set; scripts/run-python-interop.sh
 * starts the server and sets it.
 */
#[Group('integration')]
final class PythonInteropTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $url = getenv('A2A_PYTHON_SERVER_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set A2A_PYTHON_SERVER_URL (see scripts/run-python-interop.sh).');
        }
        $this->baseUrl = rtrim($url, '/');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function transportsAndClients(): iterable
    {
        foreach (['JSONRPC', 'HTTP+JSON'] as $binding) {
            foreach (['guzzle', 'symfony', 'psr18'] as $httpClient) {
                yield "$binding via $httpClient" => [$binding, $httpClient];
            }
        }
    }

    #[DataProvider('transportsAndClients')]
    public function testResolvesTheAgentCard(string $binding, string $httpClient): void
    {
        $card = (new A2ACardResolver(self::httpClient($httpClient), $this->baseUrl))->getAgentCard();

        self::assertSame('Sample Agent', $card->getName());
        self::assertTrue($card->getCapabilities()?->getStreaming());
        $bindings = array_map(static fn($i): string => $i->getProtocolBinding(), iterator_to_array($card->getSupportedInterfaces(), false));
        self::assertContains($binding, $bindings);
    }

    #[DataProvider('transportsAndClients')]
    public function testSendMessageBlocking(string $binding, string $httpClient): void
    {
        $events = iterator_to_array($this->client($binding, $httpClient, streaming: false)->sendMessage(self::request('hello')), false);

        self::assertCount(1, $events);
        $task = $events[0]->getTask();
        self::assertInstanceOf(Task::class, $task);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $task->getStatus()?->getState());
        $artifact = $task->getArtifacts()[0];
        self::assertInstanceOf(\A2A\Types\Artifact::class, $artifact);
        self::assertSame('Hello World! Nice to meet you!', ProtoHelpers::getArtifactText($artifact));
    }

    #[DataProvider('transportsAndClients')]
    public function testSendMessageStreaming(string $binding, string $httpClient): void
    {
        $client = $this->client($binding, $httpClient);
        $start = microtime(true);
        $events = [];
        $arrivals = [];
        foreach ($client->sendMessage(self::request('how are you')) as $event) {
            $events[] = $event;
            $arrivals[] = microtime(true) - $start;
        }

        self::assertSame(['task', 'status_update', 'artifact_update', 'status_update'], array_map(static fn(StreamResponse $e): string => $e->getPayload(), $events));
        self::assertSame(TaskState::TASK_STATE_WORKING, $events[1]->getStatusUpdate()?->getStatus()?->getState());
        $artifact = $events[2]->getArtifactUpdate()?->getArtifact();
        self::assertNotNull($artifact);
        self::assertStringContainsString("I'm doing great!", ProtoHelpers::getArtifactText($artifact));
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $events[3]->getStatusUpdate()?->getStatus()?->getState());

        if ($httpClient !== 'psr18') {
            // The sample agent sleeps 1s between "working" and the artifact:
            // with a live-streaming client the first events arrive well before the last.
            self::assertGreaterThan(0.5, $arrivals[3] - $arrivals[0], 'events were delivered live, not in one burst');
        }
    }

    #[DataProvider('transportsAndClients')]
    public function testGetTask(string $binding, string $httpClient): void
    {
        $client = $this->client($binding, $httpClient, streaming: false);
        $sent = $this->sendAndGetTask($client, 'hello');

        $task = $client->getTask(new GetTaskRequest(['id' => $sent->getId(), 'history_length' => 1]));

        self::assertSame($sent->getId(), $task->getId());
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $task->getStatus()?->getState());
        self::assertLessThanOrEqual(1, count($task->getHistory()));
    }

    #[DataProvider('transportsAndClients')]
    public function testGetUnknownTaskIsTaskNotFound(string $binding, string $httpClient): void
    {
        $this->expectException(TaskNotFoundError::class);
        $this->client($binding, $httpClient)->getTask(new GetTaskRequest(['id' => 'no-such-task']));
    }

    #[DataProvider('transportsAndClients')]
    public function testListTasks(string $binding, string $httpClient): void
    {
        $client = $this->client($binding, $httpClient, streaming: false);
        $sent = $this->sendAndGetTask($client, 'hello');

        $response = $client->listTasks(new ListTasksRequest(['context_id' => $sent->getContextId(), 'page_size' => 10]));

        $ids = array_map(static fn(Task $t): string => $t->getId(), iterator_to_array($response->getTasks(), false));
        self::assertContains($sent->getId(), $ids);
    }

    #[DataProvider('transportsAndClients')]
    public function testCancelTask(string $binding, string $httpClient): void
    {
        $client = $this->client($binding, $httpClient);
        $submitted = $this->sendNonBlocking($binding, $httpClient, 'cancel me');

        $task = $client->cancelTask(new CancelTaskRequest(['id' => $submitted->getId()]));

        self::assertSame($submitted->getId(), $task->getId());
        self::assertSame(TaskState::TASK_STATE_CANCELED, $task->getStatus()?->getState());
    }

    #[DataProvider('transportsAndClients')]
    public function testSubscribe(string $binding, string $httpClient): void
    {
        $client = $this->client($binding, $httpClient);
        $submitted = $this->sendNonBlocking($binding, $httpClient, 'bye');

        $events = iterator_to_array($client->subscribe(new SubscribeToTaskRequest(['id' => $submitted->getId()])), false);

        self::assertNotEmpty($events);
        self::assertSame('task', $events[0]->getPayload(), 'a subscription starts with the current task');
        $last = $events[count($events) - 1];
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $last->getStatusUpdate()?->getStatus()?->getState());
        $texts = array_map(static fn(StreamResponse $e): string => ProtoHelpers::getStreamResponseText($e), $events);
        self::assertContains('Goodbye! Have a wonderful day!', $texts);
    }

    private function client(string $binding, string $httpClient, bool $streaming = true): Client
    {
        return ClientFactory::createClient(
            $this->baseUrl,
            new ClientConfig(streaming: $streaming, httpClient: self::httpClient($httpClient), supportedProtocolBindings: [$binding]),
        );
    }

    private function sendAndGetTask(Client $client, string|SendMessageRequest $request): Task
    {
        foreach ($client->sendMessage(is_string($request) ? self::request($request) : $request) as $event) {
            if ($event->hasTask()) {
                $task = $event->getTask();
                self::assertInstanceOf(Task::class, $task);

                return $task;
            }
        }
        self::fail('No task returned');
    }

    /**
     * Sends with returnImmediately on a non-streaming client, so the task
     * comes back while the agent is still working on it.
     */
    private function sendNonBlocking(string $binding, string $httpClient, string $text): Task
    {
        $request = self::request($text);
        $request->setConfiguration(new SendMessageConfiguration(['return_immediately' => true]));

        $task = $this->sendAndGetTask($this->client($binding, $httpClient, streaming: false), $request);
        self::assertContains($task->getStatus()?->getState(), [TaskState::TASK_STATE_SUBMITTED, TaskState::TASK_STATE_WORKING]);

        return $task;
    }

    private static function request(string $text): SendMessageRequest
    {
        return new SendMessageRequest(['message' => ProtoHelpers::newTextMessage($text, role: Role::ROLE_USER)]);
    }

    private static function httpClient(string $name): object
    {
        return match ($name) {
            'guzzle' => new \GuzzleHttp\Client(),
            'symfony' => \Symfony\Component\HttpClient\HttpClient::create(),
            'psr18' => new \Symfony\Component\HttpClient\Psr18Client(),
            default => throw new \InvalidArgumentException($name),
        };
    }
}
