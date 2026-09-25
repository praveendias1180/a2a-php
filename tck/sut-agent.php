<?php

/**
 * A2A TCK system under test (SUT), ported from the a2a-tck generated Python
 * SUT (sut/a2a-python/sut_agent.py). The executor's behaviour is chosen by
 * the messageId prefix the TCK sends.
 *
 * Run it with PHP's built-in server. Each request runs in its own process,
 * so the tasks and events live in SQLite, and several workers are needed
 * because the TCK keeps streams open while it sends other requests:
 *
 *     PHP_CLI_SERVER_WORKERS=32 SUT_HOST=localhost:9999 php -S 0.0.0.0:9999 tck/sut-agent.php
 *
 * Then, from an a2a-tck checkout:
 *
 *     ./run_tck.py --sut-host http://localhost:9999 --transport jsonrpc,http_json --level must
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use A2A\Helpers\ProtoHelpers;
use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Events\PdoQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\ResponseEmitter;
use A2A\Server\Routes\Routes;
use A2A\Server\Routes\ServerRequestFactory;
use A2A\Server\Tasks\PdoTaskStore;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\AgentProvider;
use A2A\Types\AgentSkill;
use A2A\Types\Part;
use A2A\Utils\Errors\A2AError;
use A2A\Utils\ProtoUtils;

const REST_URL = '/a2a/rest';

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

$env = static fn(string $name, string $default): string => ($v = getenv($name)) !== false && $v !== '' ? $v : $default;
$host = $env('SUT_HOST', 'localhost:9999');
$database = $env('A2A_SUT_DB', sys_get_temp_dir() . '/a2a-php-tck-sut.sqlite');

$agentCard = new AgentCard([
    'name' => 'A2A PHP SDK System Under Test (SUT)',
    'description' => 'System Under Test for A2A TCK conformance, built on praveendias1180/a2a-php',
    'version' => '1.0.0',
    'provider' => new AgentProvider(['organization' => 'a2a-php', 'url' => 'https://github.com/praveendias1180/a2a-php']),
    'supported_interfaces' => [
        new AgentInterface(['url' => "http://{$host}", 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0']),
        new AgentInterface(['url' => "http://{$host}" . REST_URL, 'protocol_binding' => 'HTTP+JSON', 'protocol_version' => '1.0']),
    ],
    'capabilities' => new AgentCapabilities(['streaming' => true, 'push_notifications' => false]),
    'default_input_modes' => ['text'],
    'default_output_modes' => ['text'],
    'skills' => [new AgentSkill([
        'id' => 'tck',
        'name' => 'TCK Conformance',
        'description' => 'Handles TCK conformance test messages',
        'tags' => ['tck'],
    ])],
]);

$pdo = new PDO('sqlite:' . $database);
$handler = new DefaultRequestHandler(
    agentExecutor: new TckAgentExecutor(),
    taskStore: new PdoTaskStore($pdo),
    agentCard: $agentCard,
    queueManager: new PdoQueueManager($pdo),
    keepAliveSeconds: 2.0,
    maxSubscribeIdleSeconds: 12.0,
);

$router = Routes::router($handler, $agentCard, jsonRpcPath: '/', restPrefix: REST_URL);
(new ResponseEmitter($handler))->emit($router->handle(ServerRequestFactory::fromGlobals()));
