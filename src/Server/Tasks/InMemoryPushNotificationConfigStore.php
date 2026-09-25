<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\OwnerResolver;
use A2A\Server\ServerCallContext;
use A2A\Types\TaskPushNotificationConfig;

/**
 * Keeps push configs in a PHP array (one process only; see
 * InMemoryTaskStore for what that means under PHP-FPM).
 *
 * Mirrors a2a-python: InMemoryPushNotificationConfigStore in
 * src/a2a/server/tasks/inmemory_push_notification_config_store.py
 */
final class InMemoryPushNotificationConfigStore implements PushNotificationConfigStore
{
    /** @var array<string, array<string, array<string, TaskPushNotificationConfig>>> owner => task => config id => config */
    private array $configs = [];

    /** @var \Closure(ServerCallContext): string */
    private readonly \Closure $ownerResolver;

    /**
     * @param (\Closure(ServerCallContext): string)|null $ownerResolver
     */
    public function __construct(?\Closure $ownerResolver = null)
    {
        $this->ownerResolver = $ownerResolver ?? OwnerResolver::default();
    }

    public function setInfo(string $taskId, TaskPushNotificationConfig $config, ServerCallContext $context): void
    {
        $owner = ($this->ownerResolver)($context);
        $copy = new TaskPushNotificationConfig();
        $copy->mergeFrom($config);
        if ($copy->getId() === '') {
            // Python defaults the id to the task id.
            $copy->setId($taskId);
        }
        $copy->setTaskId($taskId);
        $this->configs[$owner][$taskId][$copy->getId()] = $copy;
    }

    public function getInfo(string $taskId, ServerCallContext $context): array
    {
        $owner = ($this->ownerResolver)($context);

        return array_values($this->configs[$owner][$taskId] ?? []);
    }

    public function deleteInfo(string $taskId, ServerCallContext $context, ?string $configId = null): void
    {
        $owner = ($this->ownerResolver)($context);
        if ($configId === null) {
            unset($this->configs[$owner][$taskId]);

            return;
        }
        unset($this->configs[$owner][$taskId][$configId]);
    }
}
