<?php

declare(strict_types=1);

namespace A2A\Server\Events;

use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;

/**
 * A plain FIFO buffer of events. Useful for testing an executor on its own:
 * run execute() against it and inspect what it enqueued.
 *
 * Mirrors a2a-python: EventQueueLegacy in src/a2a/server/events/event_queue.py
 * (without asyncio: dequeueEvent() returns null when empty instead of
 * waiting).
 */
final class InMemoryEventQueue implements EventQueue
{
    /** @var list<Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent> */
    private array $events = [];

    private bool $closed = false;

    public function enqueueEvent(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
    {
        if ($this->closed) {
            throw new QueueClosedException('The event queue is closed.');
        }
        $this->events[] = $event;
    }

    public function dequeueEvent(): Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent|null
    {
        return array_shift($this->events);
    }

    /**
     * @return list<Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
