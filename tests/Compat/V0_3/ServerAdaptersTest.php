<?php

declare(strict_types=1);

namespace A2A\Tests\Compat\V0_3;

use A2A\Compat\V0_3\ExtensionHeaders;
use A2A\Compat\V0_3\FromProto;
use A2A\Compat\V0_3\ToProto;
use A2A\Compat\V0_3\Types as P;
use A2A\Compat\V0_3\V03ServerCallContextBuilder;
use A2A\Compat\V0_3\Versions;
use A2A\Server\RequestHandlers\ResponseHelpers;
use A2A\Server\Routes\DefaultServerCallContextBuilder;
use A2A\Server\Routes\JsonRpcDispatcher;
use A2A\Server\Routes\RestDispatcher;
use A2A\Tests\Server\Routes\DispatcherTestCase;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\AgentInterface;

/**
 * The v0.3 server adapters behind the v1.0 dispatchers, the v0.3 REST wire
 * shape and the small helpers. Ported in spirit from a2a-python
 * tests/compat/v0_3/test_jsonrpc_app_compat.py, test_rest_routes_compat.py,
 * test_rest_handler.py, test_proto_utils.py, test_versions.py,
 * test_extension_headers.py and test_context_builders.py.
 */
final class ServerAdaptersTest extends DispatcherTestCase
{
    private const V03_MESSAGE = '{"kind":"message","messageId":"m1","role":"user","parts":[{"kind":"text","text":"hi"}]}';

    public function testV03MethodsAreOffUnlessEnabled(): void
    {
        $response = (new JsonRpcDispatcher($this->handler))->handle(self::rpc('message/send', '{"message":' . self::V03_MESSAGE . '}'));

        self::assertSame(-32601, self::at(self::json($response), 'error.code'));
    }

    public function testJsonRpcMessageSend(): void
    {
        $data = self::json($this->jsonRpc()->handle(self::rpc('message/send', '{"message":' . self::V03_MESSAGE . '}')));

        self::assertSame('task', self::at($data, 'result.kind'));
        self::assertSame('completed', self::at($data, 'result.status.state'));
        self::assertSame('text', self::at($data, 'result.artifacts.0.parts.0.kind'));
        self::assertSame('done', self::at($data, 'result.artifacts.0.parts.0.text'));
    }

    public function testJsonRpcStreamAndResubscribe(): void
    {
        $events = self::sseEvents($this->jsonRpc()->handle(self::rpc('message/stream', '{"message":' . self::V03_MESSAGE . '}')));

        $kinds = array_map(static fn(array $e): mixed => self::at($e['data'], 'result.kind'), $events);
        self::assertSame(['task', 'status-update', 'artifact-update', 'status-update'], $kinds);
        self::assertTrue(self::at($events[3]['data'], 'result.final'));
        self::assertSame(7, self::at($events[0]['data'], 'id'));

        $taskId = self::at($events[0]['data'], 'result.id');
        self::assertIsString($taskId);
        $done = self::json($this->jsonRpc()->handle(self::rpc('tasks/resubscribe', sprintf('{"id":"%s"}', $taskId))));
        self::assertSame(-32004, self::at($done, 'error.code'), 'resubscribing to a finished task is unsupported');
    }

    public function testJsonRpcGetAndCancelAndErrors(): void
    {
        $data = self::json($this->jsonRpc()->handle(self::rpc('message/send', '{"message":' . self::V03_MESSAGE . '}')));
        $taskId = self::at($data, 'result.id');
        self::assertIsString($taskId);

        $got = self::json($this->jsonRpc()->handle(self::rpc('tasks/get', sprintf('{"id":"%s","historyLength":1}', $taskId))));
        self::assertSame($taskId, self::at($got, 'result.id'));
        self::assertCount(1, self::arrayAt($got, 'result.history'));

        $missing = self::json($this->jsonRpc()->handle(self::rpc('tasks/get', '{"id":"nope"}')));
        self::assertSame(['code' => -32001, 'message' => 'Task not found'], self::at($missing, 'error'), 'A2A errors keep their code (Python sends -32603)');

        $notCancelable = self::json($this->jsonRpc()->handle(self::rpc('tasks/cancel', sprintf('{"id":"%s"}', $taskId))));
        self::assertSame(-32002, self::at($notCancelable, 'error.code'));

        $noPush = self::json($this->jsonRpc()->handle(self::rpc('tasks/pushNotificationConfig/set', sprintf('{"taskId":"%s","pushNotificationConfig":{"url":"https://h"}}', $taskId))));
        self::assertSame(-32003, self::at($noPush, 'error.code'));
    }

