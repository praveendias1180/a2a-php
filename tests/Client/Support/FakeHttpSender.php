<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Support;

use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpResponse;
use A2A\Client\Http\HttpSender;
use A2A\Client\Http\StreamedResponse;

/**
 * An HttpSender that records requests and replays queued responses, the PHP
 * counterpart of the respx / mocked httpx client in the Python tests.
 */
final class FakeHttpSender implements HttpSender
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    public int $closedStreams = 0;

    /** @var list<array{status: int, headers: array<string, string>, chunks: list<string>}|\Throwable> */
    private array $queue = [];

    public function queueJson(mixed $data, int $status = 200): self
    {
        return $this->queueBody(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json']);
    }

    /**
     * @param array<string, string> $headers
     */
    public function queueBody(string $body, int $status = 200, array $headers = []): self
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'chunks' => [$body]];

        return $this;
    }

    /**
     * Queues an SSE response delivered in the given raw chunks.
     *
     * @param list<string> $chunks
     */
    public function queueSse(array $chunks, int $status = 200): self
    {
        $this->queue[] = ['status' => $status, 'headers' => ['Content-Type' => 'text/event-stream; charset=utf-8'], 'chunks' => $chunks];

        return $this;
    }

    /**
     * Queues one SSE `data:` event per JSON value.
     *
     * @param list<mixed> $payloads
     */
    public function queueSseJson(array $payloads): self
    {
        return $this->queueSse(array_map(
            static fn(mixed $p): string => 'data: ' . json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n\n",
            $payloads,
        ));
    }

    public function queueException(\Throwable $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $entry = $this->next($request);

        return new HttpResponse($entry['status'], $entry['headers'], implode('', $entry['chunks']));
    }

    public function stream(HttpRequest $request): StreamedResponse
    {
        $entry = $this->next($request);

        return new StreamedResponse($entry['status'], $entry['headers'], $entry['chunks'], function (): void {
            $this->closedStreams++;
        });
    }

    public function supportsIncrementalStreaming(): bool
    {
        return true;
    }

    public function lastRequest(): HttpRequest
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }

    /**
     * The decoded JSON body of the last request.
     *
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        $decoded = json_decode($this->lastRequest()->body ?? 'null', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \LogicException('The last request had no JSON object body.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A value inside the last request's JSON body, by dotted path
     * (e.g. "params.message.parts.0.text"), or null when absent.
     */
    public function lastJsonPath(string $path): mixed
    {
        $value = $this->lastJson();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function lastHeader(string $name): ?string
    {
        foreach ($this->lastRequest()->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{status: int, headers: array<string, string>, chunks: list<string>}
     */
    private function next(HttpRequest $request): array
    {
        $this->requests[] = $request;
        $entry = array_shift($this->queue);
        if ($entry === null) {
            throw new \LogicException(sprintf('Unexpected request: %s %s', $request->method, $request->url));
        }
        if ($entry instanceof \Throwable) {
            throw $entry;
        }

        return $entry;
    }
}
