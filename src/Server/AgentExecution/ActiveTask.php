<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\Events\PublishedEvent;
use A2A\Server\Events\QueueManager;
use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\PushNotificationSender;
use A2A\Server\Tasks\TaskManager;
use A2A\Server\Tasks\TaskStates;
use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Errors\InvalidAgentResponseError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\TaskNotFoundError;
use Google\Protobuf\Timestamp;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs the executor for one request on one task and turns what it publishes
 * into saved, streamed events.
 *
 * How it differs from Python: a2a-python runs the executor as a background
 * asyncio task and fans events out through queues. PHP has no event loop,
 * so execute() runs inside a Fiber here. Each enqueueEvent() suspends the
 * Fiber, the event is processed and yielded to the caller, then the Fiber
 * resumes. The caller pulls events as fast as it can send them, so a
 * streaming response is live, and a caller that stops pulling early (for
 * `returnImmediately`) can finish the rest after sending its response.
 *
 * Mirrors a2a-python: ActiveTask in
 * src/a2a/server/agent_execution/active_task.py
 */
final class ActiveTask
{
    public function __construct(
        private readonly AgentExecutor $agentExecutor,
        private readonly string $taskId,
        private readonly TaskManager $taskManager,
        private readonly QueueManager $queueManager,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?PushNotificationSender $pushSender = null,
    ) {}

    public function taskId(): string
    {
        return $this->taskId;
    }

    /**
     * Checks the task can take a request: it must exist unless
     * $createTaskIfMissing, and it must not be in a terminal state.
     *
     * @throws TaskNotFoundError
     * @throws InvalidParamsError
     */
    public function start(ServerCallContext $callContext, bool $createTaskIfMissing = false): ?Task
    {
        $this->taskManager->setCallContext($callContext);
        $task = $this->taskManager->getTask();
        if ($task !== null) {
            $state = $task->getStatus()?->getState() ?? TaskState::TASK_STATE_UNSPECIFIED;
            if (TaskStates::isTerminal($state)) {
                throw new InvalidParamsError(sprintf('Task %s is in terminal state: %s', $task->getId(), TaskStates::name($state)));
            }
        } elseif (!$createTaskIfMissing) {
            throw new TaskNotFoundError();
        }

        return $task;
    }

