<?php

declare(strict_types=1);

namespace A2A\Client\Http;

/**
 * A fully read HTTP response.
 */
final class HttpResponse
{
    /** @var array<string, string> header names lower-cased, repeated values joined with ", " */
    public readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        array $headers,
        public readonly string $body,
    ) {
        $this->headers = self::normalizeHeaders($headers);
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, string>
     */
    public static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : $value;
        }

        return $normalized;
    }
}
