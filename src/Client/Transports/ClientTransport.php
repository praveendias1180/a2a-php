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
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;

/**
 * One wire protocol (JSON-RPC, HTTP+JSON, ...) for talking to an agent.
 *
 * Mirrors a2a-python: ClientTransport in src/a2a/client/transports/base.py.
 * Python's async generators are PHP Generators here.
 */
interface ClientTransport
{
    public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): SendMessageResponse;

    /**
     * @return \Generator<int, StreamResponse>
     */
    public function sendMessageStreaming(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator;

    public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task;

    public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse;

    public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task;

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig;

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig;

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse;

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void;

    /**
     * @return \Generator<int, StreamResponse>
     */
    public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator;

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null): AgentCard;

    public function close(): void;
}
