<?php

declare(strict_types=1);

namespace A2A\Client\Http;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\A2AClientTimeoutError;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends through Symfony HttpClient (the native client, not its PSR-18
 * adapter). stream() uses HttpClientInterface::stream(), so SSE events are
 * delivered as they arrive.
 */
final class SymfonyHttpSender implements HttpSender
{
    public function __construct(private readonly HttpClientInterface $client) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $response = $this->request($request);

        try {
            return new HttpResponse($response->getStatusCode(), $this->headersOf($response), $response->getContent(false));
        } catch (ExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    public function stream(HttpRequest $request): StreamedResponse
    {
        $response = $this->request($request);

        try {
            $status = $response->getStatusCode();
            $headers = $this->headersOf($response);
        } catch (ExceptionInterface $e) {
            throw $this->translate($e);
        }

        $client = $this->client;
        $idleTimeout = $request->timeout;
        $chunks = (function () use ($client, $response, $idleTimeout): \Generator {
            try {
                foreach ($client->stream($response, $idleTimeout) as $chunk) {
                    if ($chunk->isTimeout()) {
                        // A quiet SSE stream is normal. Only a caller-set timeout ends it.
                        if ($idleTimeout !== null) {
                            throw new A2AClientTimeoutError('Client Request timed out');
                        }
                        continue;
                    }
                    $content = $chunk->getContent();
                    if ($content !== '') {
                        yield $content;
                    }
                    if ($chunk->isLast()) {
                        return;
                    }
                }
            } catch (ExceptionInterface $e) {
                throw $this->translate($e);
            }
        })();

        return new StreamedResponse($status, $headers, $chunks, static fn() => $response->cancel());
    }

    public function supportsIncrementalStreaming(): bool
    {
        return true;
    }

    private function request(HttpRequest $request): ResponseInterface
    {
        $options = ['headers' => $request->headers];
        if ($request->body !== null) {
            $options['body'] = $request->body;
        }
        if ($request->timeout !== null) {
            $options['timeout'] = $request->timeout;
            $options['max_duration'] = $request->timeout;
        }

        try {
            return $this->client->request($request->method, $request->url, $options);
        } catch (ExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function headersOf(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders(false) as $name => $values) {
            $headers[(string) $name] = $values;
        }

        return $headers;
    }

    private function translate(ExceptionInterface $e): A2AClientError
    {
        if (TimeoutDetector::isTimeout($e)) {
            return new A2AClientTimeoutError('Client Request timed out', null, $e);
        }

        return new A2AClientError('Network communication error: ' . $e->getMessage(), null, $e);
    }
}
