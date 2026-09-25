<?php

declare(strict_types=1);

namespace A2A\Client\Http;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\A2AClientTimeoutError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends through any PSR-18 client.
 *
 * Limits, both from PSR-18 itself: stream() is NOT incremental (SSE events
 * all arrive when the server closes the stream), and per-request timeouts
 * (ClientCallContext::$timeout) are ignored; configure the timeout on the
 * client you pass in instead. For live streaming use Guzzle or Symfony
 * HttpClient, which HttpSenderFactory detects automatically.
 */
final class Psr18HttpSender implements HttpSender
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $response = $this->sendRequest($request);

        return new HttpResponse($response->getStatusCode(), $this->headersOf($response), (string) $response->getBody());
    }

    public function stream(HttpRequest $request): StreamedResponse
    {
        $response = $this->sendRequest($request);
        $body = $response->getBody();

        $chunks = (static function () use ($body): \Generator {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }
                yield $chunk;
            }
        })();

        return new StreamedResponse($response->getStatusCode(), $this->headersOf($response), $chunks, static fn() => $body->close());
    }

    public function supportsIncrementalStreaming(): bool
    {
        return false;
    }

    private function sendRequest(HttpRequest $request): ResponseInterface
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            return $this->client->sendRequest($psrRequest);
        } catch (NetworkExceptionInterface $e) {
            if (TimeoutDetector::isTimeout($e)) {
                throw new A2AClientTimeoutError('Client Request timed out', null, $e);
            }

            throw new A2AClientError('Network communication error: ' . $e->getMessage(), null, $e);
        } catch (ClientExceptionInterface $e) {
            throw new A2AClientError('Network communication error: ' . $e->getMessage(), null, $e);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function headersOf(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map('strval', $values));
        }

        return $headers;
    }
}
