<?php

declare(strict_types=1);

namespace A2A\Server\RequestHandlers;

use A2A\Server\AgentExecution\ActiveTask;
use A2A\Server\AgentExecution\ActiveTaskRegistry;
use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\InlineTaskRunner;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\AgentExecution\RequestContextBuilder;
use A2A\Server\AgentExecution\SimpleRequestContextBuilder;
use A2A\Server\AgentExecution\TaskRunner;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\Events\QueueManager;
use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\PushNotificationConfigStore;
use A2A\Server\Tasks\TaskStates;
use A2A\Server\Tasks\TaskStore;
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
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskState;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Errors\ExtendedAgentCardNotConfiguredError;
use A2A\Utils\Errors\InternalError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\PushNotificationNotSupportedError;
use A2A\Utils\Errors\TaskNotCancelableError;
use A2A\Utils\Errors\TaskNotFoundError;
use A2A\Utils\Errors\UnsupportedOperationError;
use A2A\Utils\ProtoUtils;
use A2A\Utils\TaskUtils;
use Google\Protobuf\Internal\Message as ProtobufMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The standard implementation of every A2A operation.
 *
 * Mirrors a2a-python: DefaultRequestHandlerV2 in
 * src/a2a/server/request_handlers/default_request_handler_v2.py. Where PHP
 * differs:
 * - Execution goes through a TaskRunner (inline by default) instead of a
 *   background asyncio task.
 * - The QueueManager is used (Python v2 ignores it): it carries events and
 *   cancel requests between PHP processes. For PHP-FPM or `php -S` pass a
 *   PdoQueueManager and a PdoTaskStore on the same database.
 * - SubscribeToTask polls the QueueManager and yields `null` keep-alive
 *   ticks while idle.
 * - Sending to a terminal task is UnsupportedOperationError (the spec's
 *   CORE-SEND-002; Python raises InvalidParamsError), subscribing to one is
 *   UnsupportedOperationError (STREAM-SUB-003), and cancelling one is
 *   TaskNotCancelableError on every transport.
 */
final class DefaultRequestHandler implements RequestHandler
{
    private readonly QueueManager $queueManager;

    private readonly RequestContextBuilder $requestContextBuilder;

    private readonly ActiveTaskRegistry $activeTaskRegistry;

    private readonly TaskRunner $taskRunner;

    /**
     * @param (\Closure(AgentCard, ServerCallContext): AgentCard)|null $extendedCardModifier
     * @param (\Closure(string): bool)|null                           $pushUrlValidator
     * @param float $keepAliveSeconds     how often idle streams send a keep-alive tick
     * @param float $cancelTimeoutSeconds how long a cancel waits for a task running in another process to stop
     * @param float $subscribePollSeconds how long each SubscribeToTask poll of the QueueManager waits
     * @param float|null $maxSubscribeIdleSeconds end a SubscribeToTask stream after this long without events
     *                                            (null: wait until the task finishes, like Python). Each open
     *                                            stream holds a PHP worker, so a limit protects the pool from
     *                                            clients that never hang up.
     */
    public function __construct(
        private readonly AgentExecutor $agentExecutor,
        private readonly TaskStore $taskStore,
        private readonly AgentCard $agentCard,
        ?QueueManager $queueManager = null,
        private readonly ?PushNotificationConfigStore $pushConfigStore = null,
        ?RequestContextBuilder $requestContextBuilder = null,
        private readonly ?AgentCard $extendedAgentCard = null,
        private readonly ?\Closure $extendedCardModifier = null,
        private readonly ?\Closure $pushUrlValidator = null,
        ?TaskRunner $taskRunner = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $keepAliveSeconds = 15.0,
        private readonly float $cancelTimeoutSeconds = 10.0,
        private readonly float $subscribePollSeconds = 0.25,
        private readonly ?float $maxSubscribeIdleSeconds = null,
    ) {
        $this->queueManager = $queueManager ?? new InMemoryQueueManager();
        $this->requestContextBuilder = $requestContextBuilder
            ?? new SimpleRequestContextBuilder(shouldPopulateReferredTasks: false, taskStore: $this->taskStore);
        $this->activeTaskRegistry = new ActiveTaskRegistry($this->agentExecutor, $this->taskStore, $this->queueManager, $this->logger);
        $this->taskRunner = $taskRunner ?? new InlineTaskRunner($this->queueManager, $this->logger);
    }

