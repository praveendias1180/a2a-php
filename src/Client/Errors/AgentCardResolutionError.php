<?php

declare(strict_types=1);

namespace A2A\Client\Errors;

/**
 * An agent card could not be fetched, decoded or validated.
 *
 * Mirrors a2a-python: AgentCardResolutionError in src/a2a/client/errors.py
 */
class AgentCardResolutionError extends A2AClientError
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, null, $previous);
    }
}
