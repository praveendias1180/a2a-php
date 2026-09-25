<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Routes;

use A2A\Server\Routes\AgentCardHandler;
use A2A\Server\Routes\Common;
use A2A\Server\Routes\DefaultServerCallContextBuilder;
use A2A\Server\Routes\Routes;
use A2A\Server\Routes\Sse\SseStream;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Tests\Server\Support\NamedUser;
use A2A\Types\ListTasksResponse;
use A2A\Types\TaskState;

/**
 * Mirrors a2a-python tests/server/routes/test_agent_card_routes.py and
 * test_common.py, plus the PHP router and SSE body.
 */
final class RoutesTest extends DispatcherTestCase
{
    public function testAgentCardWithCachingHeaders(): void
    {
        $handler = new AgentCardHandler(Fixtures::agentCard(), maxAgeSeconds: 60);
        $response = $handler->handle(self::request('GET', '/.well-known/agent-card.json'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Test Agent', self::at(self::json($response), 'name'));
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $response->getHeaderLine('ETag'));
        self::assertStringEndsWith(' GMT', $response->getHeaderLine('Last-Modified'));

        $cached = $handler->handle(self::request('GET', '/.well-known/agent-card.json', null, ['If-None-Match' => $response->getHeaderLine('ETag')]));
        self::assertSame(304, $cached->getStatusCode());
        self::assertSame('', (string) $cached->getBody());
    }

    public function testAgentCardModifier(): void
    {
        $handler = Routes::agentCard(Fixtures::agentCard(), static function (\A2A\Types\AgentCard $card): \A2A\Types\AgentCard {
            $copy = new \A2A\Types\AgentCard();
            $copy->mergeFrom($card);
            $copy->setName('Modified');

            return $copy;
        });

        self::assertSame('Modified', self::at(self::json($handler->handle(self::request('GET', '/'))), 'name'));
    }

    public function testRouterServesAllThree(): void
    {
        $router = Routes::router($this->handler, Fixtures::agentCard(), jsonRpcPath: '/a2a/jsonrpc', restPrefix: '/a2a/rest');

        self::assertSame('Test Agent', self::at(self::json($router->handle(self::request('GET', '/.well-known/agent-card.json'))), 'name'));
        self::assertSame(-32001, self::at(self::json($router->handle(self::request('POST', '/a2a/jsonrpc', '{"jsonrpc":"2.0","id":1,"method":"GetTask","params":{"id":"x"}}'))), 'error.code'));
        self::assertSame(404, $router->handle(self::request('GET', '/a2a/rest/tasks/x'))->getStatusCode());
        self::assertSame(200, $router->handle(self::request('GET', '/a2a/rest/tasks'))->getStatusCode());
        self::assertSame(404, $router->handle(self::request('GET', '/elsewhere'))->getStatusCode());
    }

    public function testContextBuilder(): void
    {
        $request = self::request('POST', '/', null, ['A2A-Version' => '1.0', 'A2A-Extensions' => 'https://a/v1, https://b/v1'])
            ->withAttribute(DefaultServerCallContextBuilder::USER_ATTRIBUTE, new NamedUser('alice'));

        $context = (new DefaultServerCallContextBuilder())->build($request);

        self::assertSame('alice', $context->user->userName());
        self::assertSame('1.0', self::at($context->headers(), 'a2a-version') ?? null);
        self::assertSame(['https://a/v1', 'https://b/v1'], $context->requestedExtensions);
        self::assertFalse((new DefaultServerCallContextBuilder())->build(self::request('GET', '/'))->user->isAuthenticated());
    }

    public function testSerializeListTasksResponseAlwaysHasPagingFields(): void
    {
        $empty = Common::serializeListTasksResponse(new ListTasksResponse(['page_size' => 50]), false);
        self::assertEquals(['tasks' => [], 'nextPageToken' => '', 'pageSize' => 50, 'totalSize' => 0], (array) $empty);

        $task = Fixtures::task('t', TaskState::TASK_STATE_COMPLETED);
        $withArtifacts = Common::serializeListTasksResponse(new ListTasksResponse(['tasks' => [$task], 'page_size' => 1, 'total_size' => 1]), true);
        self::assertIsArray($withArtifacts->tasks);
        $first = $withArtifacts->tasks[0];
        self::assertInstanceOf(\stdClass::class, $first);
        self::assertSame([], $first->artifacts);
        self::assertSame([], $first->history);
    }

    public function testSseStream(): void
    {
        self::assertSame("event: error\ndata: a\ndata: b\n\n", SseStream::event("a\nb", 'error'));
        self::assertSame(": keep-alive\n\n", SseStream::keepAlive());

        $stream = new SseStream((static function (): \Generator {
            yield 'one';
            yield 'two';
        })());
        self::assertFalse($stream->eof());
        self::assertSame('one', $stream->read(8192));
        self::assertSame('two', $stream->read(8192));
        self::assertSame('', $stream->read(8192));
        self::assertTrue($stream->eof());
        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
    }

    public function testSseStreamDrainsOnDisconnectOnlyWhenAsked(): void
    {
        $pulled = 0;
        $chunks = static function () use (&$pulled): \Generator {
            foreach (['a', 'b', 'c'] as $chunk) {
                ++$pulled;
                yield $chunk;
            }
        };

        $subscribe = new SseStream($chunks());
        $subscribe->read(0);
        $subscribe->clientDisconnected();
        self::assertSame(1, $pulled);

        $pulled = 0;
        $send = new SseStream($chunks(), drainOnDisconnect: true);
        $send->read(0);
        $send->clientDisconnected();
        self::assertSame(3, $pulled, 'the agent keeps working after the client leaves');
        self::assertTrue($send->eof());
    }
}
