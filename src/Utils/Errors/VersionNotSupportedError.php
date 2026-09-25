<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when the requested version is not supported.
 */
class VersionNotSupportedError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Version not supported';
}
