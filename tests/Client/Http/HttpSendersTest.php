<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Http;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\A2AClientTimeoutError;
use A2A\Client\Http\GuzzleHttpSender;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpSender;
use A2A\Client\Http\HttpSenderFactory;
use A2A\Client\Http\Psr18HttpSender;
use A2A\Client\Http\SymfonyHttpSender;
use A2A\Tests\Client\Support\FakeHttpSender;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as NyholmResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpSendersTest extends TestCase
{
    public function testFactoryPicksTheSenderForEachClient(): void
    {
        $fake = new FakeHttpSender();
        $psr18 = self::psr18(static fn(): ResponseInterface => new NyholmResponse());

        self::assertSame($fake, HttpSenderFactory::create($fake));
        self::assertInstanceOf(GuzzleHttpSender::class, HttpSenderFactory::create(new GuzzleClient()));
        self::assertInstanceOf(SymfonyHttpSender::class, HttpSenderFactory::create(new MockHttpClient()));
        self::assertInstanceOf(Psr18HttpSender::class, HttpSenderFactory::create($psr18));
        self::assertInstanceOf(GuzzleHttpSender::class, HttpSenderFactory::create(), 'Guzzle is preferred when installed');
    }

    public function testFactoryRejectsUnknownObjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HttpSenderFactory::create(new \stdClass());
    }

    public function testPsr18SenderSendsAndReportsItBuffers(): void
    {
        $seen = null;
        $sender = HttpSenderFactory::create(self::psr18(static function (RequestInterface $request) use (&$seen): ResponseInterface {
            $seen = $request;

            return new NyholmResponse(201, ['Content-Type' => 'application/json', 'X-Multi' => ['a', 'b']], '{"ok":true}');
        }));

        $response = $sender->send(new HttpRequest('POST', 'https://agent.example.com/rpc', ['A2A-Version' => '1.0'], '{"x":1}'));

        self::assertInstanceOf(RequestInterface::class, $seen);
        self::assertSame('POST', $seen->getMethod());
        self::assertSame('https://agent.example.com/rpc', (string) $seen->getUri());
        self::assertSame('1.0', $seen->getHeaderLine('A2A-Version'));
        self::assertSame('{"x":1}', (string) $seen->getBody());
        self::assertSame(201, $response->statusCode);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame('a, b', $response->header('X-Multi'));
        self::assertFalse($sender->supportsIncrementalStreaming());
    }

    public function testPsr18SenderStreamsTheBufferedBody(): void
    {
        $body = str_repeat('x', 20000);
        $sender = HttpSenderFactory::create(self::psr18(static fn(): ResponseInterface => new NyholmResponse(200, ['Content-Type' => 'text/event-stream'], $body)));

        $response = $sender->stream(new HttpRequest('GET', 'https://agent.example.com/s'));

        self::assertSame('text/event-stream', $response->header('Content-Type'));
        self::assertSame($body, $response->readAll());
        $response->close();
    }

    public function testPsr18NetworkErrorsAreTranslated(): void
    {
        $timeout = self::psr18(static function (RequestInterface $request): ResponseInterface {
            throw new class ('Operation timed out after 5000 milliseconds', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        });

        $this->expectException(A2AClientTimeoutError::class);
        HttpSenderFactory::create($timeout)->send(new HttpRequest('GET', 'https://agent.example.com'));
    }

    public function testGuzzleSenderSendsOptionsAndDoesNotThrowOnHttpErrors(): void
    {
        $history = [];
        $mock = new MockHandler([new GuzzleResponse(500, ['Content-Type' => 'application/json'], '{"error":{}}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $sender = new GuzzleHttpSender(new GuzzleClient(['handler' => $stack]));

        $response = $sender->send(new HttpRequest('POST', 'https://agent.example.com/rpc', ['X-A' => '1'], 'body', 7.5));

        self::assertSame(500, $response->statusCode);
        self::assertSame('{"error":{}}', $response->body);
        $sent = self::historyEntry($history);
        self::assertSame('1', $sent['request']->getHeaderLine('X-A'));
        self::assertSame('body', $sent['request']->getBody()->__toString());
        self::assertSame(7.5, $sent['options']['timeout'] ?? null);
        self::assertFalse($sent['options']['stream'] ?? null);
        self::assertTrue($sender->supportsIncrementalStreaming());
    }

    public function testGuzzleStreamRequestsGoOutAsHttp10(): void
    {
        $history = [];
        $mock = new MockHandler([new GuzzleResponse(200, ['Content-Type' => 'text/event-stream'], "data: a\n\n")]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $sender = new GuzzleHttpSender(new GuzzleClient(['handler' => $stack]));

        $response = $sender->stream(new HttpRequest('POST', 'https://agent.example.com/s', [], '{}'));

        self::assertSame("data: a\n\n", $response->readAll());
        $sent = self::historyEntry($history);
        self::assertTrue($sent['options']['stream'] ?? null);
        self::assertSame('1.0', $sent['request']->getProtocolVersion());
    }

    public function testGuzzleConnectTimeoutIsATimeout(): void
    {
        $mock = new MockHandler([new ConnectException('cURL error 28: Connection timed out', new GuzzleRequest('GET', 'https://agent.example.com'))]);
        $sender = new GuzzleHttpSender(new GuzzleClient(['handler' => HandlerStack::create($mock)]));

        $this->expectException(A2AClientTimeoutError::class);
        $sender->send(new HttpRequest('GET', 'https://agent.example.com'));
    }

    public function testGuzzleConnectFailureIsANetworkError(): void
    {
        $mock = new MockHandler([new ConnectException('cURL error 7: Failed to connect', new GuzzleRequest('GET', 'https://agent.example.com'))]);
        $sender = new GuzzleHttpSender(new GuzzleClient(['handler' => HandlerStack::create($mock)]));

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('Network communication error: cURL error 7');
        $sender->send(new HttpRequest('GET', 'https://agent.example.com'));
    }

    public function testSymfonySenderStreamsChunksAsTheyArrive(): void
    {
        $capturedMethod = null;
        $capturedOptions = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedOptions): MockResponse {
            $capturedMethod = $method;
            $capturedOptions = $options;

            return new MockResponse((static function (): \Generator {
                yield "data: one\n\n";
                yield "data: two\n\n";
            })(), ['http_code' => 200, 'response_headers' => ['Content-Type: text/event-stream']]);
        });
        $sender = new SymfonyHttpSender($client);

        $response = $sender->stream(new HttpRequest('POST', 'https://agent.example.com/s', ['A2A-Version' => '1.0'], '{}', 12.0));

        self::assertSame('text/event-stream', $response->header('content-type'));
        self::assertSame(["data: one\n\n", "data: two\n\n"], iterator_to_array($response->chunks(), false));
        self::assertSame('POST', $capturedMethod);
        self::assertSame(12.0, $capturedOptions['timeout'] ?? null);
        self::assertSame('{}', $capturedOptions['body'] ?? null);
    }

    public function testSymfonyIdleChunkIsNotAnErrorWithoutACallerTimeout(): void
    {
        // In MockResponse an empty chunk simulates an idle period (a timeout
        // chunk). A quiet SSE stream is normal, so without a caller timeout the
        // sender skips it instead of failing. (The mock then ends the stream;
        // the real client keeps waiting.)
        $client = new MockHttpClient(new MockResponse((static function (): \Generator {
            yield "data: one\n\n";
            yield '';
            yield "data: two\n\n";
        })(), ['response_headers' => ['Content-Type: text/event-stream']]));

        $response = (new SymfonyHttpSender($client))->stream(new HttpRequest('GET', 'https://agent.example.com/s'));

        self::assertSame("data: one\n\n", $response->readAll());
    }

    public function testSymfonyIdleStreamTimesOutWithACallerTimeout(): void
    {
        $client = new MockHttpClient(new MockResponse((static function (): \Generator {
            yield "data: one\n\n";
            yield '';
        })(), ['response_headers' => ['Content-Type: text/event-stream']]));

        $response = (new SymfonyHttpSender($client))->stream(new HttpRequest('GET', 'https://agent.example.com/s', [], null, 5.0));

        $this->expectException(A2AClientTimeoutError::class);
        $response->readAll();
    }

    public function testSymfonySenderSendReturnsErrorStatusesAsResponses(): void
    {
        $sender = new SymfonyHttpSender(new MockHttpClient(new MockResponse('{"error":{}}', ['http_code' => 404])));

        $response = $sender->send(new HttpRequest('GET', 'https://agent.example.com/tasks/x'));

        self::assertSame(404, $response->statusCode);
        self::assertSame('{"error":{}}', $response->body);
    }

    public function testSymfonyTransportErrorsAreTranslated(): void
    {
        $sender = new SymfonyHttpSender(new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Idle timeout reached for "https://agent.example.com".');
        }));

        $this->expectException(A2AClientTimeoutError::class);
        $sender->send(new HttpRequest('GET', 'https://agent.example.com'));
    }

    public function testSymfonyNetworkErrorsAreTranslated(): void
    {
        $sender = new SymfonyHttpSender(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host'])));

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('Network communication error');
        $sender->send(new HttpRequest('GET', 'https://agent.example.com'));
    }

    /**
     * The single request Guzzle's history middleware recorded.
     *
     * @return array{request: RequestInterface, options: array<mixed>}
     */
    private static function historyEntry(mixed $history): array
    {
        self::assertIsArray($history);
        self::assertCount(1, $history);
        $entry = $history[0] ?? null;
        self::assertIsArray($entry);
        $request = $entry['request'] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        $options = $entry['options'] ?? null;
        self::assertIsArray($options);

        return ['request' => $request, 'options' => $options];
    }

    /**
     * @param callable(RequestInterface): ResponseInterface $handler
     */
    private static function psr18(callable $handler): ClientInterface
    {
        return new class ($handler) implements ClientInterface {
            /** @var callable(RequestInterface): ResponseInterface */
            private $handler;

            /**
             * @param callable(RequestInterface): ResponseInterface $handler
             */
            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        };
    }

    /**
     * Keeps the unused-import checker quiet about Psr17Factory, which the
     * factory discovers for the PSR-18 sender.
     */
    public function testNyholmIsDiscoverableForPsr17(): void
    {
        self::assertInstanceOf(HttpSender::class, new Psr18HttpSender(self::psr18(static fn(): ResponseInterface => new NyholmResponse()), new Psr17Factory(), new Psr17Factory()));
    }
}
