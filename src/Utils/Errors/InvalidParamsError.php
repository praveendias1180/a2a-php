<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when parameters are invalid.
 */
class InvalidParamsError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Invalid params';
}
