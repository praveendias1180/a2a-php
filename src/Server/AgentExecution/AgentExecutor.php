<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\Events\EventQueue;

/**
 * Your agent's logic. The one interface you implement.
 *
 * execute() reads the request from the RequestContext and publishes what
 * happens to the EventQueue: a single Message for a direct reply, or a Task
 * followed by status and artifact updates for longer work. Each enqueued
 * event is saved and streamed to clients right away.
 *
 * Rules (the same as the Python SDK):
 * - execute() is never called twice at once for the same task.
 * - An exception thrown from execute() moves the task to FAILED.
 * - Before returning normally, move the task to a terminal state
 *   (completed, failed, rejected, canceled) or an interrupted one
 *   (input-required, auth-required). The next message with the same task id
 *   calls execute() again.
 * - After execute() returns, don't touch the context or the queue.
 *
 * Cancellation (PHP-specific): PHP can't interrupt a running call from
 * outside. When a cancel arrives the SDK calls cancel(); if execute() is
 * running it also receives a TaskCancelledException at its next
 * enqueueEvent(). Long loops should check $context->isCancelled().
 *
 * Mirrors a2a-python: AgentExecutor in
 * src/a2a/server/agent_execution/agent_executor.py
 */
interface AgentExecutor
{
    public function execute(RequestContext $context, EventQueue $eventQueue): void;

    /**
     * Publish the cancellation, normally with TaskUpdater::cancel().
     */
    public function cancel(RequestContext $context, EventQueue $eventQueue): void;
}
