<?php

declare(strict_types=1);

use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\Part;
use A2A\Types\Task;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;

/**
 * The hello-world agent, ported line for line from the A2A Python SDK's
 * samples/hello_world_agent.py (SampleAgentExecutor).
 */
final class HelloExecutor implements AgentExecutor
{
    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        $userMessage = $context->message();
        $taskId = $context->taskId();
        $contextId = $context->contextId();
        if ($userMessage === null || $taskId === null || $contextId === null) {
            return;
        }

        $eventQueue->enqueueEvent(new Task([
            'id' => $taskId,
            'context_id' => $contextId,
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_SUBMITTED]),
            'history' => [$userMessage],
        ]));

        $updater = new TaskUpdater($eventQueue, $taskId, $contextId);
        $updater->startWork($updater->newAgentMessage([new Part(['text' => 'Processing your question...'])]));

        $reply = $this->parseInput($context->getUserInput());
        sleep(1);

        // Python tracks running tasks in a set; a PHP request can be
        // cancelled from another process, so ask the context instead.
        if ($context->isCancelled()) {
            return;
        }

        $updater->addArtifact([new Part(['text' => $reply])], name: 'response', lastChunk: true);
        $updater->complete();
    }

    public function cancel(RequestContext $context, EventQueue $eventQueue): void
    {
        (new TaskUpdater($eventQueue, (string) $context->taskId(), (string) $context->contextId()))->cancel();
    }

    private function parseInput(string $query): string
    {
        if ($query === '') {
            return 'Hello! Please provide a message for me to respond to.';
        }
        $q = strtolower($query);
        if (str_contains($q, 'hello') || str_contains($q, 'hi')) {
            return 'Hello World! Nice to meet you!';
        }
        if (str_contains($q, 'how are you')) {
            return "I'm doing great! Thanks for asking. How can I help you today?";
        }
        if (str_contains($q, 'goodbye') || str_contains($q, 'bye')) {
            return 'Goodbye! Have a wonderful day!';
        }

        return "Hello World! You said: '{$query}'. Thanks for your message!";
    }
}
