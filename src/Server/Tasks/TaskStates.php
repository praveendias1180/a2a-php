<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Types\TaskState;

/**
 * Task state groups used across the server.
 *
 * Mirrors a2a-python: TERMINAL_TASK_STATES / INTERRUPTED_TASK_STATES in
 * src/a2a/server/agent_execution/active_task.py
 */
final class TaskStates
{
    public const TERMINAL = [
        TaskState::TASK_STATE_COMPLETED,
        TaskState::TASK_STATE_CANCELED,
        TaskState::TASK_STATE_FAILED,
        TaskState::TASK_STATE_REJECTED,
    ];

    public const INTERRUPTED = [
        TaskState::TASK_STATE_AUTH_REQUIRED,
        TaskState::TASK_STATE_INPUT_REQUIRED,
    ];

    private function __construct() {}

    public static function isTerminal(int $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /**
     * The state's enum name, e.g. TASK_STATE_COMPLETED.
     */
    public static function name(int $state): string
    {
        try {
            $name = TaskState::name($state);
        } catch (\UnexpectedValueException) {
            // A state number this SDK version doesn't know (a newer peer).
            return (string) $state;
        }

        return is_string($name) ? $name : (string) $state;
    }

    public static function isInterrupted(int $state): bool
    {
        return in_array($state, self::INTERRUPTED, true);
    }
}
