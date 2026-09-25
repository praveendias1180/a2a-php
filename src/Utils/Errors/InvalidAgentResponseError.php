<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when the agent response is invalid.
 */
class InvalidAgentResponseError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Invalid agent response';
}
