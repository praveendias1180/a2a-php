<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when extension support is required but not present.
 */
class ExtensionSupportRequiredError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Extension support required';
}
