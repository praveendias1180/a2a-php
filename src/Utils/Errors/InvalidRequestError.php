<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when the request is invalid.
 */
class InvalidRequestError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Invalid Request';
}