    /**
     * Runs execute() for the request and yields each processed event as the
     * executor publishes it.
     *
     * @return \Generator<int, PublishedEvent, mixed, void>
     */
    public function run(RequestContext $request): \Generator
    {
        $this->taskManager->setCallContext($request->callContext());
        $current = $this->taskManager->getTask();
        $request->setCurrentTask($current);

        $consumer = new EventConsumer($this->taskManager, $this->queueManager, $this->taskId, $current !== null, $this->pushSender, $this->logger);
        $consumer->requestStarted($request);

        $token = new CancellationToken(fn(): bool => $this->queueManager->isCancelRequested($this->taskId));
        $request->setCancellationToken($token);

        $queue = new FiberEventQueue();
        $fiber = new \Fiber(function () use ($request, $queue): void {
            $this->agentExecutor->execute($request, $queue);
        });
        $queue->bind($fiber);

        try {
            $event = $fiber->start();
            while (true) {
                foreach ($queue->takePending() as $pending) {
                    yield $consumer->process($pending);
                }
                if ($fiber->isTerminated()) {
                    return;
                }
                if ($token->isCancelled()) {
                    $this->stopForCancel($fiber, $request, $consumer);

                    return;
                }
                if ($event !== null) {
                    yield $consumer->process(self::asEvent($event));
                }
                $event = $fiber->resume();
            }
        } catch (TaskCancelledException) {
            return;
        } catch (\Throwable $e) {
            $this->logger->error('Agent execution failed for task {task}: {error}', [
                'task' => $this->taskId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            try {
                $consumer->fail();
            } catch (\Throwable $failure) {
                $this->logger->error('Could not mark task {task} as failed: {error}', ['task' => $this->taskId, 'error' => $failure->getMessage()]);
            }

            throw $e;
        }
    }

    /**
     * Cancels a task that is not running in this process: calls the
     * executor's cancel() and, if that left the task non-terminal, marks it
     * CANCELED so the caller is never left with a live task.
     */
    public function cancel(ServerCallContext $callContext): Task
    {
        $this->taskManager->setCallContext($callContext);
        $task = $this->taskManager->refresh();
        if ($task === null) {
            throw new TaskNotFoundError();
        }

        $consumer = new EventConsumer($this->taskManager, $this->queueManager, $this->taskId, true, $this->pushSender, $this->logger);
        $request = new RequestContext(
            callContext: $callContext,
            taskId: $this->taskId,
            contextId: $task->getContextId(),
            currentTask: $task,
        );
        $cancelled = new CancellationToken();
        $cancelled->cancel();
        $request->setCancellationToken($cancelled);

        try {
            $this->agentExecutor->cancel($request, new ProcessingEventQueue(static function ($event) use ($consumer): void {
                $consumer->process($event);
            }));
        } catch (\Throwable $e) {
            $this->logger->error('Agent cancel failed for task {task}: {error}', ['task' => $this->taskId, 'error' => $e->getMessage(), 'exception' => $e]);
            $consumer->fail();

            throw $e;
        }

        return $this->ensureCanceled($consumer);
    }

    /**
     * The task as stored now.
     */
    public function getTask(): Task
    {
        $task = $this->taskManager->refresh();
        if ($task === null) {
            throw new \RuntimeException('Task should have been created');
        }

        return $task;
    }

    /**
     * The value the executor's Fiber was suspended with. Only
     * FiberEventQueue suspends it, with an event; anything else means the
     * executor (or a library it uses) suspended the SDK's Fiber itself.
     */
    private static function asEvent(mixed $value): Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent
    {
        if ($value instanceof Message || $value instanceof Task || $value instanceof TaskStatusUpdateEvent || $value instanceof TaskArtifactUpdateEvent) {
            return $value;
        }

        throw new InvalidAgentResponseError(sprintf(
            'The executor suspended the SDK Fiber with a %s instead of enqueueing an event. Fiber-based async code must run inside its own Fiber.',
            get_debug_type($value),
        ));
    }

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private function stopForCancel(\Fiber $fiber, RequestContext $request, EventConsumer $consumer): void
    {
        $this->logger->info('Task {task} was cancelled while running; stopping the executor.', ['task' => $this->taskId]);

        if (!$consumer->isFinished()) {
            try {
                $this->agentExecutor->cancel($request, new ProcessingEventQueue(static function ($event) use ($consumer): void {
                    $consumer->process($event);
                }));
            } catch (\Throwable $e) {
                $this->logger->error('Agent cancel failed for task {task}: {error}', ['task' => $this->taskId, 'error' => $e->getMessage(), 'exception' => $e]);
            }
        }

        try {
            $fiber->throw(new TaskCancelledException(sprintf('Task %s was cancelled.', $this->taskId)));
        } catch (\Throwable) {
            // The executor let the exception escape (expected) or failed while
            // unwinding. Either way it has stopped.
        }

        if (!$consumer->isFinished()) {
            $this->ensureCanceled($consumer);
        }
    }

    private function ensureCanceled(EventConsumer $consumer): Task
    {
        $task = $this->taskManager->refresh();
        if ($task === null) {
            throw new \RuntimeException('Task should have been created');
        }
        $state = $task->getStatus()?->getState() ?? TaskState::TASK_STATE_UNSPECIFIED;
        if (TaskStates::isTerminal($state) || $consumer->isFinished()) {
            return $task;
        }

        $timestamp = new Timestamp();
        $timestamp->fromDateTime(new \DateTime('now', new \DateTimeZone('UTC')));
        $published = $consumer->process(new TaskStatusUpdateEvent([
            'task_id' => $task->getId(),
            'context_id' => $task->getContextId(),
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_CANCELED, 'timestamp' => $timestamp]),
        ]));

        return $published->task ?? $this->getTask();
    }
}
