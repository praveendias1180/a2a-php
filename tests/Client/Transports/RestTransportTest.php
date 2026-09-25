<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Transports;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\A2AClientTimeoutError;
use A2A\Client\Transports\RestTransport;
use A2A\Helpers\ProtoHelpers;
use A2A\Tests\Client\Support\FakeHttpSender;
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
use A2A\Utils\Errors\MethodNotFoundError;
use A2A\Utils\Errors\TaskNotFoundError;
use Google\Protobuf\Internal\Message as ProtobufMessage;
use Google\Protobuf\Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/transports/test_rest_client.py, plus a test per
 * transport method.
 */
final class RestTransportTest extends TestCase
{
    private const URL = 'http://agent.example.com/api';

    private FakeHttpSender $http;
    private RestTransport $transport;

    protected function setUp(): void
    {
        $this->http = new FakeHttpSender();
        $this->transport = new RestTransport($this->http, JsonRpcTransportTest::card(), self::URL . '/');
    }

    public function testTrailingSlashIsRemovedFromTheBaseUrl(): void
    {
        self::assertSame(self::URL, $this->transport->url);
    }

    public function testSendMessage(): void
    {
        $this->http->queueJson(['task' => ['id' => 't-1', 'status' => ['state' => 'TASK_STATE_COMPLETED']]]);

        $response = $this->transport->sendMessage(self::sendRequest());

        self::assertSame('t-1', $response->getTask()?->getId());
        self::assertSame('POST', $this->http->lastRequest()->method);
        self::assertSame(self::URL . '/message:send', $this->http->lastRequest()->url);
        self::assertSame('hi', $this->http->lastJsonPath('message.parts.0.text'));
        self::assertSame('application/json', $this->http->lastHeader('Content-Type'));
        self::assertSame('1.0', $this->http->lastHeader('A2A-Version'));
    }

