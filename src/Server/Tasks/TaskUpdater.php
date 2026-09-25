<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\Events\EventQueue;
use A2A\Server\IdGenerator;
use A2A\Server\IdGeneratorContext;
use A2A\Server\UuidGenerator;
use A2A\Types\Artifact;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\ProtoUtils;
use Google\Protobuf\Timestamp;

/**
 * The helper an executor uses to publish status changes and artifacts for
 * one task.
 *
 * Mirrors a2a-python: TaskUpdater in src/a2a/server/tasks/task_updater.py
 */
final class TaskUpdater
{
    private bool $terminalStateReached = false;

    private readonly IdGenerator $artifactIdGenerator;

    private readonly IdGenerator $messageIdGenerator;

    public function __construct(
        public readonly EventQueue $eventQueue,
        public readonly string $taskId,
        public readonly string $contextId,
        ?IdGenerator $artifactIdGenerator = null,
        ?IdGenerator $messageIdGenerator = null,
    ) {
        $this->artifactIdGenerator = $artifactIdGenerator ?? new UuidGenerator();
        $this->messageIdGenerator = $messageIdGenerator ?? new UuidGenerator();
    }

    /**
     * Publishes a status change. After a terminal state (completed,
     * canceled, failed, rejected) further updates throw.
     *
     * @param string|null               $timestamp ISO 8601; defaults to now (UTC)
     * @param array<string, mixed>|null $metadata
     */
    public function updateStatus(int $state, ?Message $message = null, ?string $timestamp = null, ?array $metadata = null): void
    {
        if ($this->terminalStateReached) {
            throw new \RuntimeException(sprintf('Task %s is already in a terminal state.', $this->taskId));
        }
        if (TaskStates::isTerminal($state)) {
            $this->terminalStateReached = true;
        }

        $ts = new Timestamp();
        $ts->fromDateTime($timestamp !== null
            ? new \DateTime(str_replace('Z', '+00:00', $timestamp))
            : new \DateTime('now', new \DateTimeZone('UTC')));

        $status = new TaskStatus(['state' => $state, 'timestamp' => $ts]);
        if ($message !== null) {
            $status->setMessage($message);
        }

        $event = new TaskStatusUpdateEvent([
            'task_id' => $this->taskId,
            'context_id' => $this->contextId,
            'status' => $status,
        ]);
        if ($metadata !== null) {
            $event->setMetadata(ProtoUtils::toStruct($metadata));
        }

        $this->eventQueue->enqueueEvent($event);
    }

    /**
     * Publishes an artifact (or a chunk of one when $append is true).
     *
     * @param list<Part>                $parts
     * @param array<string, mixed>|null $metadata
     * @param list<string>|null         $extensions
     */
    public function addArtifact(
        array $parts,
        ?string $artifactId = null,
        ?string $name = null,
        ?array $metadata = null,
        ?bool $append = null,
        ?bool $lastChunk = null,
        ?array $extensions = null,
    ): void {
        if ($artifactId === null || $artifactId === '') {
            $artifactId = $this->artifactIdGenerator->generate(new IdGeneratorContext($this->taskId, $this->contextId));
        }

        $artifact = new Artifact(['artifact_id' => $artifactId, 'parts' => $parts]);
        if ($name !== null) {
            $artifact->setName($name);
        }
        if ($metadata !== null) {
            $artifact->setMetadata(ProtoUtils::toStruct($metadata));
        }
        if ($extensions !== null) {
            $artifact->setExtensions($extensions);
        }

        $this->eventQueue->enqueueEvent(new TaskArtifactUpdateEvent([
            'task_id' => $this->taskId,
            'context_id' => $this->contextId,
            'artifact' => $artifact,
            'append' => (bool) $append,
            'last_chunk' => (bool) $lastChunk,
        ]));
    }

    public function complete(?Message $message = null): void
    {
        $this->updateStatus(TaskState::TASK_STATE_COMPLETED, $message);
    }

    public function failed(?Message $message = null): void
    {
        $this->updateStatus(TaskState::TASK_STATE_FAILED, $message);
    }

    public function reject(?Message $message = null): void
    {
        $this->updateStatus(TaskState::TASK_STATE_REJECTED, $message);
    }

    public function submit(?Message $message = null): void
    {
        $this->updateStatus(TaskState::TASK_STATE_SUBMITTED, $message);
    }

    public function startWork(?Message $message = null): void
    {
        $this->updateStatus(TaskState::TASK_STATE_WORKING, $message);
    }

    public function cancel(?Message $message = null): void
    {
        $this->updateStatus(TaskState::TASK_STATE_CANCELED, $message);
    }

    public function requiresInput(?Message $message = null): void
    {
        $this->updateStatus(TaskState::TASK_STATE_INPUT_REQUIRED, $message);
    }

    public function requiresAuth(?Message $message = null): void
    {
        $this->updateStatus(TaskState::TASK_STATE_AUTH_REQUIRED, $message);
    }

    /**
     * An agent message bound to this task and context.
     *
     * @param list<Part>                $parts
     * @param array<string, mixed>|null $metadata
     */
    public function newAgentMessage(array $parts, ?array $metadata = null): Message
    {
        $message = new Message([
            'role' => Role::ROLE_AGENT,
            'task_id' => $this->taskId,
            'context_id' => $this->contextId,
            'message_id' => $this->messageIdGenerator->generate(new IdGeneratorContext($this->taskId, $this->contextId)),
            'parts' => $parts,
        ]);
        if ($metadata !== null) {
            $message->setMetadata(ProtoUtils::toStruct($metadata));
        }

        return $message;
    }
}