    public function onGetTask(GetTaskRequest $params, ServerCallContext $context): Task
    {
        self::validateParams($params);
        TaskUtils::validateHistoryLength($params);

        $task = $this->taskStore->get($params->getId(), $context);
        if ($task === null) {
            throw new TaskNotFoundError();
        }

        return TaskUtils::applyHistoryLength($task, $params);
    }

    public function onListTasks(ListTasksRequest $params, ServerCallContext $context): ListTasksResponse
    {
        self::validateParams($params);
        TaskUtils::validateHistoryLength($params);
        if ($params->hasPageSize()) {
            TaskUtils::validatePageSize($params->getPageSize());
        }

        $page = $this->taskStore->list($params, $context);
        $tasks = [];
        foreach ($page->getTasks() as $task) {
            if (!$params->getIncludeArtifacts()) {
                $task->setArtifacts([]);
            }
            $tasks[] = TaskUtils::applyHistoryLength($task, $params);
        }
        $page->setTasks($tasks);

        return $page;
    }

    public function onCancelTask(CancelTaskRequest $params, ServerCallContext $context): Task
    {
        self::validateParams($params);
        $taskId = $params->getId();

        $task = $this->taskStore->get($taskId, $context);
        if ($task === null) {
            throw new TaskNotFoundError();
        }
        if (self::isTerminal($task)) {
            throw new TaskNotCancelableError(sprintf('Task %s is in terminal state: %s', $taskId, TaskStates::name($task->getStatus()?->getState() ?? 0)));
        }

        $this->queueManager->requestCancel($taskId);

        if ($this->queueManager->hasActiveRunLease($taskId)) {
            // The executor is running in another process (or earlier in this
            // one). It sees the cancel flag at its next event and writes the
            // terminal state itself; wait for that.
            $deadline = microtime(true) + $this->cancelTimeoutSeconds;
            while (microtime(true) < $deadline) {
                $current = $this->taskStore->get($taskId, $context);
                if ($current !== null && self::isTerminal($current)) {
                    return $current;
                }
                usleep(100_000);
            }
            $this->logger->warning('Task {task} did not stop within {seconds}s of a cancel request; cancelling it from this request.', [
                'task' => $taskId,
                'seconds' => $this->cancelTimeoutSeconds,
            ]);
        }

        return $this->activeTaskRegistry->create($taskId, $context, $task->getContextId())->cancel($context);
    }

    public function onMessageSend(SendMessageRequest $params, ServerCallContext $context): Message|Task
    {
        self::validateParams($params);
        [$activeTask, $request] = $this->setupActiveTask($params, $context);
        $taskId = (string) $request->taskId();
        $configuration = $params->getConfiguration();
        $returnImmediately = $configuration !== null && $configuration->getReturnImmediately();

        $events = $this->taskRunner->run($activeTask, $request);
        $result = null;
        $finishedEarly = false;

        foreach ($events as $published) {
            $event = $published->event;
            if ($event instanceof TaskStatusUpdateEvent && $published->task !== null) {
                // Python's replace_status_update_with_task=True.
                $event = $published->task;
            }

            if ($event instanceof Task) {
                $state = $event->getStatus()?->getState() ?? TaskState::TASK_STATE_UNSPECIFIED;
                if ($returnImmediately || TaskStates::isTerminal($state) || TaskStates::isInterrupted($state)) {
                    $this->validateTaskIdMatch($taskId, $event->getId());
                    $result = $event;
                    if ($returnImmediately || $state !== TaskState::TASK_STATE_FAILED) {
                        $finishedEarly = true;
                        break;
                    }
                }
            }

            if ($event instanceof Message) {
                // Keep going: the consumer rejects any event after a Message.
                $result = $event;
            }
        }

        if ($finishedEarly) {
            // The executor may still be running (returnImmediately, or it
            // keeps working after an interrupted state). Let it finish after
            // the response is sent, as Python's background task would.
            // Nothing more runs before the response goes out.
            $this->taskRunner->defer(static function () use ($events): void {
                do {
                    $events->next();
                } while ($events->valid());
            });
        }

        if ($result === null) {
            $result = $activeTask->getTask();
        }
        if ($result instanceof Task) {
            $result = TaskUtils::applyHistoryLength($result, $configuration);
        }

        return $result;
    }

