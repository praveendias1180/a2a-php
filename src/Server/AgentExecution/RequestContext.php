<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

use A2A\Helpers\ProtoHelpers;
use A2A\Server\IdGenerator;
use A2A\Server\IdGeneratorContext;
use A2A\Server\ServerCallContext;
use A2A\Server\UuidGenerator;
use A2A\Types\Message;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\Task;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\ProtoUtils;

/**
 * Everything an executor needs about the current request: the message, the
 * task and context ids (generated when the client sent none), the current
 * task if it already exists, and the call context.
 *
 * Mirrors a2a-python: RequestContext in
 * src/a2a/server/agent_execution/context.py. PHP adds isCancelled().
 */
final class RequestContext
{
    private ?string $taskId;

    private ?string $contextId;

    private readonly IdGenerator $taskIdGenerator;

    private readonly IdGenerator $contextIdGenerator;

    private CancellationToken $cancellation;

    /**
     * @param list<Task> $relatedTasks
     */
    public function __construct(
        private readonly ServerCallContext $callContext,
        private readonly ?SendMessageRequest $request = null,
        ?string $taskId = null,
        ?string $contextId = null,
        private ?Task $currentTask = null,
        private array $relatedTasks = [],
        ?IdGenerator $taskIdGenerator = null,
        ?IdGenerator $contextIdGenerator = null,
    ) {
        $this->taskId = $taskId;
        $this->contextId = $contextId;
        $this->taskIdGenerator = $taskIdGenerator ?? new UuidGenerator();
        $this->contextIdGenerator = $contextIdGenerator ?? new UuidGenerator();
        $this->cancellation = CancellationToken::none();

        $message = $this->request?->getMessage();
        if ($message === null) {
            return;
        }
        if ($taskId !== null && $taskId !== '') {
            $message->setTaskId($taskId);
            if ($currentTask !== null && $currentTask->getId() !== $taskId) {
                throw new InvalidParamsError('bad task id');
            }
        } else {
            $this->checkOrGenerateTaskId($message);
        }
        if ($contextId !== null && $contextId !== '') {
            $message->setContextId($contextId);
            if ($currentTask !== null && $currentTask->getContextId() !== $contextId) {
                throw new InvalidParamsError('bad context id');
            }
        } else {
            $this->checkOrGenerateContextId($message);
        }
    }

    /**
     * The text parts of the user's message, joined by $delimiter.
     */
    public function getUserInput(string $delimiter = "\n"): string
    {
        $message = $this->message();

        return $message === null ? '' : ProtoHelpers::getMessageText($message, $delimiter);
    }

    public function attachRelatedTask(Task $task): void
    {
        $this->relatedTasks[] = $task;
    }

    public function message(): ?Message
    {
        return $this->request?->getMessage();
    }

    public function request(): ?SendMessageRequest
    {
        return $this->request;
    }

    /**
     * @return list<Task>
     */
    public function relatedTasks(): array
    {
        return $this->relatedTasks;
    }

    public function currentTask(): ?Task
    {
        return $this->currentTask;
    }

    public function setCurrentTask(?Task $task): void
    {
        $this->currentTask = $task;
    }

    public function taskId(): ?string
    {
        return $this->taskId;
    }

    public function contextId(): ?string
    {
        return $this->contextId;
    }

    public function configuration(): ?SendMessageConfiguration
    {
        return $this->request?->getConfiguration();
    }

    public function callContext(): ServerCallContext
    {
        return $this->callContext;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $metadata = $this->request?->getMetadata();

        return $metadata === null ? [] : ProtoUtils::fromStruct($metadata);
    }

    public function tenant(): string
    {
        return $this->callContext->tenant;
    }

    /**
     * @return list<string>
     */
    public function requestedExtensions(): array
    {
        return $this->callContext->requestedExtensions;
    }

    /**
     * True once the task was cancelled, even by a request in another
     * process. Check it in long loops and stop early. PHP-specific.
     */
    public function isCancelled(): bool
    {
        return $this->cancellation->isCancelled();
    }

    public function cancellationToken(): CancellationToken
    {
        return $this->cancellation;
    }

    /**
     * @internal set by the SDK before execute() runs
     */
    public function setCancellationToken(CancellationToken $token): void
    {
        $this->cancellation = $token;
    }

    private function checkOrGenerateTaskId(Message $message): void
    {
        if (($this->taskId === null || $this->taskId === '') && $message->getTaskId() === '') {
            $message->setTaskId($this->taskIdGenerator->generate(new IdGeneratorContext(contextId: $this->contextId)));
        }
        if ($message->getTaskId() !== '') {
            $this->taskId = $message->getTaskId();
        }
    }

    private function checkOrGenerateContextId(Message $message): void
    {
        if (($this->contextId === null || $this->contextId === '') && $message->getContextId() === '') {
            $message->setContextId($this->contextIdGenerator->generate(new IdGeneratorContext(taskId: $this->taskId)));
        }
        if ($message->getContextId() !== '') {
            $this->contextId = $message->getContextId();
        }
    }
}
