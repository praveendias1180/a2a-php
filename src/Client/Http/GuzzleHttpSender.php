<?php

declare(strict_types=1);

namespace A2A\Client\Http;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\A2AClientTimeoutError;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends through Guzzle 7. stream() uses Guzzle's `stream` option, so SSE
 * events are delivered as they arrive.
 *
 * Streaming requests go out as HTTP/1.0. Guzzle streams through PHP's
 * http:// wrapper, and that wrapper's de-chunking holds an HTTP/1.1 chunked
 * body back until the server closes it, which would turn a live SSE stream
 * into one late burst. Over HTTP/1.0 the server sends the body unchunked
 * and closes the connection at the end, which is exactly how an event
 * stream behaves anyway. Non-streaming requests keep HTTP/1.1.
 */
final class GuzzleHttpSender implements HttpSender
{
    public function __construct(private readonly ClientInterface $client) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $response = $this->request($request, false);

        return new HttpResponse($response->getStatusCode(), $this->headersOf($response), (string) $response->getBody());
    }

    public function stream(HttpRequest $request): StreamedResponse
    {
        $response = $this->request($request, true);
        $body = $response->getBody();

        $chunks = (static function () use ($body): \Generator {
            try {
                while (!$body->eof()) {
                    $chunk = $body->read(8192);
                    if ($chunk !== '') {
                        yield $chunk;
                    }
                }
            } catch (\RuntimeException $e) {
                if (TimeoutDetector::isTimeout($e)) {
                    throw new A2AClientTimeoutError('Client Request timed out', null, $e);
                }

                throw new A2AClientError('Network communication error: ' . $e->getMessage(), null, $e);
            }
        })();

        return new StreamedResponse($response->getStatusCode(), $this->headersOf($response), $chunks, static fn() => $body->close());
    }

    public function supportsIncrementalStreaming(): bool
    {
        return true;
    }

    private function request(HttpRequest $request, bool $stream): ResponseInterface
    {
        $options = [
            RequestOptions::HEADERS => $request->headers,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::STREAM => $stream,
        ];
        if ($stream) {
            $options[RequestOptions::VERSION] = '1.0';
        }
        if ($request->body !== null) {
            $options[RequestOptions::BODY] = $request->body;
        }
        if ($request->timeout !== null) {
            $options[RequestOptions::TIMEOUT] = $request->timeout;
            $options[RequestOptions::READ_TIMEOUT] = $request->timeout;
        }

        try {
            return $this->client->request($request->method, $request->url, $options);
        } catch (GuzzleException $e) {
            if (TimeoutDetector::isTimeout($e)) {
                throw new A2AClientTimeoutError('Client Request timed out', null, $e);
            }

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
