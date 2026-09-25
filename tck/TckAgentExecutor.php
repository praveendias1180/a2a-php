<?php

/**
 * The executor behind the A2A TCK system under test, ported from the a2a-tck
 * generated Python SUT (sut/a2a-python/sut_agent.py). Its behaviour is
 * chosen by the messageId prefix the TCK sends.
 *
 * Shared by tck/sut-agent.php (plain PHP) and the Laravel TCK app built by
 * scripts/run-tck-laravel.sh. Not part of the published package.
 */

declare(strict_types=1);

use A2A\Helpers\ProtoHelpers;
use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\Part;
use A2A\Utils\Errors\A2AError;
use A2A\Utils\ProtoUtils;

final class TckAgentExecutor implements AgentExecutor
{
    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        $taskId = $context->taskId();
        $contextId = $context->contextId();
        if ($taskId === null || $contextId === null) {
            return;
        }

        $updater = new TaskUpdater($eventQueue, $taskId, $contextId);
        $message = $context->message();
        if ($message === null) {
            $updater->complete($updater->newAgentMessage([new Part(['text' => 'No message provided'])]));

            return;
        }

        if ($context->currentTask() === null) {
            $eventQueue->enqueueEvent(ProtoHelpers::newTaskFromUserMessage($message));
        }

        $messageId = $message->getMessageId();
        $is = static fn(string $prefix): bool => str_starts_with($messageId, $prefix);

        if ($is('tck-stream-artifact-chunked')) {
            $updater->startWork();
            $updater->addArtifact([new Part(['text' => 'chunk-1 '])], append: true);
            $updater->addArtifact([new Part(['text' => 'chunk-2'])], append: true, lastChunk: true);
            $updater->complete();

            return;
        }
        if ($is('test-resubscribe-message-id')) {
            $updater->startWork();
            sleep(4);
            $updater->complete();

            return;
        }
        if ($is('tck-stream-artifact-text')) {
            $updater->startWork();
            $updater->addArtifact([new Part(['text' => 'Streamed text content'])]);
            $updater->complete();

            return;
        }
        if ($is('tck-stream-artifact-file')) {
            $updater->startWork();
            $updater->addArtifact([new Part(['raw' => 'tck', 'media_type' => 'text/plain', 'filename' => 'output.txt'])]);
            $updater->complete();

            return;
        }
        if ($is('tck-stream-ordering-001')) {
            $updater->startWork();
            $updater->addArtifact([new Part(['text' => 'Ordered output'])]);
            $updater->complete();

            return;
        }
        if ($is('tck-artifact-file-url')) {
            $updater->addArtifact([new Part(['url' => 'https://example.com/output.txt', 'media_type' => 'text/plain', 'filename' => 'output.txt'])]);
            $updater->complete();

            return;
        }
        if ($is('tck-message-response')) {
            $eventQueue->enqueueEvent($updater->newAgentMessage([new Part(['text' => 'Direct message response'])]));

            return;
        }
        if ($is('tck-input-required')) {
            $updater->requiresInput();

            return;
        }
        if ($is('tck-complete-task')) {
            $updater->complete($updater->newAgentMessage([new Part(['text' => 'Hello from TCK'])]));

            return;
        }
        if ($is('tck-artifact-text')) {
            $updater->addArtifact([new Part(['text' => 'Generated text content'])]);
            $updater->complete();

            return;
        }
        if ($is('tck-artifact-file')) {
            $updater->addArtifact([new Part(['raw' => 'tck', 'media_type' => 'text/plain', 'filename' => 'output.txt'])]);
            $updater->complete();

            return;
        }
        if ($is('tck-artifact-data')) {
            $updater->addArtifact([new Part(['data' => ProtoUtils::toValue(['key' => 'value', 'count' => 42])])]);
            $updater->complete();

            return;
        }
        if ($is('tck-reject-task')) {
            throw new A2AError('rejected');
        }
        if ($is('tck-stream-001')) {
            $updater->startWork();
            $updater->addArtifact([new Part(['text' => 'Stream hello from TCK'])]);
            $updater->complete();

            return;
        }
        if ($is('tck-stream-002')) {
            $updater->complete();

            return;
        }
        if ($is('tck-stream-003')) {
            $updater->startWork();
            $updater->addArtifact([new Part(['text' => 'Stream task lifecycle'])]);
            $updater->complete();

            return;
        }

        $updater->complete($updater->newAgentMessage([new Part(['text' => 'Unhandled messageId prefix: ' . $messageId])]));
    }

    public function cancel(RequestContext $context, EventQueue $eventQueue): void
    {
        $taskId = $context->taskId();
        $contextId = $context->contextId();
        if ($taskId === null || $contextId === null) {
            return;
        }
        (new TaskUpdater($eventQueue, $taskId, $contextId))->cancel();
    }
}
