<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\Events\PublishedEvent;
use A2A\Server\Events\QueueManager;
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
use Google\Protobuf\Timestamp;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Checks each event the agent publishes, applies it to the task through the
 * TaskManager and publishes the result to the QueueManager.
 *
 * It enforces the same rules as the Python SDK: an agent either replies
 * with exactly one Message, or works in task mode (a Task plus status and
 * artifact updates), never both, and nothing may follow a terminal state.
 *
 * With a PushNotificationSender it also sends every task-mode event (the
 * updated Task for a Task event, the event itself otherwise) after it is
 * saved and published, as Python's EventConsumer._update_task_state does.
 * A sender failure is logged and never fails the task.
 *
 * Mirrors a2a-python: EventConsumer in
 * src/a2a/server/agent_execution/active_task.py (driven synchronously
 * instead of as an asyncio task).
 *
 * @internal Not covered by the 1.x backward-compatibility promise; may change in any release.
 */
final class EventConsumer
{
    private ?bool $taskMode = null;

    private ?Message $messageToSave = null;

    private bool $finished = false;

    public function __construct(
        private readonly TaskManager $taskManager,
        private readonly QueueManager $queueManager,
        private readonly string $taskId,
        private bool $taskCreated = false,
        private readonly ?PushNotificationSender $pushSender = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function requestStarted(RequestContext $request): void
    {
        $this->messageToSave = $request->message();
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function markTaskCreated(): void
    {
        $this->taskCreated = true;
    }

    public function process(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): PublishedEvent
    {
        if ($this->finished) {
            throw new InvalidAgentResponseError(sprintf('Received %s after the task reached a terminal state.', self::shortName($event)));
        }

        if ($event instanceof Message) {
            $this->handleMessage();
            $published = new PublishedEvent($event, null);
        } else {
            $updated = $this->handleTaskEvent($event);
            $this->taskCreated = true;
            // A snapshot, as Python copies it: later events keep changing the
            // live task, and what was published must not change with it.
            $snapshot = self::copy($updated);
            $published = new PublishedEvent($event instanceof Task ? $snapshot : $event, $snapshot);
            if ($updated->getStatus() !== null && TaskStates::isTerminal($updated->getStatus()->getState())) {
                $this->finished = true;
            }
        }

        $this->queueManager->publish($this->taskId, $published);
        if (!$event instanceof Message) {
            $this->push($event instanceof Task ? ($published->task ?? $event) : $event);
        }

        return $published;
    }

    /**
     * Moves the task to FAILED after an executor or validation error (unless
     * it already reached a terminal state) and publishes that change, so
     * subscribers in other processes stop waiting. Returns the published
     * event, or null when there was nothing to update.
     */
    public function fail(): ?PublishedEvent
    {
        $task = $this->taskManager->getTask();
        if ($task === null || ($task->getStatus() !== null && TaskStates::isTerminal($task->getStatus()->getState()))) {
            return null;
        }

        $timestamp = new Timestamp();
        $timestamp->fromDateTime(new \DateTime('now', new \DateTimeZone('UTC')));
        $event = new TaskStatusUpdateEvent([
            'task_id' => $task->getId(),
            'context_id' => $task->getContextId(),
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_FAILED, 'timestamp' => $timestamp]),
        ]);
        $updated = $this->taskManager->saveTaskEvent($event);
        $this->finished = true;
        $published = new PublishedEvent($event, self::copy($updated));
        $this->queueManager->publish($this->taskId, $published);
        $this->push($event);

        return $published;
    }

    private function push(Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
    {
        if ($this->pushSender === null) {
            return;
        }
        try {
            $this->pushSender->sendNotification($this->taskId, $event);
        } catch (\Throwable $e) {
            $this->logger->error('Sending push notifications for task {task_id} failed: {message}', [
                'task_id' => $this->taskId, 'message' => $e->getMessage(), 'exception' => $e,
            ]);
        }
    }

    private function handleMessage(): void
    {
        if ($this->taskMode === true) {
            throw new InvalidAgentResponseError('Received Message object in task mode. Use TaskStatusUpdateEvent or TaskArtifactUpdateEvent instead.');
        }
        if ($this->taskMode === false) {
            throw new InvalidAgentResponseError('Multiple Message objects received.');
        }
        $this->taskMode = false;
    }

    private function handleTaskEvent(Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): Task
    {
        if ($this->taskMode === false) {
            throw new InvalidAgentResponseError(sprintf(
                'Received %s in message mode. Use Task with TaskStatusUpdateEvent and TaskArtifactUpdateEvent instead.',
                self::shortName($event),
            ));
        }

        if ($event instanceof Task) {
            $this->handleInitialTask($event);
        } else {
            $this->handleTaskModification($event);
        }

        $this->taskMode = true;
        $task = $this->taskManager->getTask();
        if ($task === null) {
            throw new \RuntimeException(sprintf('Task %s not found', $this->taskId));
        }

        return $task;
    }

    private function handleInitialTask(Task $event): void
    {
        if ($this->taskManager->getTask() === null) {
            $this->taskManager->saveTaskEvent($event);
        }
        // An existing task is kept: Python logs "Ignoring task replacement."
        $this->messageToSave = null;
    }

    private function handleTaskModification(TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
    {
        if ($event instanceof TaskStatusUpdateEvent && !$this->taskCreated && $this->taskManager->getTask() === null) {
            throw new InvalidAgentResponseError(sprintf('Agent should enqueue Task before %s event', self::shortName($event)));
        }

        $task = $this->taskManager->ensureTaskId($this->taskId, $event->getContextId());

        if ($this->messageToSave !== null) {
            // Python compares message ids only. Comparing the content too
            // still skips the request message the executor already put in
            // its Task, but keeps a follow-up that reuses an id (the TCK
            // does) instead of silently dropping it from history.
            $alreadySaved = false;
            $candidate = $this->messageToSave->serializeToString();
            foreach ($task->getHistory() as $message) {
                if ($message->getMessageId() === $this->messageToSave->getMessageId() && $message->serializeToString() === $candidate) {
                    $alreadySaved = true;
                    break;
                }
            }
            if (!$alreadySaved) {
                $task = $this->taskManager->updateWithMessage($this->messageToSave, $task);
                $this->taskManager->saveTaskEvent($task);
            }
            $this->messageToSave = null;
        }

        $this->taskManager->contextId = $event->getContextId();
        $this->taskManager->process($event);
    }

    private static function copy(Task $task): Task
    {
        $copy = new Task();
        $copy->mergeFrom($task);

        return $copy;
    }

    private static function shortName(object $event): string
    {
        $parts = explode('\\', $event::class);

        return end($parts);
    }
}
