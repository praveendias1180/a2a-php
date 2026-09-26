<?php

declare(strict_types=1);

namespace A2A\Tests\Compat\V0_3;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Compat\V0_3\CompatJsonRpcTransport;
use A2A\Compat\V0_3\CompatRestTransport;
use A2A\Tests\Client\Support\FakeHttpSender;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\TaskState;
use A2A\Utils\Errors\MethodNotFoundError;
use A2A\Utils\Errors\PushNotificationNotSupportedError;
use A2A\Utils\Errors\TaskNotFoundError;
use PHPUnit\Framework\TestCase;

/**
 * The v0.3 client transports against canned v0.3 responses. Ported in
 * spirit from a2a-python tests/compat/v0_3/test_jsonrpc_transport.py and
 * test_rest_transport.py.
 */
final class CompatTransportsTest extends TestCase
{
    private FakeHttpSender $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpSender();
    }

    public function testJsonRpcSendsV03MethodsAndHeaders(): void
    {
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['kind' => 'task', 'id' => 't', 'contextId' => 'c', 'status' => ['state' => 'completed']]]);
        $context = new ClientCallContext(serviceParameters: ['A2A-Extensions' => 'https://ext/a']);

        $response = $this->jsonRpc()->sendMessage(new SendMessageRequest(['message' => Fixtures::userMessage('hi')]), $context);

        self::assertSame(TaskState::TASK_STATE_COMPLETED, $response->getTask()?->getStatus()?->getState());
        self::assertSame('message/send', $this->http->lastJsonPath('method'));
        self::assertSame('user', $this->http->lastJsonPath('params.message.role'));
        self::assertSame('text', $this->http->lastJsonPath('params.message.parts.0.kind'));
        self::assertTrue($this->http->lastJsonPath('params.configuration.blocking'), 'blocking is always stated');
        self::assertSame('0.3', $this->http->lastHeader('A2A-Version'));
        self::assertSame('https://ext/a', $this->http->lastHeader('X-A2A-Extensions'));
        self::assertSame('https://ext/a', $this->http->lastHeader('A2A-Extensions'));
    }

    public function testJsonRpcReturnImmediatelyIsNonBlocking(): void
    {
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['kind' => 'task', 'id' => 't', 'contextId' => 'c', 'status' => ['state' => 'submitted']]]);

        $this->jsonRpc()->sendMessage(new SendMessageRequest(['message' => Fixtures::userMessage('hi'), 'configuration' => new SendMessageConfiguration(['return_immediately' => true])]));

        self::assertFalse($this->http->lastJsonPath('params.configuration.blocking'));
    }

    public function testJsonRpcMessageResultAndOldServersWithoutKind(): void
    {
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['messageId' => 'm', 'role' => 'agent', 'parts' => [['kind' => 'text', 'text' => 'hey']]]]);

        $response = $this->jsonRpc()->sendMessage(new SendMessageRequest(['message' => Fixtures::userMessage('hi')]));

        self::assertSame('hey', $response->getMessage()?->getParts()[0]->getText());
    }

    public function testJsonRpcStreamsV03Events(): void
    {
        $this->http->queueSseJson([
            ['jsonrpc' => '2.0', 'id' => '1', 'result' => ['kind' => 'task', 'id' => 't', 'contextId' => 'c', 'status' => ['state' => 'submitted']]],
            ['jsonrpc' => '2.0', 'id' => '1', 'result' => ['kind' => 'status-update', 'taskId' => 't', 'contextId' => 'c', 'status' => ['state' => 'working'], 'final' => false]],
            ['jsonrpc' => '2.0', 'id' => '1', 'result' => ['kind' => 'artifact-update', 'taskId' => 't', 'contextId' => 'c', 'artifact' => ['artifactId' => 'a', 'parts' => [['kind' => 'text', 'text' => 'x']]]]],
            ['jsonrpc' => '2.0', 'id' => '1', 'result' => ['kind' => 'status-update', 'taskId' => 't', 'contextId' => 'c', 'status' => ['state' => 'completed'], 'final' => true]],
        ]);

        $events = iterator_to_array($this->jsonRpc()->sendMessageStreaming(new SendMessageRequest(['message' => Fixtures::userMessage('hi')])), false);

        self::assertSame(['task', 'status_update', 'artifact_update', 'status_update'], array_map(static fn($e): string => $e->getPayload(), $events));
        self::assertSame('message/stream', $this->http->lastJsonPath('method'));
    }

    public function testJsonRpcErrorsKeepTheirCode(): void
    {
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'error' => ['code' => -32001, 'message' => 'Task not found']]);

        $this->expectException(TaskNotFoundError::class);
        $this->jsonRpc()->getTask(new GetTaskRequest(['id' => 'x']));
    }

    public function testJsonRpcStreamErrorEvent(): void
    {
        $this->http->queueSseJson([['jsonrpc' => '2.0', 'id' => '1', 'error' => ['code' => -32003, 'message' => 'no push']]]);

        $this->expectException(PushNotificationNotSupportedError::class);
        iterator_to_array($this->jsonRpc()->subscribe(new SubscribeToTaskRequest(['id' => 't'])));
    }

    public function testJsonRpcUnknownErrorCodeIsAClientError(): void
    {
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'error' => ['code' => 12345, 'message' => 'odd']]);

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('odd');
        $this->jsonRpc()->getTask(new GetTaskRequest(['id' => 'x']));
    }

    public function testJsonRpcExtendedCard(): void
    {
        $card = Fixtures::agentCard(extended: true);
        $this->http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'result' => [
            'name' => 'Extended', 'description' => 'd', 'version' => '1', 'url' => 'http://x/rpc', 'preferredTransport' => 'JSONRPC', 'protocolVersion' => '0.3.0',
            'capabilities' => ['streaming' => true], 'defaultInputModes' => [], 'defaultOutputModes' => [], 'skills' => [],
        ]]);

        $extended = (new CompatJsonRpcTransport($this->http, $card, 'http://x/rpc'))->getExtendedAgentCard(new GetExtendedAgentCardRequest());

        self::assertSame('Extended', $extended->getName());
        self::assertSame('agent/getAuthenticatedExtendedCard', $this->http->lastJsonPath('method'));
    }

    public function testRestSendsV03ProtoJson(): void
    {
        $this->http->queueJson(['task' => ['id' => 't', 'contextId' => 'c', 'status' => ['state' => 'TASK_STATE_CANCELLED']]]);

        $response = $this->rest()->sendMessage(new SendMessageRequest(['message' => Fixtures::userMessage('hi')]));

        self::assertSame(TaskState::TASK_STATE_CANCELED, $response->getTask()?->getStatus()?->getState());
        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('http://x/rest/v1/message:send', $request->url);
        self::assertSame('hi', $this->http->lastJsonPath('message.content.0.text'), 'v0.3 REST calls parts "content"');
        self::assertSame('ROLE_USER', $this->http->lastJsonPath('message.role'));
        self::assertTrue($this->http->lastJsonPath('configuration.blocking'));
        self::assertSame('0.3', $this->http->lastHeader('A2A-Version'));
    }

    public function testRestGetTaskWithHistoryLength(): void
    {
        $this->http->queueJson(['id' => 't', 'contextId' => 'c', 'status' => ['state' => 'TASK_STATE_WORKING']]);

        $task = $this->rest()->getTask(new GetTaskRequest(['id' => 't', 'history_length' => 2]));

        self::assertSame(TaskState::TASK_STATE_WORKING, $task->getStatus()?->getState());
        self::assertSame('http://x/rest/v1/tasks/t?historyLength=2', $this->http->lastRequest()->url);
    }

    public function testRestSubscribeFallsBackToGetAfterA405(): void
    {
        $this->http->queueBody('Method Not Allowed', 405);
        $this->http->queueSseJson([['task' => ['id' => 't', 'contextId' => 'c', 'status' => ['state' => 'TASK_STATE_WORKING']]]]);
        $this->http->queueSseJson([['task' => ['id' => 't', 'contextId' => 'c', 'status' => ['state' => 'TASK_STATE_WORKING']]]]);
        $transport = $this->rest();

        $events = iterator_to_array($transport->subscribe(new SubscribeToTaskRequest(['id' => 't'])), false);
        self::assertSame('task', $events[0]->getPayload());
        self::assertSame(['POST', 'GET'], array_map(static fn($r): string => $r->method, $this->http->requests));

        // The transport remembers GET.
        iterator_to_array($transport->subscribe(new SubscribeToTaskRequest(['id' => 't'])), false);
        self::assertSame('GET', $this->http->lastRequest()->method);
    }

    public function testRestErrorFormats(): void
    {
        // Python's {"type", "message"}.
        $this->http->queueJson(['type' => 'TaskNotFoundError', 'message' => 'gone'], 404);
        try {
            $this->rest()->getTask(new GetTaskRequest(['id' => 'x']));
            self::fail('expected an error');
        } catch (TaskNotFoundError $e) {
            self::assertSame('gone', $e->getMessage());
        }

        // A v1.0 error body with ErrorInfo (what PHP and Python v1.x compat servers send).
        $this->http->queueJson(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Task not found', 'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'TASK_NOT_FOUND', 'domain' => 'a2a-protocol.org']]]], 404);
        try {
            $this->rest()->getTask(new GetTaskRequest(['id' => 'x']));
            self::fail('expected an error');
        } catch (TaskNotFoundError) {
            $this->addToAssertionCount(1);
        }

        // A bare v0.3.x {"message"} 404: only the status is known (Python too).
        $this->http->queueJson(['message' => 'Task not found'], 404);
        $this->expectException(MethodNotFoundError::class);
        $this->rest()->getTask(new GetTaskRequest(['id' => 'x']));
    }

    public function testRestListTasksIsNotSupported(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->rest()->listTasks(new \A2A\Types\ListTasksRequest());
    }

    private function jsonRpc(): CompatJsonRpcTransport
    {
        return new CompatJsonRpcTransport($this->http, Fixtures::agentCard(), 'http://x/rpc');
    }

    private function rest(): CompatRestTransport
    {
        return new CompatRestTransport($this->http, Fixtures::agentCard(), 'http://x/rest');
    }
}
