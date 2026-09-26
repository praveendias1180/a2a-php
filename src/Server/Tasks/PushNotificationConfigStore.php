<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\ServerCallContext;
use A2A\Types\TaskPushNotificationConfig;

/**
 * Stores the webhook configs clients register for a task.
 *
 * Mirrors a2a-python: PushNotificationConfigStore in
 * src/a2a/server/tasks/push_notification_config_store.py.
 *
 * getInfo() is the caller-facing read path and MUST return only the
 * caller's configs. getInfoForDispatch() is the internal read path the
 * PushNotificationSender uses: every config registered for the task, by any
 * owner (authorization already happened when each config was registered).
 * Python gives getInfoForDispatch() a default that calls getInfo() with an
 * empty context and warns, because that silently drops notifications for
 * every other owner; a PHP interface has no defaults, so every store
 * implements it.
 */
interface PushNotificationConfigStore
{
    public function setInfo(string $taskId, TaskPushNotificationConfig $config, ServerCallContext $context): void;

    /**
     * @return list<TaskPushNotificationConfig>
     */
    public function getInfo(string $taskId, ServerCallContext $context): array;

    /**
     * Every config registered for the task, whoever registered it.
     *
     * @return list<TaskPushNotificationConfig>
     */
    public function getInfoForDispatch(string $taskId): array;

    /**
     * Deletes one config, or all of the task's configs when $configId is null.
     */
    public function deleteInfo(string $taskId, ServerCallContext $context, ?string $configId = null): void;
}
