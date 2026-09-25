<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when a task cannot be canceled.
 */
class TaskNotCancelableError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Task cannot be canceled';
}
