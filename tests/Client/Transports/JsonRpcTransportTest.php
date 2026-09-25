<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Transports;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\A2AClientTimeoutError;
use A2A\Client\Transports\JsonRpcTransport;
use A2A\Helpers\ProtoHelpers;
use A2A\Tests\Client\Support\FakeHttpSender;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\CancelTaskRequest;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\Role;
use A2A\Types\SendMessageRequest;
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskState;
use A2A\Utils\Errors\ErrorMapping;
use A2A\Utils\Errors\InternalError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\JSONParseError;
use A2A\Utils\Errors\TaskNotFoundError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/transports/test_jsonrpc_client.py, plus a test per
 * transport method.
 */
final class JsonRpcTransportTest extends TestCase
{
    private const URL = 'http://test-agent.example.com';

    private FakeHttpSender $http;
    private JsonRpcTransport $transport;

    protected function setUp(): void
    {
        $this->http = new FakeHttpSender();
        $this->transport = new JsonRpcTransport($this->http, self::card(), self::URL);
    }

    public function testInitWithAgentCard(): void
    {
        self::assertSame(self::URL, $this->transport->url);
        self::assertSame('Test Agent', $this->transport->agentCard->getName());
    }

    public function testSendMessageSuccess(): void
    {
        $this->http->queueJson(self::rpcResult(['task' => ['id' => 't-1', 'contextId' => 'ctx-123', 'status' => ['state' => 'TASK_STATE_COMPLETED']]]));

        $response = $this->transport->sendMessage(self::sendRequest());

        self::assertSame('t-1', $response->getTask()?->getId());
        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame(self::URL, $request->url);
        $payload = $this->http->lastJson();
        self::assertSame('2.0', $payload['jsonrpc']);
        self::assertSame('SendMessage', $payload['method']);
        self::assertIsString($payload['id']);
        self::assertSame('hello', $this->http->lastJsonPath('params.message.parts.0.text'));
        self::assertSame('application/json', $this->http->lastHeader('Content-Type'));
        self::assertSame('1.0', $this->http->lastHeader('A2A-Version'));
    }

    /**
     * @return iterable<string, array{class-string, int}>
     */
    public static function mappedErrors(): iterable
    {
        foreach (ErrorMapping::JSON_RPC_ERROR_CODE_MAP as $class => $code) {
            yield $class => [$class, $code];
        }
    }

