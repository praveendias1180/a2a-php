<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\ServerCallContext;
use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Errors\InvalidAgentResponseError;
use A2A\Utils\Errors\InvalidParamsError;

/**
 * Applies agent events to one task and saves the result: a Task replaces
 * it, a status update moves the old status message into history, and an
 * artifact update adds, replaces or appends to an artifact.
 *
 * Mirrors a2a-python: TaskManager in src/a2a/server/tasks/task_manager.py
 */
final class TaskManager
{
    private ?Task $currentTask = null;

    public function __construct(
        private readonly TaskStore $taskStore,
        private ServerCallContext $callContext,
        public ?string $taskId,
        public ?string $contextId,
        private readonly ?Message $initialMessage = null,
    ) {
        if ($taskId !== null && $taskId === '') {
            throw new \InvalidArgumentException('Task ID must be a non-empty string');
        }
    }

    public function setCallContext(ServerCallContext $context): void
    {
        $this->callContext = $context;
    }

    public function callContext(): ServerCallContext
    {
        return $this->callContext;
    }

    /**
     * The task, from this manager's cache or the store. Null when there is
     * no task id yet or the task doesn't exist.
     */
    public function getTask(): ?Task
    {
        if ($this->taskId === null) {
            return null;
        }
        if ($this->currentTask !== null) {
            return $this->currentTask;
        }
        $this->currentTask = $this->taskStore->get($this->taskId, $this->callContext);

        return $this->currentTask;
    }

    /**
     * Forgets the cached task so the next getTask() reads the store again.
     * PHP-specific: another process may have changed the task (a cancel
     * request, for example).
     */
    public function refresh(): ?Task
    {
        $this->currentTask = null;

        return $this->getTask();
    }

    public function saveTaskEvent(Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): Task
    {
        $eventTaskId = $event instanceof Task ? $event->getId() : $event->getTaskId();
        if ($this->taskId !== null && $this->taskId !== $eventTaskId) {
            throw new InvalidParamsError(sprintf("Task in event doesn't match TaskManager %s : %s", $this->taskId, $eventTaskId));
        }
        $this->taskId ??= $eventTaskId;

        if ($this->contextId !== null && $this->contextId !== $event->getContextId()) {
            throw new InvalidParamsError(sprintf("Context in event doesn't match TaskManager %s : %s", $this->contextId, $event->getContextId()));
        }
        $this->contextId ??= $event->getContextId();

        if ($event instanceof Task) {
            $this->saveTask($event);

            return $event;
        }

        $task = $this->ensureTask($event);
        if ($event instanceof TaskStatusUpdateEvent) {
            $statusMessage = $task->getStatus()?->getMessage();
            if ($statusMessage !== null) {
                $history = iterator_to_array($task->getHistory());
                $history[] = $statusMessage;
                $task->setHistory($history);
            }
            $metadata = $event->getMetadata();
            if ($metadata !== null && count($metadata->getFields()) > 0) {
                $merged = $task->getMetadata() ?? new \Google\Protobuf\Struct();
                $merged->mergeFrom($metadata);
                $task->setMetadata($merged);
            }
            $newStatus = new TaskStatus();
            if ($event->getStatus() !== null) {
                $newStatus->mergeFrom($event->getStatus());
            }
            $task->setStatus($newStatus);
        } else {
            self::appendArtifactToTask($task, $event);
        }
        $this->saveTask($task);

        return $task;
    }

    public function ensureTaskId(string $taskId, string $contextId): Task
    {
        $task = $this->currentTask;
        if ($task === null && $this->taskId !== null) {
            $task = $this->taskStore->get($this->taskId, $this->callContext);
        }
        if ($task === null) {
            $task = $this->initTaskObject($taskId, $contextId);
            $this->saveTask($task);
        }

        return $task;
    }

    public function ensureTask(TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): Task
    {
        return $this->ensureTaskId($event->getTaskId(), $event->getContextId());
    }

    public function process(Task|Message|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): Task|Message|TaskStatusUpdateEvent|TaskArtifactUpdateEvent
    {
        if (!$event instanceof Message) {
            $this->saveTaskEvent($event);
        }

        return $event;
    }

    /**
     * Adds the message to history, moving any status message there first.
     */
    public function updateWithMessage(Message $message, Task $task): Task
    {
        $history = iterator_to_array($task->getHistory());
        $status = $task->getStatus();
        $statusMessage = $status?->getMessage();
        if ($status !== null && $statusMessage !== null) {
            $history[] = $statusMessage;
            // Not clearMessage(): the pure-PHP protobuf runtime unsets the
            // property, and a later getMessage() then raises a warning.
            $status->setMessage(null);
        }
        $history[] = $message;
        $task->setHistory($history);
        $this->currentTask = $task;

        return $task;
    }

    /**
     * Mirrors a2a-python: append_artifact_to_task().
     */
    public static function appendArtifactToTask(Task $task, TaskArtifactUpdateEvent $event): void
    {
        $newArtifact = $event->getArtifact();
        if ($newArtifact === null) {
            return;
        }
        $artifactId = $newArtifact->getArtifactId();
        $artifacts = iterator_to_array($task->getArtifacts());

        $existingIndex = null;
        foreach ($artifacts as $index => $artifact) {
            if ($artifact->getArtifactId() === $artifactId) {
                $existingIndex = $index;
                break;
            }
        }

        if (!$event->getAppend()) {
            if ($existingIndex !== null) {
                $artifacts[$existingIndex] = $newArtifact;
            } else {
                $artifacts[] = $newArtifact;
            }
            $task->setArtifacts($artifacts);

            return;
        }

        if ($existingIndex === null) {
            throw new InvalidAgentResponseError(sprintf(
                "append=True for nonexistent artifact_id='%s' in task '%s'. The artifact must be created (append=False) before appending parts to it.",
                $artifactId,
                $task->getId(),
            ));
        }

        $existing = $artifacts[$existingIndex];
        $parts = iterator_to_array($existing->getParts());
        foreach ($newArtifact->getParts() as $part) {
            $parts[] = $part;
        }
        $existing->setParts($parts);
        $newMetadata = $newArtifact->getMetadata();
        if ($newMetadata !== null && count($newMetadata->getFields()) > 0) {
            $metadata = $existing->getMetadata() ?? new \Google\Protobuf\Struct();
            $fields = $metadata->getFields();
            foreach ($newMetadata->getFields() as $key => $value) {
                if (is_string($key)) {
                    $fields[$key] = $value;
                }
            }
            $existing->setMetadata($metadata);
        }
        $task->setArtifacts($artifacts);
    }

    private function initTaskObject(string $taskId, string $contextId): Task
    {
        return new Task([
            'id' => $taskId,
            'context_id' => $contextId,
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_SUBMITTED]),
            'history' => $this->initialMessage === null ? [] : [$this->initialMessage],
        ]);
    }

    private function saveTask(Task $task): void
    {
        $this->taskStore->save($task, $this->callContext);
        $this->currentTask = $task;
        if ($this->taskId === null) {
            $this->taskId = $task->getId();
            $this->contextId = $task->getContextId();
        }
    }
}
