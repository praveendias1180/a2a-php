<?php

declare(strict_types=1);

namespace A2A\Client\Transports;

use A2A\Client\ClientCallContext;
use A2A\Types\AgentCard;
use A2A\Types\CancelTaskRequest;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\ListTaskPushNotificationConfigsResponse;
use A2A\Types\ListTasksRequest;
use A2A\Types\ListTasksResponse;
use A2A\Types\SendMessageRequest;
use A2A\Types\SendMessageResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;

/**
 * Fills in the interface's tenant on every request that doesn't set one.
 *
 * Mirrors a2a-python: TenantTransportDecorator in
 * src/a2a/client/transports/tenant_decorator.py
 */
final class TenantTransportDecorator implements ClientTransport
{
    public function __construct(
        private readonly ClientTransport $base,
        private readonly string $tenant,
    ) {}

    public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): SendMessageResponse
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        return $this->base->sendMessage($request, $context);
    }

    public function sendMessageStreaming(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        yield from $this->base->sendMessageStreaming($request, $context);
    }

    public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        return $this->base->getTask($request, $context);
    }

    public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        return $this->base->listTasks($request, $context);
    }

    public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        return $this->base->cancelTask($request, $context);
    }

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        return $this->base->createTaskPushNotificationConfig($request, $context);
    }

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        return $this->base->getTaskPushNotificationConfig($request, $context);
    }

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        return $this->base->listTaskPushNotificationConfigs($request, $context);
    }

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));
        $this->base->deleteTaskPushNotificationConfig($request, $context);
    }

    public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        yield from $this->base->subscribe($request, $context);
    }

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null): AgentCard
    {
        $request->setTenant($this->resolveTenant($request->getTenant()));

        return $this->base->getExtendedAgentCard($request, $context);
    }

    public function close(): void
    {
        $this->base->close();
    }

    private function resolveTenant(string $tenant): string
    {
        return $tenant !== '' ? $tenant : $this->tenant;
    }
}
