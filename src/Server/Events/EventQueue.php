<?php

declare(strict_types=1);

namespace A2A\Server\Events;

use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;

/**
 * Where an AgentExecutor publishes what it does: a Task, a Message, or
 * status/artifact update events.
 *
 * Mirrors a2a-python: EventQueue in src/a2a/server/events/event_queue.py.
 * Python's enqueue_event is a coroutine; here it is a plain call. The queue
 * the SDK hands to your executor processes each event as you enqueue it
 * (saving the task and streaming the event), so events reach clients while
 * execute() is still running.
 */
interface EventQueue
{
    public function enqueueEvent(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void;
}
