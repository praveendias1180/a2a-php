<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when a task is not found.
 */
class TaskNotFoundError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Task not found';
}
