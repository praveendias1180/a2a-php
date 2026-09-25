<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Support;

use A2A\Client\ClientCallContext;
use A2A\Client\Transports\ClientTransport;
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
 * A ClientTransport that records every call and returns canned results, the
 * PHP counterpart of `AsyncMock(spec=ClientTransport)` in the Python tests.
 */
final class RecordingTransport implements ClientTransport
{
    /** @var list<array{string, object, ?ClientCallContext}> */
    public array $calls = [];

    public SendMessageResponse $sendMessageResponse;

    /** @var list<StreamResponse> */
    public array $streamEvents = [];

    public Task $task;

    public AgentCard $extendedCard;

    public bool $closed = false;

    public function __construct()
    {
        $this->sendMessageResponse = new SendMessageResponse(['task' => new Task(['id' => 'default'])]);
        $this->task = new Task(['id' => 'task']);
        $this->extendedCard = new AgentCard(['name' => 'extended']);
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_map(static fn(array $call): string => $call[0], $this->calls);
    }

    public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): SendMessageResponse
    {
        $this->calls[] = ['sendMessage', $request, $context];

        return $this->sendMessageResponse;
    }

    public function sendMessageStreaming(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator
    {
        $this->calls[] = ['sendMessageStreaming', $request, $context];

        yield from $this->streamEvents;
    }

    public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        $this->calls[] = ['getTask', $request, $context];

        return $this->task;
    }

    public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse
    {
        $this->calls[] = ['listTasks', $request, $context];

        return new ListTasksResponse(['tasks' => [$this->task]]);
    }

    public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        $this->calls[] = ['cancelTask', $request, $context];

        return $this->task;
    }

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $this->calls[] = ['createTaskPushNotificationConfig', $request, $context];

        return $request;
    }

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $this->calls[] = ['getTaskPushNotificationConfig', $request, $context];

        return new TaskPushNotificationConfig(['id' => $request->getId()]);
    }

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse
    {
        $this->calls[] = ['listTaskPushNotificationConfigs', $request, $context];

        return new ListTaskPushNotificationConfigsResponse();
    }

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void
    {
        $this->calls[] = ['deleteTaskPushNotificationConfig', $request, $context];
    }

    public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator
    {
        $this->calls[] = ['subscribe', $request, $context];

        yield from $this->streamEvents;
    }

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null): AgentCard
    {
        $this->calls[] = ['getExtendedAgentCard', $request, $context];

        return $this->extendedCard;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