    /**
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('mappedErrors')]
    public function testSendMessageJsonRpcError(string $class, int $code): void
    {
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'error' => ['code' => $code, 'message' => 'Mapped Error'], 'result' => null]);

        $this->expectException($class);
        $this->expectExceptionMessage('Mapped Error');
        $this->transport->sendMessage(self::sendRequest());
    }

    public function testSendMessageTimeout(): void
    {
        $this->http->queueException(new A2AClientTimeoutError('Client Request timed out'));

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('timed out');
        $this->transport->sendMessage(self::sendRequest());
    }

    public function testSendMessageHttpError(): void
    {
        $this->http->queueBody('Server Error', 500);

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('HTTP Error 500');
        $this->transport->sendMessage(self::sendRequest());
    }

    public function testSendMessageJsonDecodeError(): void
    {
        $this->http->queueBody('not json');

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('JSON Decode Error');
        $this->transport->sendMessage(self::sendRequest());
    }

    public function testSendMessageWithTimeoutContext(): void
    {
        $this->http->queueJson(self::rpcResult(new \stdClass()));

        $this->transport->sendMessage(self::sendRequest(), new ClientCallContext(timeout: 15.0));

        self::assertSame(15.0, $this->http->lastRequest()->timeout);
    }

    public function testResultThatIsNotTheExpectedTypeIsAClientError(): void
    {
        $this->http->queueJson(self::rpcResult(['task' => ['status' => ['state' => 'NOT_A_STATE']]]));

        $this->expectException(A2AClientError::class);
        $this->transport->sendMessage(self::sendRequest());
    }

    public function testGetTaskSuccess(): void
    {
        $this->http->queueJson(self::rpcResult(['id' => 't-1', 'contextId' => 'c', 'status' => ['state' => 'TASK_STATE_WORKING']]));

        $task = $this->transport->getTask(new GetTaskRequest(['id' => 't-1', 'history_length' => 10]));

        self::assertSame(TaskState::TASK_STATE_WORKING, $task->getStatus()?->getState());
        $payload = $this->http->lastJson();
        self::assertSame('GetTask', $payload['method']);
        self::assertSame(['id' => 't-1', 'historyLength' => 10], $payload['params']);
    }

    public function testListTasks(): void
    {
        $this->http->queueJson(self::rpcResult(['tasks' => [['id' => 'a'], ['id' => 'b']], 'nextPageToken' => '', 'pageSize' => 2, 'totalSize' => 2]));

        $response = $this->transport->listTasks(new ListTasksRequest(['page_size' => 2]));

        self::assertCount(2, $response->getTasks());
        self::assertSame('ListTasks', $this->http->lastJson()['method']);
    }

    public function testCancelTaskSuccess(): void
    {
        $this->http->queueJson(self::rpcResult(['id' => 't-1', 'status' => ['state' => 'TASK_STATE_CANCELED']]));

        $task = $this->transport->cancelTask(new CancelTaskRequest(['id' => 't-1']));

        self::assertSame(TaskState::TASK_STATE_CANCELED, $task->getStatus()?->getState());
        self::assertSame('CancelTask', $this->http->lastJson()['method']);
    }

    public function testPushNotificationConfigMethods(): void
    {
        $config = ['id' => 'cfg-1', 'taskId' => 't-1', 'url' => 'https://example.com/hook'];
        $this->http
            ->queueJson(self::rpcResult($config))
            ->queueJson(self::rpcResult($config))
            ->queueJson(self::rpcResult(['configs' => [$config]]))
            ->queueJson(self::rpcResult(new \stdClass()));

        $created = $this->transport->createTaskPushNotificationConfig(new TaskPushNotificationConfig(['task_id' => 't-1', 'url' => 'https://example.com/hook']));
        self::assertSame('cfg-1', $created->getId());
        self::assertSame('CreateTaskPushNotificationConfig', $this->http->lastJson()['method']);

        $fetched = $this->transport->getTaskPushNotificationConfig(new GetTaskPushNotificationConfigRequest(['task_id' => 't-1', 'id' => 'cfg-1']));
        self::assertSame('https://example.com/hook', $fetched->getUrl());
        self::assertSame('GetTaskPushNotificationConfig', $this->http->lastJson()['method']);

        $listed = $this->transport->listTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest(['task_id' => 't-1']));
        self::assertCount(1, $listed->getConfigs());
        self::assertSame('ListTaskPushNotificationConfigs', $this->http->lastJson()['method']);

        $this->transport->deleteTaskPushNotificationConfig(new DeleteTaskPushNotificationConfigRequest(['task_id' => 't-1', 'id' => 'cfg-1']));
        self::assertSame('DeleteTaskPushNotificationConfig', $this->http->lastJson()['method']);
    }

    public function testSendMessageStreamingYieldsEachEvent(): void
    {
        $this->http->queueSseJson([
            self::rpcResult(['task' => ['id' => 't-1', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]),
            self::rpcResult(['statusUpdate' => ['taskId' => 't-1', 'contextId' => 'c', 'status' => ['state' => 'TASK_STATE_WORKING']]]),
            self::rpcResult(['artifactUpdate' => ['taskId' => 't-1', 'contextId' => 'c', 'artifact' => ['artifactId' => 'a', 'parts' => [['text' => 'hi']]]]]),
            self::rpcResult(['statusUpdate' => ['taskId' => 't-1', 'contextId' => 'c', 'status' => ['state' => 'TASK_STATE_COMPLETED']]]),
        ]);

        $events = iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()), false);

        self::assertSame(['task', 'status_update', 'artifact_update', 'status_update'], array_map(static fn(StreamResponse $e): string => $e->getPayload(), $events));
        self::assertSame('SendStreamingMessage', $this->http->lastJson()['method']);
        self::assertSame('text/event-stream', $this->http->lastHeader('Accept'));
        self::assertSame('no-store', $this->http->lastHeader('Cache-Control'));
        self::assertSame(1, $this->http->closedStreams);
    }

    public function testSubscribe(): void
    {
        $this->http->queueSseJson([self::rpcResult(['task' => ['id' => 't-1']])]);

        $events = iterator_to_array($this->transport->subscribe(new SubscribeToTaskRequest(['id' => 't-1'])), false);

        self::assertCount(1, $events);
        self::assertSame('SubscribeToTask', $this->http->lastJson()['method']);
        self::assertSame(['id' => 't-1'], $this->http->lastJson()['params']);
    }

    public function testStreamingIsLazy(): void
    {
        $stream = $this->transport->sendMessageStreaming(self::sendRequest());

        self::assertSame([], $this->http->requests, 'nothing is sent before iteration starts');
        unset($stream);
    }

    public function testSendMessageStreamingSseError(): void
    {
        $this->http->queueSse(["event: error\n", "data: Simulated SSE error\n", "\n"]);

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('SSE stream error: Simulated SSE error');
        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
    }

    public function testSseErrorEventCarryingAJsonRpcErrorIsMapped(): void
    {
        $this->http->queueSse(["event: error\ndata: " . json_encode(['jsonrpc' => '2.0', 'id' => '1', 'error' => ['code' => -32001, 'message' => 'Task not found']]) . "\n\n"]);

        $this->expectException(TaskNotFoundError::class);
        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
    }

    public function testErrorInsideAStreamedEventIsMapped(): void
    {
        $this->http->queueSseJson([['jsonrpc' => '2.0', 'id' => '1', 'error' => ['code' => -32602, 'message' => 'bad params']]]);

        $this->expectException(InvalidParamsError::class);
        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
    }

    public function testNonSseStreamResponseIsReadWhole(): void
    {
        // An up-front JSON-RPC error comes back as plain JSON, not as SSE.
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'error' => ['code' => -32004, 'message' => 'Streaming is not supported']]);

        $this->expectException(\A2A\Utils\Errors\UnsupportedOperationError::class);
        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
    }

    public function testSendMessageStreamingRequestError(): void
    {
        $this->http->queueException(new A2AClientError('Network communication error: Simulated request error'));

        $this->expectException(A2AClientError::class);
        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
    }

    public function testSendMessageStreamingTimeout(): void
    {
        $this->http->queueException(new A2AClientTimeoutError('Client Request timed out'));

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('timed out');
        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
    }

    public function testSendMessageStreamingServerErrorPropagates(): void
    {
        $this->http->queueSse([], 403);

        try {
            iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
            self::fail('Expected an exception');
        } catch (A2AClientError $e) {
            self::assertStringContainsString('HTTP Error 403', $e->getMessage());
        }
        self::assertCount(1, $this->http->requests);
        self::assertSame(1, $this->http->closedStreams);
    }

    public function testExtensionsAddedToRequest(): void
    {
        $this->http->queueJson(self::rpcResult(['task' => ['id' => 'task-123']]));

        $this->transport->sendMessage(self::sendRequest(), new ClientCallContext(serviceParameters: ['A2A-Extensions' => 'https://example.com/ext1']));

        self::assertSame('https://example.com/ext1', $this->http->lastHeader('A2A-Extensions'));
    }

    public function testServiceParametersOverrideTheVersionHeader(): void
    {
        $this->http->queueJson(self::rpcResult(['task' => ['id' => 'task-123']]));

        $this->transport->sendMessage(self::sendRequest(), new ClientCallContext(serviceParameters: ['a2a-version' => '1.1']));

        self::assertSame('1.1', $this->http->lastHeader('A2A-Version'));
        self::assertCount(1, array_filter(array_keys($this->http->lastRequest()->headers), static fn(string $h): bool => strcasecmp($h, 'A2A-Version') === 0));
    }

    public function testGetExtendedCardReturnsTheCardWhenNotSupported(): void
    {
        $card = $this->transport->getExtendedAgentCard(new GetExtendedAgentCardRequest());

        self::assertSame($this->transport->agentCard, $card);
        self::assertSame([], $this->http->requests);
    }

    public function testGetCardWithExtendedCardSupportWithExtensions(): void
    {
        $card = self::card();
        $card->getCapabilities()?->setExtendedAgentCard(true);
        $transport = new JsonRpcTransport($this->http, $card, self::URL);
        $extended = clone $card;
        $extended->setName('Extended');
        $this->http->queueBody('{"jsonrpc":"2.0","id":"123","result":' . $extended->serializeToJsonString() . '}');
        $extensions = 'https://example.com/test-ext/v1,https://example.com/test-ext/v2';

        $result = $transport->getExtendedAgentCard(new GetExtendedAgentCardRequest(), new ClientCallContext(serviceParameters: ['A2A-Extensions' => $extensions]));

        self::assertSame('Extended', $result->getName());
        self::assertSame('GetExtendedAgentCard', $this->http->lastJson()['method']);
        self::assertSame($extensions, $this->http->lastHeader('A2A-Extensions'));
    }

    public function testLiftsErrorInfoMetadataOntoA2AErrorData(): void
    {
        $error = JsonRpcTransport::createJsonRpcError(json_decode((string) json_encode([
            'code' => -32001,
            'message' => 'Task not found',
            'data' => [[
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => 'TASK_NOT_FOUND',
                'domain' => 'a2a-protocol.org',
                'metadata' => ['taskId' => 'abc-123'],
            ]],
        ])));

        self::assertInstanceOf(TaskNotFoundError::class, $error);
        self::assertSame('Task not found', $error->getMessage());
        self::assertSame(['taskId' => 'abc-123'], $error->data);
    }

    public function testNoDataFieldYieldsNullData(): void
    {
        $error = JsonRpcTransport::createJsonRpcError((object) ['code' => -32603, 'message' => 'oops']);

        self::assertInstanceOf(InternalError::class, $error);
        self::assertNull($error->data);
    }

    public function testEmptyErrorInfoMetadataYieldsNullData(): void
    {
        $error = JsonRpcTransport::createJsonRpcError(json_decode('{"code":-32001,"message":"x","data":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"TASK_NOT_FOUND","metadata":{}}]}'));

        self::assertNull($error->data);
    }

    public function testArrayWithoutErrorInfoYieldsNullData(): void
    {
        $error = JsonRpcTransport::createJsonRpcError(json_decode('{"code":-32602,"message":"bad params","data":[{"@type":"type.googleapis.com/google.rpc.BadRequest","fieldViolations":[]}]}'));

        self::assertInstanceOf(InvalidParamsError::class, $error);
        self::assertNull($error->data);
    }

    public function testUnknownCodeFallsBackToA2AClientError(): void
    {
        $error = JsonRpcTransport::createJsonRpcError((object) ['code' => -42, 'message' => 'who knows']);

        self::assertInstanceOf(A2AClientError::class, $error);
        self::assertStringContainsString('JSON-RPC Error -42', $error->getMessage());
    }

    public function testJsonParseErrorIsTyped(): void
    {
        $error = JsonRpcTransport::createJsonRpcError((object) ['code' => -32700, 'message' => 'Invalid JSON payload']);

        self::assertInstanceOf(JSONParseError::class, $error);
        self::assertSame('Invalid JSON payload', $error->getMessage());
    }

    public function testResponseWithoutResultOrErrorIsRejected(): void
    {
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1']);

        $this->expectException(A2AClientError::class);
        $this->transport->getTask(new GetTaskRequest(['id' => 't']));
    }

    public static function card(): AgentCard
    {
        return new AgentCard([
            'name' => 'Test Agent',
            'description' => 'A test agent',
            'version' => '1.0.0',
            'capabilities' => new AgentCapabilities(),
        ]);
    }

    private static function sendRequest(): SendMessageRequest
    {
        return new SendMessageRequest(['message' => ProtoHelpers::newTextMessage('hello', role: Role::ROLE_USER)]);
    }

    /**
     * @return array{jsonrpc: string, id: string, result: mixed}
     */
    private static function rpcResult(mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => '1', 'result' => $result];
    }
}
