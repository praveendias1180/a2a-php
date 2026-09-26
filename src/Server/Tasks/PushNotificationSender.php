<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;

/**
 * Sends a task's updates to the webhooks registered for it.
 *
 * The SDK calls sendNotification() for every Task, TaskStatusUpdateEvent
 * and TaskArtifactUpdateEvent the agent produces (after the event is saved),
 * in order. Implementations must not throw for delivery failures: log them.
 *
 * Mirrors a2a-python: PushNotificationSender in
 * src/a2a/server/tasks/push_notification_sender.py
 */
interface PushNotificationSender
{
    public function sendNotification(string $taskId, Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void;
}
