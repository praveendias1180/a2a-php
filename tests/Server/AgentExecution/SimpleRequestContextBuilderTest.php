<?php

declare(strict_types=1);

namespace A2A\Tests\Server\AgentExecution;

use A2A\Server\AgentExecution\SimpleRequestContextBuilder;
use A2A\Server\IdGenerator;
use A2A\Server\IdGeneratorContext;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\TaskState;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python
 * tests/server/agent_execution/test_simple_request_context_builder.py.
 */
final class SimpleRequestContextBuilderTest extends TestCase
{
    public function testBuildBasicContext(): void
    {
        $request = Fixtures::sendRequest(Fixtures::userMessage());
        $task = Fixtures::task('task-1', TaskState::TASK_STATE_WORKING, 'ctx-1');

        $context = (new SimpleRequestContextBuilder())->build(Fixtures::callContext(), $request, 'task-1', 'ctx-1', $task);

        self::assertSame('task-1', $context->taskId());
        self::assertSame('ctx-1', $context->contextId());
        self::assertSame($task, $context->currentTask());
        self::assertSame([], $context->relatedTasks());
    }

    public function testPopulatesReferencedTasks(): void
    {
        $store = new InMemoryTaskStore();
        $store->save(Fixtures::task('ref-1', TaskState::TASK_STATE_COMPLETED), Fixtures::callContext());
        $store->save(Fixtures::task('ref-2', TaskState::TASK_STATE_COMPLETED), Fixtures::callContext());
        $message = Fixtures::userMessage();
        $message->setReferenceTaskIds(['ref-1', 'missing', 'ref-2']);

        $context = (new SimpleRequestContextBuilder(true, $store))->build(Fixtures::callContext(), Fixtures::sendRequest($message));

        self::assertSame(['ref-1', 'ref-2'], array_map(static fn($t) => $t->getId(), $context->relatedTasks()));
    }

    public function testDoesNotPopulateWhenDisabledOrWithoutStore(): void
    {
        $message = Fixtures::userMessage();
        $message->setReferenceTaskIds(['ref-1']);
        $store = new InMemoryTaskStore();
        $store->save(Fixtures::task('ref-1', TaskState::TASK_STATE_COMPLETED), Fixtures::callContext());

        self::assertSame([], (new SimpleRequestContextBuilder(false, $store))->build(Fixtures::callContext(), Fixtures::sendRequest($message))->relatedTasks());
        self::assertSame([], (new SimpleRequestContextBuilder(true, null))->build(Fixtures::callContext(), Fixtures::sendRequest($message))->relatedTasks());
        self::assertSame([], (new SimpleRequestContextBuilder(true, $store))->build(Fixtures::callContext(), null)->relatedTasks());
    }

    public function testPassesCustomIdGenerators(): void
    {
        $taskIds = new class implements IdGenerator {
            public function generate(IdGeneratorContext $context): string
            {
                return 'custom-task';
            }
        };
        $contextIds = new class implements IdGenerator {
            public function generate(IdGeneratorContext $context): string
            {
                return 'custom-context';
            }
        };

        $context = (new SimpleRequestContextBuilder(taskIdGenerator: $taskIds, contextIdGenerator: $contextIds))
            ->build(Fixtures::callContext(), Fixtures::sendRequest(Fixtures::userMessage()));
        self::assertSame('custom-task', $context->taskId());
        self::assertSame('custom-context', $context->contextId());

        $provided = (new SimpleRequestContextBuilder(taskIdGenerator: $taskIds, contextIdGenerator: $contextIds))
            ->build(Fixtures::callContext(), Fixtures::sendRequest(Fixtures::userMessage()), 'given-task', 'given-context');
        self::assertSame('given-task', $provided->taskId());
        self::assertSame('given-context', $provided->contextId());
    }
}
