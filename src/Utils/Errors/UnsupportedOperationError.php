<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when an operation is not supported.
 */
class UnsupportedOperationError extends A2AError
{
    public const DEFAULT_MESSAGE = 'This operation is not supported';
}
