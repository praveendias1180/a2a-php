<?php

declare(strict_types=1);

namespace A2A\Tests\Server\AgentExecution;

use A2A\Server\AgentExecution\ActiveTask;
use A2A\Server\AgentExecution\InlineTaskRunner;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskManager;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Utils\Errors\UnsupportedOperationError;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class InlineTaskRunnerTest extends TestCase
{
    public function testHoldsTheRunLeaseWhileRunning(): void
    {
        $queues = new InMemoryQueueManager();
        $leased = null;
        $executor = new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u, RequestContext $c) use ($queues, &$leased): void {
            $leased = $queues->hasActiveRunLease((string) $c->taskId());
            $u->complete();
        }));
        [$active, $request] = $this->create($executor, $queues);

        iterator_to_array((new InlineTaskRunner($queues))->run($active, $request), false);

        self::assertTrue($leased);
        self::assertFalse($queues->hasActiveRunLease((string) $request->taskId()));
    }

    public function testReleasesTheLeaseWhenTheExecutorFails(): void
    {
        $queues = new InMemoryQueueManager();
        [$active, $request] = $this->create(new CallbackExecutor(static function (): void {
            throw new \RuntimeException('boom');
        }), $queues);

        try {
            iterator_to_array((new InlineTaskRunner($queues))->run($active, $request), false);
        } catch (\RuntimeException) {
        }

        self::assertFalse($queues->hasActiveRunLease((string) $request->taskId()));
    }

    public function testRefusesATaskBusyInAnotherRequest(): void
    {
        $queues = new InMemoryQueueManager();
        [$active, $request] = $this->create(new CallbackExecutor(), $queues);
        $queues->acquireRunLease((string) $request->taskId(), 60);

        $this->expectException(UnsupportedOperationError::class);
        iterator_to_array((new InlineTaskRunner($queues, leaseWaitSeconds: 0.1))->run($active, $request), false);
    }

    public function testDeferredWorkRunsInOrderAndErrorsAreLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->errors[] = is_string($level) ? $level : get_debug_type($level);
            }
        };
        $runner = new InlineTaskRunner(new InMemoryQueueManager(), $logger);
        $order = [];
        $runner->defer(static function () use (&$order): void {
            $order[] = 1;
        });
        $runner->defer(static function (): void {
            throw new \RuntimeException('deferred failure');
        });
        $runner->defer(static function () use (&$order): void {
            $order[] = 3;
        });

        $runner->runDeferred();
        $runner->runDeferred();

        self::assertSame([1, 3], $order);
        self::assertSame(['error'], $logger->errors);
    }

    /**
     * @return array{ActiveTask, RequestContext}
     */
    private function create(CallbackExecutor $executor, InMemoryQueueManager $queues): array
    {
        $request = new RequestContext(Fixtures::callContext(), Fixtures::sendRequest(Fixtures::userMessage()));
        $taskId = (string) $request->taskId();

        return [new ActiveTask($executor, $taskId, new TaskManager(new InMemoryTaskStore(), Fixtures::callContext(), $taskId, $request->contextId(), $request->message()), $queues), $request];
    }
}