    public function onMessageSendStream(SendMessageRequest $params, ServerCallContext $context): \Generator
    {
        self::validateParams($params);
        $this->requireStreaming();
        [$activeTask, $request] = $this->setupActiveTask($params, $context);
        $taskId = (string) $request->taskId();
        $configuration = $params->getConfiguration();

        foreach ($this->taskRunner->run($activeTask, $request) as $published) {
            $event = $published->event;
            if ($event instanceof Task) {
                $this->validateTaskIdMatch($taskId, $event->getId());
                yield TaskUtils::applyHistoryLength($event, $configuration);
            } else {
                yield $event;
            }
        }
    }

    public function onCreateTaskPushNotificationConfig(TaskPushNotificationConfig $params, ServerCallContext $context): TaskPushNotificationConfig
    {
        self::validateParams($params);
        $store = $this->requirePushConfigStore();
        $this->requireTask($params->getTaskId(), $context);
        $this->rejectUnsafePushUrl($params->getUrl());
        $store->setInfo($params->getTaskId(), $params, $context);

        return $params;
    }

    public function onGetTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $params, ServerCallContext $context): TaskPushNotificationConfig
    {
        self::validateParams($params);
        $store = $this->requirePushConfigStore();
        $this->requireTask($params->getTaskId(), $context);

        foreach ($store->getInfo($params->getTaskId(), $context) as $config) {
            if ($config->getId() === $params->getId()) {
                return $config;
            }
        }

        throw new TaskNotFoundError();
    }

    public function onSubscribeToTask(SubscribeToTaskRequest $params, ServerCallContext $context): \Generator
    {
        self::validateParams($params);
        $this->requireStreaming();
        $taskId = $params->getId();

        // Read the log position first, so nothing published while we load
        // the task is missed (at worst an event is seen twice).
        $cursor = $this->queueManager->lastSequence($taskId);
        $task = $this->taskStore->get($taskId, $context);
        if ($task === null) {
            throw new TaskNotFoundError();
        }
        if (self::isTerminal($task)) {
            throw new UnsupportedOperationError(sprintf('Task %s is in a terminal state and cannot be subscribed to.', $taskId));
        }

        yield $task;

        $lastActivity = microtime(true);
        $lastEvent = $lastActivity;
        while (true) {
            $events = $this->queueManager->read($taskId, $cursor, $this->subscribePollSeconds);
            if ($events === []) {
                $now = microtime(true);
                if ($this->maxSubscribeIdleSeconds !== null && $now - $lastEvent >= $this->maxSubscribeIdleSeconds) {
                    return;
                }
                if ($now - $lastActivity >= $this->keepAliveSeconds) {
                    $lastActivity = $now;
                    yield null;
                }
                continue;
            }
            $lastEvent = microtime(true);
            foreach ($events as $sequence => $published) {
                $cursor = $sequence;
                yield $published->event;
                if ($published->isTerminal()) {
                    return;
                }
            }
            $lastActivity = microtime(true);
        }
    }

    public function onListTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $params, ServerCallContext $context): ListTaskPushNotificationConfigsResponse
    {
        self::validateParams($params);
        $store = $this->requirePushConfigStore();
        $this->requireTask($params->getTaskId(), $context);

        return new ListTaskPushNotificationConfigsResponse(['configs' => $store->getInfo($params->getTaskId(), $context)]);
    }

    public function onDeleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $params, ServerCallContext $context): void
    {
        self::validateParams($params);
        $store = $this->requirePushConfigStore();
        $this->requireTask($params->getTaskId(), $context);
        $store->deleteInfo($params->getTaskId(), $context, $params->getId());
    }

    public function onGetExtendedAgentCard(GetExtendedAgentCardRequest $params, ServerCallContext $context): AgentCard
    {
        self::validateParams($params);
        if ($this->agentCard->getCapabilities()?->getExtendedAgentCard() !== true) {
            throw new UnsupportedOperationError('The agent does not support authenticated extended cards');
        }
        if ($this->extendedAgentCard === null) {
            throw new ExtendedAgentCardNotConfiguredError();
        }

        $card = $this->extendedAgentCard;
        if ($this->extendedCardModifier !== null) {
            $card = ($this->extendedCardModifier)($card, $context);
        }

        return $card;
    }

    public function runBackgroundWork(): void
    {
        $this->taskRunner->runDeferred();
    }

    public function agentCard(): AgentCard
    {
        return $this->agentCard;
    }

    /**
     * @return array{ActiveTask, RequestContext}
     */
    private function setupActiveTask(SendMessageRequest $params, ServerCallContext $context): array
    {
        TaskUtils::validateHistoryLength($params->getConfiguration());
        $message = $params->getMessage();
        if ($message === null) {
            throw new InvalidParamsError('message is required');
        }

        $originalTaskId = $message->getTaskId() !== '' ? $message->getTaskId() : null;
        $originalContextId = $message->getContextId() !== '' ? $message->getContextId() : null;

        if ($originalTaskId !== null) {
            $task = $this->taskStore->get($originalTaskId, $context);
            if ($task === null) {
                throw new TaskNotFoundError(sprintf('Task %s not found', $originalTaskId));
            }
            if (self::isTerminal($task)) {
                throw new UnsupportedOperationError(sprintf(
                    'Task %s is in terminal state %s and cannot accept new messages.',
                    $originalTaskId,
                    TaskStates::name($task->getStatus()?->getState() ?? 0),
                ));
            }
            if ($originalContextId !== null && $originalContextId !== $task->getContextId()) {
                throw new InvalidParamsError(sprintf('contextId %s does not match task %s (context %s).', $originalContextId, $originalTaskId, $task->getContextId()));
            }
            // A follow-up that only names the task continues its context.
            $originalContextId ??= $task->getContextId();
        }

        $request = $this->requestContextBuilder->build(
            context: $context,
            params: $params,
            taskId: $originalTaskId,
            contextId: $originalContextId,
            task: null,
        );
        $taskId = (string) $request->taskId();
        $contextId = (string) $request->contextId();

        $configuration = $params->getConfiguration();
        if ($this->pushConfigStore !== null && $configuration !== null && $configuration->hasTaskPushNotificationConfig()) {
            $pushConfig = $configuration->getTaskPushNotificationConfig();
            if ($pushConfig !== null) {
                $this->rejectUnsafePushUrl($pushConfig->getUrl());
                $this->pushConfigStore->setInfo($taskId, $pushConfig, $context);
            }
        }

        $activeTask = $this->activeTaskRegistry->create($taskId, $context, $contextId, $request->message());
        $activeTask->start($context, createTaskIfMissing: true);

        return [$activeTask, $request];
    }

    private function validateTaskIdMatch(string $taskId, string $eventTaskId): void
    {
        if ($taskId !== $eventTaskId) {
            $this->logger->error('Agent generated task_id={event} does not match the RequestContext task_id={task}.', ['event' => $eventTaskId, 'task' => $taskId]);

            throw new InternalError('Task ID mismatch in agent response');
        }
    }

    private function requireStreaming(): void
    {
        if ($this->agentCard->getCapabilities()?->getStreaming() !== true) {
            throw new UnsupportedOperationError('Streaming is not supported by the agent');
        }
    }

    private function requirePushConfigStore(): PushNotificationConfigStore
    {
        if ($this->agentCard->getCapabilities()?->getPushNotifications() !== true || $this->pushConfigStore === null) {
            throw new PushNotificationNotSupportedError('Push notifications are not supported by the agent');
        }

        return $this->pushConfigStore;
    }

    private function requireTask(string $taskId, ServerCallContext $context): Task
    {
        $task = $this->taskStore->get($taskId, $context);
        if ($task === null) {
            throw new TaskNotFoundError();
        }

        return $task;
    }

    private function rejectUnsafePushUrl(string $url): void
    {
        if ($this->pushUrlValidator !== null && !($this->pushUrlValidator)($url)) {
            throw new InvalidParamsError('Invalid push notification URL');
        }
    }

    private static function isTerminal(Task $task): bool
    {
        return TaskStates::isTerminal($task->getStatus()?->getState() ?? TaskState::TASK_STATE_UNSPECIFIED);
    }

    private static function validateParams(ProtobufMessage $params): void
    {
        ProtoUtils::validateProtoRequiredFields($params);
    }
}
