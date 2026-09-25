<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Routes;

use A2A\Server\Routes\JsonRpcDispatcher;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\TaskState;
use Psr\Log\AbstractLogger;

/**
 * Mirrors a2a-python tests/server/routes/test_jsonrpc_dispatcher.py.
 */
final class JsonRpcDispatcherTest extends DispatcherTestCase
{
    public function testSendMessage(): void
    {
        $response = $this->call('SendMessage', ['message' => ['messageId' => 'm-1', 'role' => 'ROLE_USER', 'parts' => [['text' => 'hi']]]]);

        self::assertSame(200, $response['status']);
        self::assertSame('2.0', self::at($response, 'body.jsonrpc'));
        self::assertSame(1, self::at($response, 'body.id'));
        self::assertSame('TASK_STATE_COMPLETED', self::at($response, 'body.result.task.status.state'));
        self::assertSame('done', self::at($response, 'body.result.task.artifacts.0.parts.0.text'));
    }

    public function testGetListAndCancel(): void
    {
        $this->store->save(Fixtures::task('open', TaskState::TASK_STATE_INPUT_REQUIRED, 'c', 10), Fixtures::callContext());

        self::assertSame('open', self::at($this->call('GetTask', ['id' => 'open']), 'body.result.id'));

        $list = self::arrayAt($this->call('ListTasks', []), 'body.result');
        self::assertEqualsCanonicalizing(['tasks', 'nextPageToken', 'pageSize', 'totalSize'], array_keys($list));
        self::assertSame('', $list['nextPageToken']);
        self::assertSame([], self::at($list, 'tasks.0.history'));
        self::assertArrayNotHasKey('artifacts', self::arrayAt($list, 'tasks.0'));

        self::assertSame('TASK_STATE_CANCELED', self::at($this->call('CancelTask', ['id' => 'open']), 'body.result.status.state'));
        self::assertSame(-32002, self::at($this->call('CancelTask', ['id' => 'open']), 'body.error.code'), 'TaskNotCancelableError');
    }

    public function testA2AErrorsCarryErrorInfo(): void
    {
        $error = self::arrayAt($this->call('GetTask', ['id' => 'missing']), 'body.error');

        self::assertSame(-32001, $error['code']);
        self::assertSame('type.googleapis.com/google.rpc.ErrorInfo', self::at($error, 'data.0.@type'));
        self::assertSame('TASK_NOT_FOUND', self::at($error, 'data.0.reason'));
    }

    public function testEnvelopeErrors(): void
    {
        $dispatcher = new JsonRpcDispatcher($this->handler);
        $send = static fn(string $body): array => self::json($dispatcher->handle(self::request('POST', '/', $body)));

        self::assertSame(-32700, self::at($send('{not json'), 'error.code'));
        self::assertNull(self::at($send('{not json'), 'id'));
        self::assertSame(-32600, self::at($send('[{"jsonrpc":"2.0","id":1,"method":"GetTask"}]'), 'error.code'), 'batches are not supported');
        self::assertSame(-32600, self::at($send('"text"'), 'error.code'));
        self::assertSame(-32600, self::at($send('{"jsonrpc":"1.0","id":1,"method":"GetTask"}'), 'error.code'));
        self::assertSame(-32600, self::at($send('{"jsonrpc":"2.0","id":1}'), 'error.code'));
        self::assertSame(-32600, self::at($send('{"jsonrpc":"2.0","id":1.5,"method":"GetTask"}'), 'error.code'));
        self::assertSame(-32601, self::at($send('{"jsonrpc":"2.0","id":"x","method":"NoSuchMethod"}'), 'error.code'));
        self::assertSame('x', self::at($send('{"jsonrpc":"2.0","id":"x","method":"NoSuchMethod"}'), 'id'));
        self::assertSame(-32602, self::at($send('{"jsonrpc":"2.0","id":1,"method":"GetTask","params":{"id":"x","historyLength":"many"}}'), 'error.code'));
        self::assertSame(-32602, self::at($send('{"jsonrpc":"2.0","id":1,"method":"GetTask","params":{}}'), 'error.code'), 'missing required id');
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        $this->store->save(Fixtures::task('t', TaskState::TASK_STATE_WORKING), Fixtures::callContext());

        self::assertSame('t', self::at($this->call('GetTask', ['id' => 't', 'someFutureField' => true]), 'body.result.id'));
    }

