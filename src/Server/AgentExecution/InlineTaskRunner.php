<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Server\Events\QueueManager;
use A2A\Utils\Errors\UnsupportedOperationError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs the executor in the current PHP process, during the request.
 *
 * While it runs, it holds a run lease in the QueueManager, so other
 * processes know the task is busy (a second message for the same task waits
 * for it, and a cancel request from another process can reach it).
 *
 * Work deferred with defer() runs when runDeferred() is called (the SDK's
 * ResponseEmitter does this after sending the response), or at the latest
 * at shutdown, after fastcgi_finish_request() where PHP-FPM provides it.
 *
 * PHP-specific; see TaskRunner.
 */
final class InlineTaskRunner implements TaskRunner
{
    /** @var list<\Closure(): void> */
    private array $deferred = [];

    private bool $shutdownRegistered = false;

    /**
     * @param int   $leaseSeconds     how long a run lease lasts if the process dies without releasing it
     * @param float $leaseWaitSeconds how long a request waits for another request on the same task to finish
     */
    public function __construct(
        private readonly QueueManager $queueManager,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $leaseSeconds = 600,
        private readonly float $leaseWaitSeconds = 30.0,
    ) {}

    public function run(ActiveTask $activeTask, RequestContext $context): \Generator
    {
        $taskId = $activeTask->taskId();
        $deadline = microtime(true) + $this->leaseWaitSeconds;
        while (!$this->queueManager->acquireRunLease($taskId, $this->leaseSeconds)) {
            if (microtime(true) >= $deadline) {
                throw new UnsupportedOperationError(sprintf('Task %s is still processing another request.', $taskId));
            }
            usleep(50_000);
        }

        try {
            yield from $activeTask->run($context);
        } finally {
            $this->queueManager->releaseRunLease($taskId);
        }
    }

    public function defer(\Closure $work): void
    {
        $this->deferred[] = $work;
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                if ($this->deferred === []) {
                    return;
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $this->runDeferred();
            });
        }
    }

    public function runDeferred(): void
    {
        ignore_user_abort(true);
        while ($this->deferred !== []) {
            $work = array_shift($this->deferred);
            try {
                $work();
            } catch (\Throwable $e) {
                $this->logger->error('Deferred task work failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
