<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when invalid JSON was received by the server.
 */
class JSONParseError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Invalid JSON payload';
}
