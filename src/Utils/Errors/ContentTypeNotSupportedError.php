<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when the content type is incompatible.
 */
class ContentTypeNotSupportedError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Incompatible content types';
}