    public function testJsonRpcInvalidV03RequestsAreInvalidRequest(): void
    {
        foreach ([
            ['message/send', '{}'],
            ['message/send', '{"message":{"messageId":"m","role":"robot","parts":[]}}'],
            ['tasks/get', '{}'],
            ['tasks/pushNotificationConfig/delete', '{"id":"t"}'],
        ] as [$method, $params]) {
            $data = self::json($this->jsonRpc()->handle(self::rpc($method, $params)));
            self::assertSame(-32600, self::at($data, 'error.code'), "{$method} {$params}");
        }
    }

    public function testJsonRpcRejectsAV10VersionHeaderOnV03Methods(): void
    {
        $request = self::rpc('message/send', '{"message":' . self::V03_MESSAGE . '}', ['A2A-Version' => '1.0']);

        self::assertSame(-32009, self::at(self::json($this->jsonRpc()->handle($request)), 'error.code'));
    }

    public function testJsonRpcV10MethodsStillWork(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"SendMessage","params":{"message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"hi"}]}}}';

        $data = self::json($this->jsonRpc()->handle(self::request('POST', '/', $body)));

        self::assertSame('TASK_STATE_COMPLETED', self::at($data, 'result.task.status.state'));
    }

    public function testRestV03Routes(): void
    {
        $rest = new RestDispatcher($this->handler, '/rest', enableV03Compat: true);
        $body = '{"message":{"messageId":"m1","role":"ROLE_USER","content":[{"text":"hi"}]},"configuration":{"blocking":true}}';

        $sent = self::json($rest->handle(self::request('POST', '/rest/v1/message:send', $body, [])));
        self::assertSame('TASK_STATE_COMPLETED', self::at($sent, 'task.status.state'));
        self::assertSame('done', self::at($sent, 'task.artifacts.0.parts.0.text'));
        self::assertSame('hi', self::at($sent, 'task.history.0.content.0.text'), 'v0.3 REST calls parts "content"');
        $taskId = self::at($sent, 'task.id');
        self::assertIsString($taskId);

        $got = self::json($rest->handle(self::request('GET', "/rest/v1/tasks/{$taskId}?historyLength=1", null, [])));
        self::assertSame($taskId, self::at($got, 'id'));

        $missing = $rest->handle(self::request('GET', '/rest/v1/tasks/nope', null, []));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('TASK_NOT_FOUND', self::at(self::json($missing), 'error.details.0.reason'));

        $events = self::sseEvents($rest->handle(self::request('POST', '/rest/v1/message:stream', $body, [])));
        $last = $events[count($events) - 1]['data'] ?? [];
        self::assertSame('TASK_STATE_COMPLETED', self::at($last, 'statusUpdate.status.state'));
        self::assertTrue(self::at($last, 'statusUpdate.final'));

        // Unrelated paths still reach the v1.0 routes.
        $v10 = self::json($rest->handle(self::request('GET', "/rest/tasks/{$taskId}")));
        self::assertSame('TASK_STATE_COMPLETED', self::at($v10, 'status.state'));
    }

    public function testRestV03RoutesAreOffUnlessEnabled(): void
    {
        $response = (new RestDispatcher($this->handler, '/rest'))->handle(self::request('POST', '/rest/v1/message:send', '{}', []));

        self::assertNotSame(200, $response->getStatusCode());
    }

    public function testRestNoBlockingMeansReturnImmediately(): void
    {
        // v0.3 REST: `blocking` is a plain bool that defaults to false.
        $rest = new RestDispatcher($this->handler, '/rest', enableV03Compat: true);
        $sent = self::json($rest->handle(self::request('POST', '/rest/v1/message:send', '{"message":{"messageId":"m1","role":"ROLE_USER","content":[{"text":"hi"}]}}', [])));

        self::assertSame('TASK_STATE_SUBMITTED', self::at($sent, 'task.status.state'));
    }

    public function testTheCardCarriesTheV03FieldsWhenItOffersAV03Interface(): void
    {
        $card = Fixtures::agentCard();
        self::assertObjectNotHasProperty('url', ResponseHelpers::agentCardToDict($card), 'a v1.0-only card is served unchanged');

        $card->setSupportedInterfaces([...iterator_to_array($card->getSupportedInterfaces()), new AgentInterface(['url' => 'http://a/rpc', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '0.3'])]);
        $served = ResponseHelpers::agentCardToDict($card);

        self::assertSame('http://a/rpc', $served->url);
        self::assertSame('JSONRPC', $served->preferredTransport);
        self::assertSame('0.3', $served->protocolVersion);
        self::assertIsArray($served->supportedInterfaces);
        self::assertCount(3, $served->supportedInterfaces, 'the v1.0 fields are untouched');
        self::assertObjectNotHasProperty('supportsAuthenticatedExtendedCard', $served, 'left out when false');
    }

    public function testProtoShapeOfTheRestBinding(): void
    {
        $task = ToProto::task(self::obj('{"kind":"task","id":"t","contextId":"c","status":{"state":"canceled","timestamp":"2024-01-01T00:00:00Z"},"history":[{"kind":"message","messageId":"m","role":"agent","parts":[{"kind":"file","file":{"bytes":"aGk=","name":"f"}}]}]}'));

        self::assertJsonStringEqualsJsonString(
            // file_with_bytes holds the base64 text, so ProtoJSON encodes it again (v0.3 did this).
            '{"id":"t","contextId":"c","status":{"state":"TASK_STATE_CANCELLED","timestamp":"2024-01-01T00:00:00Z"},"history":[{"messageId":"m","role":"ROLE_AGENT","content":[{"file":{"fileWithBytes":"YUdrPQ==","name":"f"}}]}]}',
            $task->serializeToJsonString(),
        );

        $back = json_decode(json_encode(FromProto::task($task), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('canceled', self::at($back, 'status.state'));
        self::assertSame('aGk=', self::at($back, 'history.0.parts.0.file.bytes'));
        self::assertSame('2024-01-01T00:00:00Z', self::at($back, 'status.timestamp'));
    }

    public function testFromProtoPushConfigNames(): void
    {
        $config = FromProto::taskPushNotificationConfig(new P\TaskPushNotificationConfig(['name' => 'tasks/t1/pushNotificationConfigs/c1', 'push_notification_config' => new P\PushNotificationConfig(['id' => 'c1', 'url' => 'https://h'])]));
        self::assertSame('t1', $config->taskId);

        $this->expectException(\A2A\Utils\Errors\InvalidParamsError::class);
        FromProto::taskPushNotificationConfig(new P\TaskPushNotificationConfig(['name' => 'bad']));
    }

    public function testVersionsAndExtensionHeaders(): void
    {
        self::assertTrue(Versions::isLegacyVersion('0.3'));
        self::assertTrue(Versions::isLegacyVersion('0.3.0'));
        self::assertTrue(Versions::isLegacyVersion('0.9'));
        self::assertFalse(Versions::isLegacyVersion('1.0'));
        self::assertFalse(Versions::isLegacyVersion('0.2.5'));
        self::assertFalse(Versions::isLegacyVersion(''));
        self::assertFalse(Versions::isLegacyVersion('not-a-version'));

        self::assertSame(['A2A-Extensions' => 'a', 'X-A2A-Extensions' => 'a'], ExtensionHeaders::addLegacyExtensionHeader(['A2A-Extensions' => 'a']));
        self::assertSame(['a2a-extensions' => 'a', 'x-a2a-extensions' => 'b'], ExtensionHeaders::addLegacyExtensionHeader(['a2a-extensions' => 'a', 'x-a2a-extensions' => 'b']));
        self::assertSame([], ExtensionHeaders::addLegacyExtensionHeader([]));
    }

    public function testTheContextBuilderReadsTheLegacyExtensionHeader(): void
    {
        $request = self::request('POST', '/', '{}', ['A2A-Extensions' => 'https://a', 'X-A2A-Extensions' => 'https://b, https://a']);

        $context = (new V03ServerCallContextBuilder(new DefaultServerCallContextBuilder()))->build($request);

        self::assertSame(['https://a', 'https://b'], $context->requestedExtensions);
    }

    private function jsonRpc(): JsonRpcDispatcher
    {
        return new JsonRpcDispatcher($this->handler, enableV03Compat: true);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function rpc(string $method, string $params, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        return self::request('POST', '/', sprintf('{"jsonrpc":"2.0","id":7,"method":"%s","params":%s}', $method, $params), $headers + ['Content-Type' => 'application/json']);
    }

    private static function obj(string $json): \stdClass
    {
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $value);

        return $value;
    }
}