    public function testSendMessageStreamingTimeout(): void
    {
        $this->http->queueException(new A2AClientTimeoutError('Client Request timed out'));

        $this->expectException(A2AClientTimeoutError::class);
        $this->expectExceptionMessage('Client Request timed out');
        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function mappedErrors(): iterable
    {
        foreach (ErrorMapping::A2A_ERROR_MAPPING as $class => [, , $reason]) {
            yield $class => [$class, $reason];
        }
    }

    /**
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('mappedErrors')]
    public function testRestMappedErrors(string $class, string $reason): void
    {
        $this->http->queueJson(['error' => [
            'code' => 500,
            'status' => 'UNKNOWN',
            'message' => 'Mapped Error',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => $reason, 'domain' => 'a2a-protocol.org', 'metadata' => new \stdClass()]],
        ]], 500);

        $this->expectException($class);
        $this->expectExceptionMessage('Mapped Error');
        $this->transport->sendMessage(self::sendRequest());
    }

    public function testMappedErrorCarriesTheMetadata(): void
    {
        $this->http->queueJson(['error' => ['code' => 404, 'message' => 'Task not found', 'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'TASK_NOT_FOUND', 'metadata' => ['taskId' => 't-9']],
        ]]], 404);

        try {
            $this->transport->getTask(new GetTaskRequest(['id' => 't-9']));
            self::fail('Expected TaskNotFoundError');
        } catch (TaskNotFoundError $e) {
            self::assertSame(['taskId' => 't-9'], $e->data);
        }
    }

    public function testUnmappedNotFoundBecomesMethodNotFound(): void
    {
        $this->http->queueBody('<html>Not Found</html>', 404);

        $this->expectException(MethodNotFoundError::class);
        $this->expectExceptionMessage('Resource not found: ' . self::URL . '/tasks/t-1');
        $this->transport->getTask(new GetTaskRequest(['id' => 't-1']));
    }

    public function testOtherUnmappedStatusIsAClientError(): void
    {
        $this->http->queueJson(['error' => ['code' => 502, 'message' => 'Bad gateway']], 502);

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('HTTP Error 502');
        $this->transport->listTasks(new ListTasksRequest());
    }

    public function testSendMessageWithTimeoutContext(): void
    {
        $this->http->queueJson(['task' => ['id' => 't']]);

        $this->transport->sendMessage(self::sendRequest(), new ClientCallContext(timeout: 10.0));

        self::assertSame(10.0, $this->http->lastRequest()->timeout);
    }

    public function testUrlSerialization(): void
    {
        $timestamp = new Timestamp();
        $timestamp->setSeconds(1710000000);
        $this->http->queueJson(['tasks' => []]);

        $this->transport->listTasks(new ListTasksRequest([
            'tenant' => 'my-tenant',
            'status' => TaskState::TASK_STATE_WORKING,
            'include_artifacts' => true,
            'status_timestamp_after' => $timestamp,
        ]));

        $url = $this->http->lastRequest()->url;
        self::assertSame('GET', $this->http->lastRequest()->method);
        self::assertStringStartsWith(self::URL . '/my-tenant/tasks?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        self::assertSame('TASK_STATE_WORKING', $params['status']);
        self::assertSame('true', $params['includeArtifacts']);
        self::assertSame('2024-03-09T16:00:00Z', $params['statusTimestampAfter']);
        self::assertArrayNotHasKey('tenant', $params);
        self::assertNull($this->http->lastRequest()->body);
    }

    public function testGetTaskPutsHistoryLengthInTheQuery(): void
    {
        $this->http->queueJson(['id' => 't-1', 'status' => ['state' => 'TASK_STATE_WORKING']]);

        $task = $this->transport->getTask(new GetTaskRequest(['id' => 't-1', 'history_length' => 3]));

        self::assertSame('t-1', $task->getId());
        self::assertSame(self::URL . '/tasks/t-1?historyLength=3', $this->http->lastRequest()->url);
    }

    public function testCancelTask(): void
    {
        $this->http->queueJson(['id' => 't-1', 'status' => ['state' => 'TASK_STATE_CANCELED']]);

        $task = $this->transport->cancelTask(new CancelTaskRequest(['id' => 't-1']));

        self::assertSame(TaskState::TASK_STATE_CANCELED, $task->getStatus()?->getState());
        self::assertSame(self::URL . '/tasks/t-1:cancel', $this->http->lastRequest()->url);
        self::assertSame(['id' => 't-1'], $this->http->lastJson());
    }

    public function testListTaskPushNotificationConfigsSuccess(): void
    {
        $this->http->queueJson(['configs' => [['id' => 'config-1', 'taskId' => 'task-1', 'url' => 'https://example.com']]]);

        $result = $this->transport->listTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest(['task_id' => 'task-1']));

        self::assertCount(1, $result->getConfigs());
        self::assertSame(self::URL . '/tasks/task-1/pushNotificationConfigs', $this->http->lastRequest()->url);
        self::assertSame('GET', $this->http->lastRequest()->method);
    }

    public function testDeleteTaskPushNotificationConfigSuccess(): void
    {
        $this->http->queueBody('', 200);

        $this->transport->deleteTaskPushNotificationConfig(new DeleteTaskPushNotificationConfigRequest(['task_id' => 'task-1', 'id' => 'config-1']));

        self::assertSame('DELETE', $this->http->lastRequest()->method);
        self::assertSame(self::URL . '/tasks/task-1/pushNotificationConfigs/config-1', $this->http->lastRequest()->url);
    }

    public function testCreateAndGetPushNotificationConfig(): void
    {
        $config = ['id' => 'config-1', 'taskId' => 'task-1', 'url' => 'https://example.com'];
        $this->http->queueJson($config)->queueJson($config);

        $created = $this->transport->createTaskPushNotificationConfig(new TaskPushNotificationConfig(['task_id' => 'task-1', 'url' => 'https://example.com']));
        self::assertSame('config-1', $created->getId());
        self::assertSame('POST', $this->http->lastRequest()->method);
        self::assertSame(self::URL . '/tasks/task-1/pushNotificationConfigs', $this->http->lastRequest()->url);

        $this->transport->getTaskPushNotificationConfig(new GetTaskPushNotificationConfigRequest(['task_id' => 'task-1', 'id' => 'config-1']));
        self::assertSame(self::URL . '/tasks/task-1/pushNotificationConfigs/config-1', $this->http->lastRequest()->url);
    }

    public function testSendMessageStreaming(): void
    {
        $this->http->queueSse([
            ": keep-alive\n\n",
            "data: {\"task\": {\"id\": \"t-1\"}}\n\n",
            "data: {\"statusUpdate\": {\"taskId\": \"t-1\", \"contextId\": \"c\", \"status\": {\"state\": \"TASK_STATE_COMPLETED\"}}}\n\n",
        ]);

        $events = iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()), false);

        self::assertSame(['task', 'status_update'], array_map(static fn(StreamResponse $e): string => $e->getPayload(), $events));
        self::assertSame(self::URL . '/message:stream', $this->http->lastRequest()->url);
        self::assertSame('text/event-stream', $this->http->lastHeader('Accept'));
    }

    public function testSendMessageStreamingWithNewExtensions(): void
    {
        $this->http->queueSse([]);
        $extensions = 'https://example.com/test-ext/v2';

        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest(), new ClientCallContext(serviceParameters: ['A2A-Extensions' => $extensions])));

        self::assertSame($extensions, $this->http->lastHeader('A2A-Extensions'));
    }

    public function testSendMessageStreamingServerErrorPropagates(): void
    {
        $this->http->queueSse([], 403);

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('HTTP Error 403');
        iterator_to_array($this->transport->sendMessageStreaming(self::sendRequest()));
    }

    public function testSseErrorEventIsMappedThroughItsReason(): void
    {
        $this->http->queueSse(["event: error\ndata: {\"error\": {\"code\": 404, \"message\": \"Task not found\", \"details\": [{\"@type\": \"type.googleapis.com/google.rpc.ErrorInfo\", \"reason\": \"TASK_NOT_FOUND\"}]}}\n\n"]);

        $this->expectException(TaskNotFoundError::class);
        iterator_to_array($this->transport->subscribe(new SubscribeToTaskRequest(['id' => 't-1'])));
    }

    public function testUnmappedSseErrorEventIsAClientError(): void
    {
        $this->http->queueSse(["event: error\ndata: something broke\n\n"]);

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('something broke');
        iterator_to_array($this->transport->subscribe(new SubscribeToTaskRequest(['id' => 't-1'])));
    }

    public function testGetCardWithExtendedCardSupportWithExtensions(): void
    {
        $card = JsonRpcTransportTest::card();
        $card->getCapabilities()?->setExtendedAgentCard(true);
        $transport = new RestTransport($this->http, $card, self::URL);
        $this->http->queueJson(['name' => 'Extended', 'description' => 'd', 'version' => '1']);
        $extensions = 'https://example.com/test-ext/v1,https://example.com/test-ext/v2';

        $result = $transport->getExtendedAgentCard(new GetExtendedAgentCardRequest(), new ClientCallContext(serviceParameters: ['A2A-Extensions' => $extensions]));

        self::assertSame('Extended', $result->getName());
        self::assertSame(self::URL . '/extendedAgentCard', $this->http->lastRequest()->url);
        self::assertSame($extensions, $this->http->lastHeader('A2A-Extensions'));
    }

    /**
     * @return iterable<string, array{string, ProtobufMessage, string, string}>
     */
    public static function tenantCases(): iterable
    {
        yield 'send_message' => ['sendMessage', new SendMessageRequest(['tenant' => 'my-tenant', 'message' => ProtoHelpers::newTextMessage('hi')]), '/my-tenant/message:send', 'POST'];
        yield 'list_tasks' => ['listTasks', new ListTasksRequest(['tenant' => 'my-tenant']), '/my-tenant/tasks', 'GET'];
        yield 'get_task' => ['getTask', new GetTaskRequest(['tenant' => 'my-tenant', 'id' => 'task-123']), '/my-tenant/tasks/task-123', 'GET'];
        yield 'cancel_task' => ['cancelTask', new CancelTaskRequest(['tenant' => 'my-tenant', 'id' => 'task-123']), '/my-tenant/tasks/task-123:cancel', 'POST'];
        yield 'create_task_push_notification_config' => ['createTaskPushNotificationConfig', new TaskPushNotificationConfig(['tenant' => 'my-tenant', 'task_id' => 'task-123']), '/my-tenant/tasks/task-123/pushNotificationConfigs', 'POST'];
        yield 'get_task_push_notification_config' => ['getTaskPushNotificationConfig', new GetTaskPushNotificationConfigRequest(['tenant' => 'my-tenant', 'task_id' => 'task-123', 'id' => 'cfg-1']), '/my-tenant/tasks/task-123/pushNotificationConfigs/cfg-1', 'GET'];
        yield 'list_task_push_notification_configs' => ['listTaskPushNotificationConfigs', new ListTaskPushNotificationConfigsRequest(['tenant' => 'my-tenant', 'task_id' => 'task-123']), '/my-tenant/tasks/task-123/pushNotificationConfigs', 'GET'];
        yield 'delete_task_push_notification_config' => ['deleteTaskPushNotificationConfig', new DeleteTaskPushNotificationConfigRequest(['tenant' => 'my-tenant', 'task_id' => 'task-123', 'id' => 'cfg-1']), '/my-tenant/tasks/task-123/pushNotificationConfigs/cfg-1', 'DELETE'];
    }

    #[DataProvider('tenantCases')]
    public function testRestMethodsPrependTenant(string $method, ProtobufMessage $request, string $expectedPath, string $httpMethod): void
    {
        $this->http->queueJson(new \stdClass());

        $this->transport->{$method}($request);

        self::assertSame(self::URL . $expectedPath, $this->http->lastRequest()->url);
        self::assertSame($httpMethod, $this->http->lastRequest()->method);
    }

    public function testRestGetExtendedAgentCardPrependTenant(): void
    {
        $card = JsonRpcTransportTest::card();
        $card->getCapabilities()?->setExtendedAgentCard(true);
        $transport = new RestTransport($this->http, $card, self::URL);
        $this->http->queueJson(new \stdClass());

        $transport->getExtendedAgentCard(new GetExtendedAgentCardRequest(['tenant' => 'my-tenant']));

        self::assertSame(self::URL . '/my-tenant/extendedAgentCard', $this->http->lastRequest()->url);
    }

    public function testRestGetTaskPrependEmptyTenant(): void
    {
        $this->http->queueJson(new \stdClass());

        $this->transport->getTask(new GetTaskRequest(['tenant' => '', 'id' => 'task-123']));

        self::assertSame(self::URL . '/tasks/task-123', $this->http->lastRequest()->url);
    }

    /**
     * @return iterable<string, array{string, ProtobufMessage, string}>
     */
    public static function streamingTenantCases(): iterable
    {
        yield 'subscribe' => ['subscribe', new SubscribeToTaskRequest(['tenant' => 'my-tenant', 'id' => 'task-123']), '/my-tenant/tasks/task-123:subscribe'];
        yield 'send_message_streaming' => ['sendMessageStreaming', new SendMessageRequest(['tenant' => 'my-tenant', 'message' => ProtoHelpers::newTextMessage('hi')]), '/my-tenant/message:stream'];
    }

    #[DataProvider('streamingTenantCases')]
    public function testRestStreamingMethodsPrependTenant(string $method, ProtobufMessage $request, string $expectedPath): void
    {
        $this->http->queueSse([]);

        $generator = $this->transport->{$method}($request);
        self::assertInstanceOf(\Generator::class, $generator);
        iterator_to_array($generator);

        $sent = $this->http->lastRequest();
        self::assertSame('POST', $sent->method);
        self::assertSame(self::URL . $expectedPath, $sent->url);
        if ($method === 'subscribe') {
            self::assertNull($sent->body);
        } else {
            self::assertSame(json_decode($request->serializeToJsonString(), true), $this->http->lastJson());
        }
    }

    private static function sendRequest(): SendMessageRequest
    {
        return new SendMessageRequest(['message' => ProtoHelpers::newTextMessage('hi', role: Role::ROLE_USER)]);
    }
}
