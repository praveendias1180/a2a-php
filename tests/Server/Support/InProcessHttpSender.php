<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Support;

use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpResponse;
use A2A\Client\Http\HttpSender;
use A2A\Client\Http\StreamedResponse;
use A2A\Server\RequestHandlers\RequestHandler;
use A2A\Server\Routes\Sse\SseStream;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Connects the PHP client straight to a PHP server handler in the same
 * process: the client's HTTP request becomes a PSR-7 request, the server's
 * PSR-7 response goes back to the client. Background work the server
 * deferred runs after each response, like ResponseEmitter does.
 */
final class InProcessHttpSender implements HttpSender
{
    private readonly Psr17Factory $factory;

    public function __construct(
        private readonly RequestHandlerInterface $server,
        private readonly RequestHandler $requestHandler,
    ) {
        $this->factory = new Psr17Factory();
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $response = $this->server->handle($this->toServerRequest($request));
        $body = (string) $response->getBody();
        $this->requestHandler->runBackgroundWork();

        return new HttpResponse($response->getStatusCode(), self::headers($response), $body);
    }

    public function stream(HttpRequest $request): StreamedResponse
    {
        $response = $this->server->handle($this->toServerRequest($request));
        $body = $response->getBody();
        $handler = $this->requestHandler;
        $chunks = (static function () use ($body, $handler): \Generator {
            if ($body instanceof SseStream) {
                yield from $body->chunks();
            } else {
                yield (string) $body;
            }
            $handler->runBackgroundWork();
        })();

        return new StreamedResponse($response->getStatusCode(), self::headers($response), $chunks, static function () use ($body): void {
            $body->close();
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private static function headers(\Psr\Http\Message\ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return $headers;
    }

    public function supportsIncrementalStreaming(): bool
    {
        return true;
    }

    private function toServerRequest(HttpRequest $request): \Psr\Http\Message\ServerRequestInterface
    {
        $serverRequest = $this->factory->createServerRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $serverRequest = $serverRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $serverRequest = $serverRequest->withBody($this->factory->createStream($request->body));
        }

        return $serverRequest;
    }
}