    public function testVersionHeader(): void
    {
        $dispatcher = new JsonRpcDispatcher($this->handler);
        $body = '{"jsonrpc":"2.0","id":1,"method":"GetTask","params":{"id":"x"}}';

        $old = self::json($dispatcher->handle(self::request('POST', '/', $body, ['A2A-Version' => '0.3'])));
        self::assertSame(-32009, self::at($old, 'error.code'), 'VersionNotSupportedError');
        $missing = self::json($dispatcher->handle(self::request('POST', '/', $body, [])));
        self::assertSame(-32009, self::at($missing, 'error.code'), 'no header means 0.3');
        $patch = self::json($dispatcher->handle(self::request('POST', '/', $body, ['a2a-version' => '1.0'])));
        self::assertSame(-32001, self::at($patch, 'error.code'), 'header name is case-insensitive');
    }

    public function testInternalErrorsHideTheMessageAndAreLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->levels[] = is_string($level) ? $level : get_debug_type($level);
            }
        };
        $handler = $this->makeHandler(new CallbackExecutor(static function (): void {
            throw new \RuntimeException('SQLSTATE secret details');
        }));
        $dispatcher = new JsonRpcDispatcher($handler, logger: $logger);

        $body = self::json($dispatcher->handle(self::request('POST', '/', '{"jsonrpc":"2.0","id":1,"method":"SendMessage","params":{"message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"x"}]}}}')));

        self::assertSame(['code' => -32603, 'message' => 'Internal error'], $body['error']);
        self::assertContains('error', $logger->levels);
    }

    public function testStreamingMessage(): void
    {
        $dispatcher = new JsonRpcDispatcher($this->handler);
        $response = $dispatcher->handle(self::request('POST', '/', '{"jsonrpc":"2.0","id":7,"method":"SendStreamingMessage","params":{"message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"x"}]}}}'));

        self::assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
        $events = self::sseEvents($response);
        self::assertSame(['task', 'statusUpdate', 'artifactUpdate', 'statusUpdate'], array_map(static fn(array $e): string => (string) array_key_first(self::arrayAt($e, 'data.result')), $events));
        foreach ($events as $event) {
            self::assertSame(7, self::at($event, 'data.id'));
            self::assertNull($event['event']);
        }
    }

    public function testStreamSetupErrorsAreJson(): void
    {
        $dispatcher = new JsonRpcDispatcher($this->handler);

        $response = $dispatcher->handle(self::request('POST', '/', '{"jsonrpc":"2.0","id":1,"method":"SubscribeToTask","params":{"id":"missing"}}'));

        self::assertSame(-32001, self::at(self::json($response), 'error.code'));
    }

    public function testErrorsDuringAStreamBecomeErrorEvents(): void
    {
        $handler = $this->makeHandler(new CallbackExecutor(Fixtures::taskScript(static function (): void {
            throw new \A2A\Utils\Errors\InvalidAgentResponseError('bad agent');
        })));
        $dispatcher = new JsonRpcDispatcher($handler);

        $events = self::sseEvents($dispatcher->handle(self::request('POST', '/', '{"jsonrpc":"2.0","id":1,"method":"SendStreamingMessage","params":{"message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"x"}]}}}')));

        $last = end($events);
        self::assertIsArray($last);
        self::assertSame('error', $last['event']);
        self::assertSame(-32006, self::at($last, 'data.error.code'));
    }

    public function testOnlyPost(): void
    {
        $response = (new JsonRpcDispatcher($this->handler))->handle(self::request('GET', '/'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function testTenantFromParams(): void
    {
        $seen = null;
        $handler = $this->makeHandler(new CallbackExecutor(static function (\A2A\Server\AgentExecution\RequestContext $c, \A2A\Server\Events\EventQueue $q) use (&$seen): void {
            $seen = $c->tenant();
            $q->enqueueEvent(new \A2A\Types\Message(['message_id' => 'r', 'role' => 2]));
        }));

        (new JsonRpcDispatcher($handler))->handle(self::request('POST', '/', '{"jsonrpc":"2.0","id":1,"method":"SendMessage","params":{"tenant":"acme","message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"x"}]}}}'));

        self::assertSame('acme', $seen);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function call(string $method, array $params): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], JSON_THROW_ON_ERROR);
        $response = (new JsonRpcDispatcher($this->handler))->handle(self::request('POST', '/', $body));

        return ['status' => $response->getStatusCode(), 'body' => self::json($response)];
    }
}
