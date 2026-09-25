<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when the authenticated extended card is not configured.
 */
class ExtendedAgentCardNotConfiguredError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Authenticated Extended Card is not configured';
}
