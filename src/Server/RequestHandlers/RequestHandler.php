<?php

declare(strict_types=1);

namespace A2A\Server\RequestHandlers;

use A2A\Server\ServerCallContext;
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
use A2A\Types\Message;
use A2A\Types\SendMessageRequest;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskStatusUpdateEvent;

/**
 * The A2A operations, independent of the wire protocol. The JSON-RPC and
 * REST dispatchers call these.
 *
 * Mirrors a2a-python: RequestHandler in
 * src/a2a/server/request_handlers/request_handler.py. Async generators are
 * PHP Generators. The streaming methods may also yield `null` as a
 * keep-alive tick while nothing is happening, so the dispatcher can keep the
 * connection open; dispatchers turn it into an SSE comment.
 */
interface RequestHandler
{
    public function onGetTask(GetTaskRequest $params, ServerCallContext $context): ?Task;

    public function onListTasks(ListTasksRequest $params, ServerCallContext $context): ListTasksResponse;

    public function onCancelTask(CancelTaskRequest $params, ServerCallContext $context): ?Task;

    public function onMessageSend(SendMessageRequest $params, ServerCallContext $context): Message|Task;

    /**
     * @return \Generator<int, Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent|null, mixed, void>
     */
    public function onMessageSendStream(SendMessageRequest $params, ServerCallContext $context): \Generator;

    public function onCreateTaskPushNotificationConfig(TaskPushNotificationConfig $params, ServerCallContext $context): TaskPushNotificationConfig;

    public function onGetTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $params, ServerCallContext $context): TaskPushNotificationConfig;

    /**
     * @return \Generator<int, Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent|null, mixed, void>
     */
    public function onSubscribeToTask(SubscribeToTaskRequest $params, ServerCallContext $context): \Generator;

    public function onListTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $params, ServerCallContext $context): ListTaskPushNotificationConfigsResponse;

    public function onDeleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $params, ServerCallContext $context): void;

    public function onGetExtendedAgentCard(GetExtendedAgentCardRequest $params, ServerCallContext $context): AgentCard;

    /**
     * Finishes work that continues after a response (see TaskRunner). The
     * SDK's ResponseEmitter calls it after sending each response.
     * PHP-specific; Python's closest equivalent is `aclose()`.
     */
    public function runBackgroundWork(): void;
}
