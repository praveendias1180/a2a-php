<?php

declare(strict_types=1);

namespace A2A\Client\Http;

/**
 * An outgoing HTTP request, independent of the HTTP library that sends it.
 *
 * PHP stand-in for the httpx.Request the Python transports build. The SDK
 * keeps its own tiny request type so the same transport code can run on a
 * plain PSR-18 client or on a client that can stream (Guzzle, Symfony).
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param float|null            $timeout seconds; null means the client's default
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly ?float $timeout = null,
    ) {}

    /**
     * A copy with $name set only if no header of that name (any case) exists,
     * like httpx.Headers.setdefault().
     */
    public function withDefaultHeader(string $name, string $value): self
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return $this;
            }
        }

        return new self($this->method, $this->url, $this->headers + [$name => $value], $this->body, $this->timeout);
    }
}
