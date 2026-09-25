<?php

declare(strict_types=1);

namespace A2A\Utils;

use A2A\Types\GetTaskRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\Task;
use A2A\Utils\Errors\InvalidParamsError;

/**
 * Utility functions for A2A Task objects.
 *
 * Mirrors a2a-python: src/a2a/utils/task.py. Python types the config as a
 * Protocol with `history_length`; here it is the union of the three request
 * types that carry that field.
 */
final class TaskUtils
{
    private function __construct() {}

    /**
     * @throws InvalidParamsError
     */
    public static function validateHistoryLength(GetTaskRequest|ListTasksRequest|SendMessageConfiguration|null $config): void
    {
        if ($config !== null && $config->getHistoryLength() < 0) {
            throw new InvalidParamsError('history length must be non-negative');
        }
    }

    /**
     * Returns the task with its history cut to `history_length` (a copy when
     * anything changes; the original is never modified).
     *
     * Unset means no limit, 0 means no history, N > 0 keeps the last N.
     *
     * @see https://a2a-protocol.org/latest/specification/#324-history-length-semantics
     */
    public static function applyHistoryLength(Task $task, GetTaskRequest|ListTasksRequest|SendMessageConfiguration|null $config): Task
    {
        if ($config === null || !$config->hasHistoryLength()) {
            return $task;
        }

        $historyLength = $config->getHistoryLength();
        $history = iterator_to_array($task->getHistory(), false);

        if ($historyLength === 0) {
            if ($history === []) {
                return $task;
            }
            $copy = self::copy($task);
            $copy->setHistory([]);

            return $copy;
        }

        if ($historyLength > 0 && $history !== []) {
            if (count($history) <= $historyLength) {
                return $task;
            }
            $copy = self::copy($task);
            $copy->setHistory(array_slice($history, -$historyLength));

            return $copy;
        }

        return $task;
    }

    /**
     * Checks that page_size is within [1, 100].
     *
     * @throws InvalidParamsError
     *
     * @see https://a2a-protocol.org/latest/specification/#314-list-tasks
     */
    public static function validatePageSize(int $pageSize): void
    {
        if ($pageSize < 1) {
            throw new InvalidParamsError('minimum page size is 1');
        }
        if ($pageSize > Constants::MAX_LIST_TASKS_PAGE_SIZE) {
            throw new InvalidParamsError('maximum page size is ' . Constants::MAX_LIST_TASKS_PAGE_SIZE);
        }
    }

    public static function encodePageToken(string $taskId): string
    {
        return base64_encode($taskId);
    }

    /**
     * @throws InvalidParamsError
     */
    public static function decodePageToken(string $pageToken): string
    {
        $missingPadding = strlen($pageToken) % 4;
        if ($missingPadding !== 0) {
            $pageToken .= str_repeat('=', 4 - $missingPadding);
        }
        $decoded = base64_decode($pageToken, true);
        if ($decoded === false || preg_match('//u', $decoded) !== 1) {
            throw new InvalidParamsError('Token is not a valid base64-encoded cursor.');
        }

        return $decoded;
    }

    private static function copy(Task $task): Task
    {
        $copy = new Task();
        $copy->mergeFrom($task);

        return $copy;
    }
}
