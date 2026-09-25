<?php

declare(strict_types=1);

namespace A2A\Client;

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
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\SendMessageResponse;
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;

/**
 * The transport-independent client: applies the client config, runs the
 * interceptors, and hands the call to the transport.
 *
 * Mirrors a2a-python: BaseClient in src/a2a/client/base_client.py
 */
class BaseClient extends Client
{
    /**
     * @param list<ClientCallInterceptor> $interceptors
     */
    public function __construct(
        protected AgentCard $card,
        protected readonly ClientConfig $config,
        protected readonly ClientTransport $transport,
        array $interceptors = [],
    ) {
        parent::__construct($interceptors);
    }

    /**
     * The agent card this client talks to (replaced by getExtendedAgentCard()).
     */
    public function agentCard(): AgentCard
    {
        return $this->card;
    }

    public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator
    {
        $this->applyClientConfig($request);

        if (!$this->config->streaming || !($this->card->getCapabilities()?->getStreaming() ?? false)) {
            $response = $this->executeWithInterceptors(
                $request,
                'send_message',
                $context,
                fn(mixed $req, ?ClientCallContext $ctx): SendMessageResponse => $this->transport->sendMessage(self::expect($req, SendMessageRequest::class), $ctx),
            );
            $response = self::expect($response, SendMessageResponse::class);

            // Non-streaming responses become a StreamResponse too, so callers
            // always iterate the same way.
            $streamResponse = new StreamResponse();
            if ($response->hasTask()) {
                $streamResponse->setTask($response->getTask());
            } elseif ($response->hasMessage()) {
                $streamResponse->setMessage($response->getMessage());
            } else {
                throw new \ValueError('Response has neither task nor message');
            }

            yield $streamResponse;

            return;
        }

        yield from $this->executeStreamWithInterceptors(
            $request,
            'send_message_streaming',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): \Generator => $this->transport->sendMessageStreaming(self::expect($req, SendMessageRequest::class), $ctx),
        );
    }

    public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        return self::expect($this->executeWithInterceptors(
            $request,
            'get_task',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): Task => $this->transport->getTask(self::expect($req, GetTaskRequest::class), $ctx),
        ), Task::class);
    }

    public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse
    {
        return self::expect($this->executeWithInterceptors(
            $request,
            'list_tasks',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): ListTasksResponse => $this->transport->listTasks(self::expect($req, ListTasksRequest::class), $ctx),
        ), ListTasksResponse::class);
    }

    public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        return self::expect($this->executeWithInterceptors(
            $request,
            'cancel_task',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): Task => $this->transport->cancelTask(self::expect($req, CancelTaskRequest::class), $ctx),
        ), Task::class);
    }

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        return self::expect($this->executeWithInterceptors(
            $request,
            'create_task_push_notification_config',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): TaskPushNotificationConfig => $this->transport->createTaskPushNotificationConfig(self::expect($req, TaskPushNotificationConfig::class), $ctx),
        ), TaskPushNotificationConfig::class);
    }

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        return self::expect($this->executeWithInterceptors(
            $request,
            'get_task_push_notification_config',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): TaskPushNotificationConfig => $this->transport->getTaskPushNotificationConfig(self::expect($req, GetTaskPushNotificationConfigRequest::class), $ctx),
        ), TaskPushNotificationConfig::class);
    }

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse
    {
        return self::expect($this->executeWithInterceptors(
            $request,
            'list_task_push_notification_configs',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): ListTaskPushNotificationConfigsResponse => $this->transport->listTaskPushNotificationConfigs(self::expect($req, ListTaskPushNotificationConfigsRequest::class), $ctx),
        ), ListTaskPushNotificationConfigsResponse::class);
    }

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void
    {
        $this->executeWithInterceptors(
            $request,
            'delete_task_push_notification_config',
            $context,
            function (mixed $req, ?ClientCallContext $ctx): mixed {
                $this->transport->deleteTaskPushNotificationConfig(self::expect($req, DeleteTaskPushNotificationConfigRequest::class), $ctx);

                return null;
            },
        );
    }

    public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator
    {
        if (!$this->config->streaming || !($this->card->getCapabilities()?->getStreaming() ?? false)) {
            // Python raises NotImplementedError.
            throw new \BadMethodCallException('client and/or server do not support resubscription.');
        }

        yield from $this->executeStreamWithInterceptors(
            $request,
            'subscribe',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): \Generator => $this->transport->subscribe(self::expect($req, SubscribeToTaskRequest::class), $ctx),
        );
    }

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null, ?callable $signatureVerifier = null): AgentCard
    {
        $card = self::expect($this->executeWithInterceptors(
            $request,
            'get_extended_agent_card',
            $context,
            fn(mixed $req, ?ClientCallContext $ctx): AgentCard => $this->transport->getExtendedAgentCard(self::expect($req, GetExtendedAgentCardRequest::class), $ctx),
        ), AgentCard::class);
        if ($signatureVerifier !== null) {
            $signatureVerifier($card);
        }

        $this->card = $card;

        return $card;
    }

    public function close(): void
    {
        $this->transport->close();
    }

    protected function applyClientConfig(SendMessageRequest $request): void
    {
        $configuration = $request->getConfiguration();
        if ($configuration === null) {
            $configuration = new SendMessageConfiguration();
            $request->setConfiguration($configuration);
        }

        $configuration->setReturnImmediately($configuration->getReturnImmediately() || $this->config->polling);
        if (!$configuration->hasTaskPushNotificationConfig() && $this->config->pushNotificationConfig !== null) {
            $configuration->setTaskPushNotificationConfig(clone $this->config->pushNotificationConfig);
        }
        if (count($configuration->getAcceptedOutputModes()) === 0 && $this->config->acceptedOutputModes !== []) {
            $configuration->setAcceptedOutputModes($this->config->acceptedOutputModes);
        }
    }

    /**
     * @param callable(mixed, ?ClientCallContext): mixed $transportCall
     */
    protected function executeWithInterceptors(mixed $input, string $method, ?ClientCallContext $context, callable $transportCall): mixed
    {
        $beforeArgs = new BeforeArgs($input, $method, $this->card, $context);
        $beforeResult = $this->interceptBefore($beforeArgs);

        if ($beforeResult !== null) {
            $earlyAfterArgs = new AfterArgs($beforeResult['early_return'], $method, $this->card, $beforeArgs->context);
            $this->interceptAfter($earlyAfterArgs, $beforeResult['executed']);

            return $earlyAfterArgs->result;
        }

        $result = $transportCall($beforeArgs->input, $beforeArgs->context);

        $afterArgs = new AfterArgs($result, $method, $this->card, $beforeArgs->context);
        $this->interceptAfter($afterArgs);

        return $afterArgs->result;
    }

    /**
     * @param callable(mixed, ?ClientCallContext): \Generator<int, StreamResponse> $transportCall
     *
     * @return \Generator<int, StreamResponse>
     */
    protected function executeStreamWithInterceptors(mixed $input, string $method, ?ClientCallContext $context, callable $transportCall): \Generator
    {
        $beforeArgs = new BeforeArgs($input, $method, $this->card, $context);
        $beforeResult = $this->interceptBefore($beforeArgs);

        if ($beforeResult !== null) {
            $afterArgs = new AfterArgs($beforeResult['early_return'], $method, $this->card, $beforeArgs->context);
            $this->interceptAfter($afterArgs, $beforeResult['executed']);

            yield self::expect($afterArgs->result, StreamResponse::class);

            return;
        }

        $stream = $transportCall($beforeArgs->input, $beforeArgs->context);

        foreach ($stream as $event) {
            $afterArgs = new AfterArgs($event, $beforeArgs->method, $this->card, $beforeArgs->context);
            $this->interceptAfter($afterArgs);
            $result = self::expect($afterArgs->result, StreamResponse::class);

            yield $result;

            if ($result->hasMessage()) {
                return;
            }
        }
    }

    /**
     * @return array{early_return: mixed, executed: list<ClientCallInterceptor>}|null
     */
    private function interceptBefore(BeforeArgs $args): ?array
    {
        $executed = [];
        foreach ($this->interceptors as $interceptor) {
            $interceptor->before($args);
            $executed[] = $interceptor;
            if ($args->earlyReturn !== null) {
                return ['early_return' => $args->earlyReturn, 'executed' => $executed];
            }
        }

        return null;
    }

    /**
     * @param list<ClientCallInterceptor>|null $interceptors
     */
    private function interceptAfter(AfterArgs $args, ?array $interceptors = null): void
    {
        foreach (array_reverse($interceptors ?? $this->interceptors) as $interceptor) {
            $interceptor->after($args);
            if ($args->earlyReturn) {
                return;
            }
        }
    }

    /**
     * Narrows a value that went through the interceptors back to its type.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function expect(mixed $value, string $class): object
    {
        if (!$value instanceof $class) {
            throw new \UnexpectedValueException(sprintf('Expected %s, got %s', $class, get_debug_type($value)));
        }

        return $value;
    }
}
