<?php

declare(strict_types=1);

namespace A2A\Tests\Server\AgentExecution;

use A2A\Server\AgentExecution\CancellationToken;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\IdGenerator;
use A2A\Server\IdGeneratorContext;
use A2A\Server\ServerCallContext;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\Part;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\TaskState;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\ProtoUtils;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/server/agent_execution/test_context.py.
 */
final class RequestContextTest extends TestCase
{
    public function testInitWithoutParams(): void
    {
        $context = new RequestContext(Fixtures::callContext());

        self::assertNull($context->message());
        self::assertNull($context->taskId());
        self::assertNull($context->contextId());
        self::assertNull($context->currentTask());
        self::assertSame([], $context->relatedTasks());
        self::assertSame('', $context->getUserInput());
        self::assertSame([], $context->metadata());
        self::assertNull($context->configuration());
    }

    public function testInitWithParamsNoIdsGeneratesBoth(): void
    {
        $request = Fixtures::sendRequest(Fixtures::userMessage());
        $context = new RequestContext(Fixtures::callContext(), $request);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $context->taskId());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $context->contextId());
        self::assertSame($context->taskId(), $request->getMessage()?->getTaskId(), 'ids are written back onto the message');
        self::assertSame($context->contextId(), $request->getMessage()?->getContextId());
    }

    public function testInitWithTaskIdAndContextId(): void
    {
        $request = Fixtures::sendRequest(Fixtures::userMessage());
        $context = new RequestContext(Fixtures::callContext(), $request, taskId: 'task-123', contextId: 'context-456');

        self::assertSame('task-123', $context->taskId());
        self::assertSame('context-456', $context->contextId());
        self::assertSame('task-123', $request->getMessage()?->getTaskId());
        self::assertSame('context-456', $request->getMessage()->getContextId());
    }

    public function testInitWithExistingIdsInMessage(): void
    {
        $context = new RequestContext(Fixtures::callContext(), Fixtures::sendRequest(Fixtures::userMessage('hi', 'm', 'existing-task', 'existing-ctx')));

        self::assertSame('existing-task', $context->taskId());
        self::assertSame('existing-ctx', $context->contextId());
    }

    public function testInitWithMatchingTask(): void
    {
        $task = Fixtures::task('task-123', TaskState::TASK_STATE_WORKING, 'context-456');
        $context = new RequestContext(Fixtures::callContext(), Fixtures::sendRequest(Fixtures::userMessage()), 'task-123', 'context-456', $task);

        self::assertSame($task, $context->currentTask());
    }

    public function testTaskIdMismatchRaises(): void
    {
        $this->expectException(InvalidParamsError::class);
        new RequestContext(Fixtures::callContext(), Fixtures::sendRequest(Fixtures::userMessage()), 'task-123', null, Fixtures::task('different', TaskState::TASK_STATE_WORKING));
    }

    public function testContextIdMismatchRaises(): void
    {
        $this->expectException(InvalidParamsError::class);
        new RequestContext(Fixtures::callContext(), Fixtures::sendRequest(Fixtures::userMessage()), 'task-123', 'context-456', Fixtures::task('task-123', TaskState::TASK_STATE_WORKING, 'different'));
    }

    public function testCustomIdGenerators(): void
    {
        $generator = new class implements IdGenerator {
            /** @var list<IdGeneratorContext> */
            public array $seen = [];

            public function generate(IdGeneratorContext $context): string
            {
                $this->seen[] = $context;

                return 'generated-' . count($this->seen);
            }
        };

        $context = new RequestContext(Fixtures::callContext(), Fixtures::sendRequest(Fixtures::userMessage()), taskIdGenerator: $generator, contextIdGenerator: $generator);

        self::assertSame('generated-1', $context->taskId());
        self::assertSame('generated-2', $context->contextId());
        self::assertSame('generated-1', $generator->seen[1]->taskId, 'the context id generator sees the new task id');
    }

    public function testGetUserInputJoinsTextParts(): void
    {
        $message = Fixtures::userMessage('first');
        $message->setParts([new Part(['text' => 'first']), new Part(['url' => 'https://x']), new Part(['text' => 'second'])]);
        $context = new RequestContext(Fixtures::callContext(), Fixtures::sendRequest($message));

        self::assertSame("first\nsecond", $context->getUserInput());
        self::assertSame('first | second', $context->getUserInput(' | '));
    }

    public function testRelatedTasksAndCurrentTask(): void
    {
        $related = Fixtures::task('related', TaskState::TASK_STATE_COMPLETED);
        $context = new RequestContext(Fixtures::callContext(), relatedTasks: [$related]);
        $context->attachRelatedTask(Fixtures::task('another', TaskState::TASK_STATE_COMPLETED));
        $context->setCurrentTask(Fixtures::task('current', TaskState::TASK_STATE_WORKING));

        self::assertCount(2, $context->relatedTasks());
        self::assertSame('current', $context->currentTask()?->getId());
    }

    public function testMetadataConfigurationAndCallContext(): void
    {
        $request = new SendMessageRequest([
            'message' => Fixtures::userMessage(),
            'metadata' => ProtoUtils::toStruct(['key' => 'value', 'nested' => ['a' => 1]]),
            'configuration' => new SendMessageConfiguration(['accepted_output_modes' => ['text/plain']]),
        ]);
        $call = new ServerCallContext(tenant: 'acme', requestedExtensions: ['https://ext/v1']);
        $context = new RequestContext($call, $request);

        self::assertSame(['key' => 'value', 'nested' => ['a' => 1]], $context->metadata());
        self::assertSame(['text/plain'], iterator_to_array($context->configuration()?->getAcceptedOutputModes() ?? []));
        self::assertSame($call, $context->callContext());
        self::assertSame('acme', $context->tenant());
        self::assertSame(['https://ext/v1'], $context->requestedExtensions());
        self::assertSame($request, $context->request());
    }

    public function testCancellation(): void
    {
        $context = new RequestContext(Fixtures::callContext());
        self::assertFalse($context->isCancelled());

        $flag = false;
        $context->setCancellationToken(new CancellationToken(static function () use (&$flag): bool {
            return $flag;
        }));
        self::assertFalse($context->isCancelled());

        $flag = true;
        self::assertTrue($context->isCancelled());
        $flag = false;
        self::assertTrue($context->isCancelled(), 'once cancelled, always cancelled');
    }
}
