<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Routes;

use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\Sse\SseStream;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\AgentCard;
use A2A\Types\Part;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class DispatcherTestCase extends TestCase
{
    protected InMemoryTaskStore $store;

    protected DefaultRequestHandler $handler;

    protected function setUp(): void
    {
        $this->store = new InMemoryTaskStore();
        $this->handler = $this->makeHandler();
    }

    protected function makeHandler(?CallbackExecutor $executor = null, ?AgentCard $card = null): DefaultRequestHandler
    {
        return new DefaultRequestHandler(
            agentExecutor: $executor ?? new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
                $u->startWork();
                $u->addArtifact([new Part(['text' => 'done'])], artifactId: 'a-1');
                $u->complete();
            })),
            taskStore: $this->store,
            agentCard: $card ?? Fixtures::agentCard(),
            queueManager: new InMemoryQueueManager(),
            subscribePollSeconds: 0.01,
            maxSubscribeIdleSeconds: 0.2,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    protected static function request(string $method, string $uri, ?string $body = null, array $headers = ['A2A-Version' => '1.0', 'Content-Type' => 'application/json']): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $body === null ? $request : $request->withBody($factory->createStream($body));
    }

    /**
     * @return array<mixed>
     */
    protected static function json(ResponseInterface $response): array
    {
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * The value at a dotted path ("result.task.status.state", "tasks.0.id")
     * in decoded JSON, or null when the path doesn't exist.
     */
    protected static function at(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data)) {
                return null;
            }
            $data = $data[ctype_digit($key) ? (int) $key : $key] ?? null;
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    protected static function arrayAt(mixed $data, string $path): array
    {
        $value = self::at($data, $path);
        self::assertIsArray($value, $path);

        return $value;
    }

    /**
     * @return list<array{event: ?string, data: array<mixed>}>
     */
    protected static function sseEvents(ResponseInterface $response): array
    {
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertInstanceOf(SseStream::class, $response->getBody());
        $events = [];
        foreach (preg_split("/\n\n/", trim((string) $response->getBody())) ?: [] as $frame) {
            if (str_starts_with($frame, ':')) {
                continue;
            }
            $event = null;
            $data = '';
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data .= substr($line, 6);
                }
            }
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $events[] = ['event' => $event, 'data' => $decoded];
        }

        return $events;
    }
}
