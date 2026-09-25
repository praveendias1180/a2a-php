<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Base exception for A2A errors.
 *
 * `data` is public on purpose: error handlers serialize it unaltered into the
 * response (as ErrorInfo.metadata), so never put secrets in it.
 *
 * Mirrors a2a-python: A2AError in src/a2a/utils/errors.py
 */
class A2AError extends \RuntimeException
{
    /**
     * Default message, overridden by each subclass.
     *
     * @var string
     */
    public const DEFAULT_MESSAGE = 'A2A Error';

    /**
     * @param array<array-key, mixed>|null $data
     */
    public function __construct(
        ?string $message = null,
        public readonly ?array $data = null,
        ?\Throwable $previous = null,
    ) {
        // Python treats an empty message like a missing one (`if message:`).
        parent::__construct($message !== null && $message !== '' ? $message : static::DEFAULT_MESSAGE, 0, $previous);
    }

    /**
     * The JSON-RPC error code, or null for an error type with no mapping.
     */
    public function jsonRpcCode(): ?int
    {
        return ErrorMapping::jsonRpcCodeFor(static::class);
    }

    /**
     * The HTTP / gRPC / reason mapping, or null for an error type with no mapping.
     */
    public function mapping(): ?ErrorMapping
    {
        return ErrorMapping::for(static::class);
    }
}
