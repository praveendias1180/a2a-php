<?php

/**
 * The hello-world A2A agent, served with JSON-RPC and HTTP+JSON.
 * A port of the A2A Python SDK's samples/hello_world_agent.py.
 *
 *     PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:41241 examples/hello-world/server.php
 *
 * Then call it with examples/call-an-agent.php, the Python SDK's
 * samples/cli.py, or any A2A client.
 *
 * Like the Python sample, it also speaks A2A v0.3 (the v0.3 interfaces in the
 * card + enableV03Compat), so v0.3 clients such as a2a-sdk 0.3.x work too.
 *
 * Every PHP request is a separate process, so tasks and stream events are
 * kept in SQLite (A2A_DB, default: a file in the system temp dir). Several
 * workers let a stream stay open while other requests are served.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/HelloExecutor.php';

use A2A\Server\Events\PdoQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\ResponseEmitter;
use A2A\Server\Routes\Routes;
use A2A\Server\Routes\ServerRequestFactory;
use A2A\Server\Tasks\PdoTaskStore;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\AgentProvider;
use A2A\Types\AgentSkill;

$request = ServerRequestFactory::fromGlobals();
$baseUrl = getenv('A2A_PUBLIC_URL') ?: $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();

// --8<-- [start:card]
$agentCard = new AgentCard([
    'name' => 'Sample Agent',
    'description' => 'A sample agent to test the stream functionality.',
    'provider' => new AgentProvider(['organization' => 'A2A Samples', 'url' => 'https://example.com']),
    'version' => '1.0.0',
    'capabilities' => new AgentCapabilities(['streaming' => true, 'push_notifications' => false]),
    'default_input_modes' => ['text'],
    'default_output_modes' => ['text', 'task-status'],
    'skills' => [new AgentSkill([
        'id' => 'sample_agent',
        'name' => 'Sample Agent',
        'description' => 'Say hi.',
        'tags' => ['sample'],
        'examples' => ['hi'],
        'input_modes' => ['text'],
        'output_modes' => ['text', 'task-status'],
    ])],
    'supported_interfaces' => [
        new AgentInterface(['protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0', 'url' => $baseUrl . '/a2a/jsonrpc']),
        new AgentInterface(['protocol_binding' => 'HTTP+JSON', 'protocol_version' => '1.0', 'url' => $baseUrl . '/a2a/rest']),
        new AgentInterface(['protocol_binding' => 'JSONRPC', 'protocol_version' => '0.3', 'url' => $baseUrl . '/a2a/jsonrpc']),
        new AgentInterface(['protocol_binding' => 'HTTP+JSON', 'protocol_version' => '0.3', 'url' => $baseUrl . '/a2a/rest']),
    ],
]);
// --8<-- [end:card]

// --8<-- [start:serve]
$pdo = new PDO('sqlite:' . (getenv('A2A_DB') ?: sys_get_temp_dir() . '/a2a-php-hello-world.sqlite'));
$handler = new DefaultRequestHandler(
    agentExecutor: new HelloExecutor(),
    taskStore: new PdoTaskStore($pdo),
    agentCard: $agentCard,
    queueManager: new PdoQueueManager($pdo),
);

$router = Routes::router($handler, $agentCard, jsonRpcPath: '/a2a/jsonrpc', restPrefix: '/a2a/rest', enableV03Compat: true);
(new ResponseEmitter($handler))->emit($router->handle($request));
// --8<-- [end:serve]
