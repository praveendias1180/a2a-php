<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\ServerCallContext;
use A2A\Types\TaskPushNotificationConfig;

/**
 * Stores the webhook configs clients register for a task.
 *
 * Mirrors a2a-python: PushNotificationConfigStore in
 * src/a2a/server/tasks/push_notification_config_store.py. Sending the
 * notifications arrives in phase 5.
 */
interface PushNotificationConfigStore
{
    public function setInfo(string $taskId, TaskPushNotificationConfig $config, ServerCallContext $context): void;

    /**
     * @return list<TaskPushNotificationConfig>
     */
    public function getInfo(string $taskId, ServerCallContext $context): array;

    /**
     * Deletes one config, or all of the task's configs when $configId is null.
     */
    public function deleteInfo(string $taskId, ServerCallContext $context, ?string $configId = null): void;
}
