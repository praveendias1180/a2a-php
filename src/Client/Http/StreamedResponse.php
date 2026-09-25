<?php

declare(strict_types=1);

namespace A2A\Client\Http;

/**
 * An HTTP response whose body is read chunk by chunk, as it arrives.
 *
 * The chunks come from a single-use generator; call close() when done (the
 * transports do this in a `finally`) so an abandoned stream releases the
 * connection, which is what Python's _SSEEventSource.__aexit__ does.
 */
final class StreamedResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    private bool $consumed = false;

    /**
     * @param array<string, string|list<string>> $headers
     * @param iterable<string>                    $chunks
     * @param (\Closure(): void)|null             $onClose
     */
    public function __construct(
        public readonly int $statusCode,
        array $headers,
        private readonly iterable $chunks,
        private readonly ?\Closure $onClose = null,
    ) {
        $this->headers = HttpResponse::normalizeHeaders($headers);
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * @return \Generator<int, string>
     */
    public function chunks(): \Generator
    {
        if ($this->consumed) {
            throw new \LogicException('The response body has already been read.');
        }
        $this->consumed = true;
        foreach ($this->chunks as $chunk) {
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    public function readAll(): string
    {
        $body = '';
        foreach ($this->chunks() as $chunk) {
            $body .= $chunk;
        }

        return $body;
    }

    public function close(): void
    {
        if ($this->onClose !== null) {
            ($this->onClose)();
        }
    }
}
