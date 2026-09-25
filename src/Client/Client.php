<?php

declare(strict_types=1);

namespace A2A\Client;

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
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;

/**
 * An A2A client, whatever the transport underneath.
 *
 * Mirrors a2a-python: Client in src/a2a/client/client.py. Python's
 * `async for` over send_message() / subscribe() is a `foreach` over a
 * Generator here; the call is lazy, so nothing is sent until you start
 * iterating.
 */
abstract class Client
{
    /** @var list<ClientCallInterceptor> */
    protected array $interceptors;

    /**
     * @param list<ClientCallInterceptor> $interceptors
     */
    public function __construct(array $interceptors = [])
    {
        $this->interceptors = $interceptors;
    }

    /**
     * Sends a message, streaming or not depending on the client config and the
     * agent's capabilities, and yields the events either way.
     *
     * @return \Generator<int, StreamResponse>
     */
    abstract public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator;

    abstract public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task;

    abstract public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse;

    abstract public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task;

    abstract public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig;

    abstract public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig;

    abstract public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse;

    abstract public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void;

    /**
     * Re-attaches to a task's event stream.
     *
     * @return \Generator<int, StreamResponse>
     */
    abstract public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator;

    /**
     * @param (callable(AgentCard): void)|null $signatureVerifier
     */
    abstract public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null, ?callable $signatureVerifier = null): AgentCard;

    public function addInterceptor(ClientCallInterceptor $interceptor): void
    {
        $this->interceptors[] = $interceptor;
    }

    abstract public function close(): void;
}
