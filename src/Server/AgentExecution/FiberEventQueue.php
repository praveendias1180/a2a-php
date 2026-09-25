<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\Events\EventQueue;
use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;

/**
 * The queue ActiveTask gives to execute(). Enqueueing suspends the
 * executor's Fiber so the SDK can save and stream the event before the
 * executor carries on.
 *
 * Events enqueued from another Fiber (say, an async library the executor
 * uses) are buffered and handled at the next suspension point instead.
 *
 * @internal
 */
final class FiberEventQueue implements EventQueue
{
    /** @var \Fiber<mixed, mixed, mixed, mixed>|null */
    private ?\Fiber $fiber = null;

    /** @var list<Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent> */
    private array $pending = [];

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public function bind(\Fiber $fiber): void
    {
        $this->fiber = $fiber;
    }

    public function enqueueEvent(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
    {
        if ($this->fiber !== null && \Fiber::getCurrent() === $this->fiber) {
            \Fiber::suspend($event);

            return;
        }
        $this->pending[] = $event;
    }

    /**
     * @return list<Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent>
     */
    public function takePending(): array
    {
        $pending = $this->pending;
        $this->pending = [];

        return $pending;
    }
}
