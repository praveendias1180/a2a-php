<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatusUpdateEvent;

/**
 * Collects a stream of agent events into the final result (a Message or
 * the Task), applying each event through a TaskManager.
 *
 * Mirrors a2a-python: ResultAggregator in
 * src/a2a/server/tasks/result_aggregator.py. It takes any iterable of
 * events (Python takes an EventConsumer). Where Python starts a background
 * asyncio task to finish consuming, consumeAndBreakOnInterrupt() returns a
 * closure the caller runs later (for example with TaskRunner::defer()).
 */
final class ResultAggregator
{
    private ?Message $message = null;

    public function __construct(public readonly TaskManager $taskManager) {}

    public function currentResult(): Task|Message|null
    {
        return $this->message ?? $this->taskManager->getTask();
    }

    /**
     * Applies each event and passes it on.
     *
     * @param iterable<Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent> $events
     *
     * @return \Generator<int, Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent, mixed, void>
     */
    public function consumeAndEmit(iterable $events): \Generator
    {
        foreach ($events as $event) {
            $this->taskManager->process($event);
            yield $event;
        }
    }

    /**
     * Applies every event and returns the result: the first Message, or the
     * task once the events run out.
     *
     * @param iterable<Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent> $events
     */
    public function consumeAll(iterable $events): Task|Message|null
    {
        foreach ($events as $event) {
            if ($event instanceof Message) {
                $this->message = $event;

                return $event;
            }
            $this->taskManager->process($event);
        }

        return $this->taskManager->getTask();
    }

    /**
     * Consumes until the result is ready: a Message, an auth-required task,
     * or (when $blocking is false) the first event.
     *
     * Returns [result, interrupted, continuation]. When interrupted, the
     * continuation consumes the rest of the events; run it after answering
     * the client.
     *
     * @param \Iterator<mixed, Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent> $events
     * @param (\Closure(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent): void)|null $eventCallback
     *
     * @return array{0: Task|Message|null, 1: bool, 2: (\Closure(): void)|null}
     */
    public function consumeAndBreakOnInterrupt(\Iterator $events, bool $blocking = true, ?\Closure $eventCallback = null): array
    {
        for ($events->rewind(); $events->valid(); $events->next()) {
            $event = $events->current();
            if ($event instanceof Message) {
                $this->message = $event;

                return [$event, false, null];
            }
            $this->taskManager->process($event);
            if ($eventCallback !== null) {
                $eventCallback($event);
            }

            $authRequired = ($event instanceof Task || $event instanceof TaskStatusUpdateEvent)
                && $event->getStatus()?->getState() === TaskState::TASK_STATE_AUTH_REQUIRED;

            if ($authRequired || !$blocking) {
                $continuation = function () use ($events, $eventCallback): void {
                    for ($events->next(); $events->valid(); $events->next()) {
                        $next = $events->current();
                        $this->taskManager->process($next);
                        if ($eventCallback !== null) {
                            $eventCallback($next);
                        }
                    }
                };

                return [$this->taskManager->getTask(), true, $continuation];
            }
        }

        return [$this->taskManager->getTask(), false, null];
    }
}
