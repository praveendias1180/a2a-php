<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\Events\EventQueue;
use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;

/**
 * A queue that processes every event immediately. Used for cancel(), which
 * runs outside the executor's Fiber.
 *
 * @internal
 */
final class ProcessingEventQueue implements EventQueue
{
    /**
     * @param \Closure(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent): void $sink
     */
    public function __construct(private readonly \Closure $sink) {}

    public function enqueueEvent(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
    {
        ($this->sink)($event);
    }
}
