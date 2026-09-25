<?php

declare(strict_types=1);

namespace A2A\Server\Events;

use A2A\Server\Tasks\TaskStates;
use A2A\Types\Message;
use A2A\Types\StreamResponse;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\ProtoUtils;

/**
 * One processed agent event, paired with the task as it stood right after
 * the event was applied (null for a Message in message-only mode).
 *
 * Mirrors the `(event, updated_task)` tuples a2a-python pushes to its
 * subscriber queue in src/a2a/server/agent_execution/active_task.py. In PHP
 * these also travel between processes through a QueueManager, so they can
 * be serialized to JSON.
 */
final class PublishedEvent
{
    public function __construct(
        public readonly Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event,
        public readonly ?Task $task = null,
    ) {}

    /**
     * True when the task reached a terminal state with this event.
     */
    public function isTerminal(): bool
    {
        if ($this->task !== null && $this->task->getStatus() !== null) {
            return TaskStates::isTerminal($this->task->getStatus()->getState());
        }
        if ($this->event instanceof TaskStatusUpdateEvent && $this->event->getStatus() !== null) {
            return TaskStates::isTerminal($this->event->getStatus()->getState());
        }

        return false;
    }

    public function toJson(): string
    {
        $payload = ['event' => json_decode(ProtoUtils::toStreamResponse($this->event)->serializeToJsonString(), true)];
        if ($this->task !== null) {
            $payload['task'] = json_decode($this->task->serializeToJsonString(), true);
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(string $json): self
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !isset($payload['event'])) {
            throw new \InvalidArgumentException('Malformed published event payload.');
        }

        $response = new StreamResponse();
        $response->mergeFromJsonString(json_encode($payload['event'], JSON_THROW_ON_ERROR));
        $event = match ($response->getPayload()) {
            'task' => $response->getTask(),
            'message' => $response->getMessage(),
            'status_update' => $response->getStatusUpdate(),
            'artifact_update' => $response->getArtifactUpdate(),
            default => null,
        };
        if ($event === null) {
            throw new \InvalidArgumentException('Published event payload has no event.');
        }

        $task = null;
        if (isset($payload['task'])) {
            $task = new Task();
            $task->mergeFromJsonString(json_encode($payload['task'], JSON_THROW_ON_ERROR));
        }

        return new self($event, $task);
    }
}
