<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when a method is not found.
 */
class MethodNotFoundError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Method not found';
}
