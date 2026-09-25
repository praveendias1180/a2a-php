<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\Events\PublishedEvent;

/**
 * Decides where and when an executor runs.
 *
 * PHP-specific. a2a-python always runs the executor as a background asyncio
 * task. PHP-FPM can't keep work running after a request ends, so this
 * interface lets the core run it inline (InlineTaskRunner) and lets the
 * Laravel bridge move it to a queue worker, while the request handler stays
 * the same.
 */
interface TaskRunner
{
    /**
     * Runs one request against the task and yields each event as it is
     * processed.
     *
     * @return \Generator<int, PublishedEvent, mixed, void>
     */
    public function run(ActiveTask $activeTask, RequestContext $context): \Generator;

    /**
     * Queues work to finish after the HTTP response has been sent (used when
     * a request returns before the executor is done).
     *
     * @param \Closure(): void $work
     */
    public function defer(\Closure $work): void;

    /**
     * Runs the deferred work. Call it after the response is sent.
     */
    public function runDeferred(): void;
}
