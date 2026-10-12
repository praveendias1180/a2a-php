<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Types\TaskPushNotificationConfig;

/**
 * Helpers shared by the push-notification config stores.
 *
 * Mirrors a2a-python: normalize_push_notification_config() in
 * src/a2a/server/tasks/push_notification_config_store.py (a2a-sdk 1.2.2).
 */
final class PushNotificationConfigs
{
    private function __construct() {}

    /**
     * A copy of $config with task_id set to $taskId and an empty id defaulted
     * to the task id: exactly what a store saves. Stores call it before saving,
     * and DefaultRequestHandler returns it from CreateTaskPushNotificationConfig,
     * so the client gets the id the config was stored under.
     */
    public static function normalize(string $taskId, TaskPushNotificationConfig $config): TaskPushNotificationConfig
    {
        $copy = new TaskPushNotificationConfig();
        $copy->mergeFrom($config);
        if ($copy->getId() === '') {
            $copy->setId($taskId);
        }
        $copy->setTaskId($taskId);

        return $copy;
    }
}
