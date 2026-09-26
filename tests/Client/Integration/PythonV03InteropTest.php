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
 * The PHP client (v1.0 types) against a real A2A v0.3 server: the Python
 * a2a-sdk 0.3.x running tests/Interop/python/v03_hello_world_agent.py. The
 * client sees a v0.3 card and switches to the Compat\V0_3 transports.
 *
 * Skipped unless A2A_PYTHON_V03_SERVER_URL is set; scripts/run-python-interop.sh
 * starts the server and sets it.
 */
#[Group('integration-v03')]
final class PythonV03InteropTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $url = getenv('A2A_PYTHON_V03_SERVER_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set A2A_PYTHON_V03_SERVER_URL (see scripts/run-python-interop.sh).');
        }
        $this->baseUrl = rtrim($url, '/');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function transportsAndClients(): iterable
    {
        foreach (['JSONRPC', 'HTTP+JSON'] as $binding) {
            foreach (['guzzle', 'symfony'] as $httpClient) {
                yield "$binding via $httpClient" => [$binding, $httpClient];
            }
        }
    }

    #[DataProvider('transportsAndClients')]
    public function testResolvesTheLegacyCard(string $binding, string $httpClient): void
    {
        $card = (new A2ACardResolver(self::httpClient($httpClient), $this->baseUrl))->getAgentCard();

        self::assertSame('Sample Agent (v0.3)', $card->getName());
        $interfaces = [];
        foreach ($card->getSupportedInterfaces() as $interface) {
            $interfaces[$interface->getProtocolBinding()] = $interface->getProtocolVersion();
        }
        self::assertSame('0.3.0', $interfaces[$binding] ?? null, 'the v0.3 url / additionalInterfaces became supportedInterfaces');
    }

    #[DataProvider('transportsAndClients')]
    public function testSendMessageBlocking(string $binding, string $httpClient): void
    {
        $events = iterator_to_array($this->client($binding, $httpClient, streaming: false)->sendMessage(self::request('hello')), false);

        self::assertCount(1, $events);
        $task = $events[0]->getTask();
        self::assertInstanceOf(Task::class, $task);
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $task->getStatus()?->getState(), 'blocking is stated explicitly, so the v0.3 server waits');
        $artifact = $task->getArtifacts()[0];
        self::assertInstanceOf(\A2A\Types\Artifact::class, $artifact);
        self::assertSame('Hello World! Nice to meet you!', ProtoHelpers::getArtifactText($artifact));
    }

    #[DataProvider('transportsAndClients')]
    public function testSendMessageStreaming(string $binding, string $httpClient): void
    {
        $start = microtime(true);
        $events = [];
        $arrivals = [];
        foreach ($this->client($binding, $httpClient)->sendMessage(self::request('hello')) as $event) {
            $events[] = $event;
            $arrivals[] = microtime(true) - $start;
        }

        $payloads = array_map(static fn(StreamResponse $e): string => $e->getPayload(), $events);
        self::assertSame('task', $payloads[0]);
        $last = $events[count($events) - 1];
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $last->getStatusUpdate()?->getStatus()?->getState());
        self::assertContains('artifact_update', $payloads);
        $texts = array_map(static fn(StreamResponse $e): string => ProtoHelpers::getStreamResponseText($e), $events);
        self::assertContains('Hello World! Nice to meet you!', $texts);
        self::assertGreaterThan(0.5, $arrivals[count($arrivals) - 1] - $arrivals[0], 'events were delivered live, not in one burst');
    }

    #[DataProvider('transportsAndClients')]
    public function testGetTask(string $binding, string $httpClient): void
    {
        $client = $this->client($binding, $httpClient, streaming: false);
        $sent = $this->sendAndGetTask($client, 'hello');

        $task = $client->getTask(new GetTaskRequest(['id' => $sent->getId(), 'history_length' => 1]));

        self::assertSame($sent->getId(), $task->getId());
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $task->getStatus()?->getState());
    }

    #[DataProvider('transportsAndClients')]
    public function testGetUnknownTask(string $binding, string $httpClient): void
    {
        if ($binding === 'HTTP+JSON') {
            // v0.3 REST servers answer 404 with just {"message": ...}: there is
            // nothing to tell "task not found" from "no such route".
            $this->expectException(\A2A\Utils\Errors\MethodNotFoundError::class);
        } else {
            $this->expectException(TaskNotFoundError::class);
        }
        $this->client($binding, $httpClient)->getTask(new GetTaskRequest(['id' => 'no-such-task']));
    }

    #[DataProvider('transportsAndClients')]
    public function testCancelTask(string $binding, string $httpClient): void
    {
        $submitted = $this->sendNonBlocking($binding, $httpClient, 'cancel me');

        $task = $this->client($binding, $httpClient)->cancelTask(new CancelTaskRequest(['id' => $submitted->getId()]));

        self::assertSame($submitted->getId(), $task->getId());
        self::assertSame(TaskState::TASK_STATE_CANCELED, $task->getStatus()?->getState());
    }

    #[DataProvider('transportsAndClients')]
    public function testSubscribe(string $binding, string $httpClient): void
    {
        $submitted = $this->sendNonBlocking($binding, $httpClient, 'hello');

        $events = iterator_to_array($this->client($binding, $httpClient)->subscribe(new SubscribeToTaskRequest(['id' => $submitted->getId()])), false);

        self::assertNotEmpty($events);
        $last = $events[count($events) - 1];
        self::assertSame(TaskState::TASK_STATE_COMPLETED, $last->getStatusUpdate()?->getStatus()?->getState());
        $texts = array_map(static fn(StreamResponse $e): string => ProtoHelpers::getStreamResponseText($e), $events);
        self::assertContains('Hello World! Nice to meet you!', $texts);
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
            default => throw new \InvalidArgumentException($name),
        };
    }
}
