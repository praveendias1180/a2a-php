<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Routes;

use A2A\Server\Routes\RestDispatcher;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\TaskState;

/**
 * Mirrors a2a-python tests/server/routes/test_rest_dispatcher.py and
 * test_rest_routes.py.
 */
final class RestDispatcherTest extends DispatcherTestCase
{
    private RestDispatcher $rest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rest = new RestDispatcher($this->handler, '/a2a/rest');
    }

    public function testSendMessage(): void
    {
        $response = $this->rest->handle(self::request('POST', '/a2a/rest/message:send', '{"message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"hi"}]}}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('TASK_STATE_COMPLETED', self::at(self::json($response), 'task.status.state'));
    }

    public function testTaskRoutes(): void
    {
        $task = Fixtures::task('t-1', TaskState::TASK_STATE_INPUT_REQUIRED, 'ctx', 10);
        $task->setHistory([Fixtures::userMessage('a', 'm1'), Fixtures::userMessage('b', 'm2')]);
        $this->store->save($task, Fixtures::callContext());

        $get = self::json($this->rest->handle(self::request('GET', '/a2a/rest/tasks/t-1?historyLength=1')));
        self::assertSame('t-1', $get['id']);
        self::assertCount(1, self::arrayAt($get, 'history'));

        $list = self::json($this->rest->handle(self::request('GET', '/a2a/rest/tasks?contextId=ctx&pageSize=10')));
        self::assertSame(1, $list['totalSize']);
        self::assertSame(10, $list['pageSize']);
        self::assertSame('', $list['nextPageToken']);

        $cancel = $this->rest->handle(self::request('POST', '/a2a/rest/tasks/t-1:cancel'));
        self::assertSame('TASK_STATE_CANCELED', self::at(self::json($cancel), 'status.state'));
    }

    public function testErrorStatusCodesAndPayloads(): void
    {
        $this->store->save(Fixtures::task('done', TaskState::TASK_STATE_COMPLETED), Fixtures::callContext());
        $cases = [
            ['GET', '/a2a/rest/tasks/missing', null, 404, 'TASK_NOT_FOUND'],
            ['POST', '/a2a/rest/tasks/done:cancel', null, 409, 'TASK_NOT_CANCELABLE'],
            ['POST', '/a2a/rest/tasks/done/pushNotificationConfigs', '{"url":"https://x"}', 400, 'PUSH_NOTIFICATION_NOT_SUPPORTED'],
            ['GET', '/a2a/rest/extendedAgentCard', null, 400, 'UNSUPPORTED_OPERATION'],
            ['POST', '/a2a/rest/message:send', '{not json', 400, 'INVALID_REQUEST'],
        ];
        foreach ($cases as [$method, $path, $body, $status, $reason]) {
            $response = $this->rest->handle(self::request($method, $path, $body));
            $payload = self::json($response);

            self::assertSame($status, $response->getStatusCode(), "{$method} {$path}");
            self::assertSame($status, self::at($payload, 'error.code'));
            self::assertIsString(self::at($payload, 'error.message'));
            self::assertSame($reason, self::at($payload, 'error.details.0.reason'), "{$method} {$path}");
        }
    }

    public function testVersionHeaderIsChecked(): void
    {
        $response = $this->rest->handle(self::request('GET', '/a2a/rest/tasks', null, ['A2A-Version' => '0.3']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VERSION_NOT_SUPPORTED', self::at(self::json($response), 'error.details.0.reason'));
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        $response = $this->rest->handle(self::request('POST', '/a2a/rest/message:send', '{"message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"hi"}]},"futureField":1}'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNotFoundAndWrongMethod(): void
    {
        self::assertSame(404, $this->rest->handle(self::request('GET', '/a2a/rest/nope'))->getStatusCode());
        self::assertSame(404, $this->rest->handle(self::request('DELETE', '/a2a/rest/tasks/x'))->getStatusCode());
        self::assertTrue($this->rest->matches(self::request('GET', '/a2a/rest/tasks')));
        self::assertFalse($this->rest->matches(self::request('GET', '/other')));
    }

    public function testTenantPrefix(): void
    {
        $this->store->save(Fixtures::task('t-1', TaskState::TASK_STATE_WORKING), Fixtures::callContext());

        $response = $this->rest->handle(self::request('GET', '/a2a/rest/acme/tasks/t-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('t-1', self::at(self::json($response), 'id'));
    }

    public function testStreamAndSubscribe(): void
    {
        $stream = self::sseEvents($this->rest->handle(self::request('POST', '/a2a/rest/message:stream', '{"message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"hi"}]}}')));
        self::assertSame(['task', 'statusUpdate', 'artifactUpdate', 'statusUpdate'], array_map(static fn(array $e): string => (string) array_key_first($e['data']), $stream));

        $this->store->save(Fixtures::task('open', TaskState::TASK_STATE_INPUT_REQUIRED), Fixtures::callContext());
        foreach (['GET', 'POST'] as $method) {
            $events = self::sseEvents($this->rest->handle(self::request($method, '/a2a/rest/tasks/open:subscribe')));
            self::assertSame('open', self::at($events, '0.data.task.id'), $method);
        }

        $missing = $this->rest->handle(self::request('GET', '/a2a/rest/tasks/missing:subscribe'));
        self::assertSame(404, $missing->getStatusCode(), 'setup errors are plain JSON');
    }

    public function testPushConfigRoutes(): void
    {
        $handler = new \A2A\Server\RequestHandlers\DefaultRequestHandler(
            new \A2A\Tests\Server\Support\CallbackExecutor(),
            $this->store,
            Fixtures::agentCard(push: true),
            pushConfigStore: new \A2A\Server\Tasks\InMemoryPushNotificationConfigStore(),
        );
        $rest = new RestDispatcher($handler);
        $this->store->save(Fixtures::task('t-1', TaskState::TASK_STATE_WORKING), Fixtures::callContext());

        $created = self::json($rest->handle(self::request('POST', '/tasks/t-1/pushNotificationConfigs', '{"id":"cfg","url":"https://hooks.example"}')));
        self::assertSame('t-1', $created['taskId']);
        self::assertSame('https://hooks.example', self::at(self::json($rest->handle(self::request('GET', '/tasks/t-1/pushNotificationConfigs/cfg'))), 'url'));
        self::assertCount(1, self::arrayAt(self::json($rest->handle(self::request('GET', '/tasks/t-1/pushNotificationConfigs'))), 'configs'));
        self::assertSame([], self::json($rest->handle(self::request('DELETE', '/tasks/t-1/pushNotificationConfigs/cfg'))));
        self::assertSame('{}', (string) $rest->handle(self::request('DELETE', '/tasks/t-1/pushNotificationConfigs/cfg'))->getBody(), 'an empty object, not []');
    }
}
