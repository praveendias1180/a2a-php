<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised for internal server errors.
 */
class InternalError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Internal error';
}
