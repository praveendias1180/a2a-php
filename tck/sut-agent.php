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
require __DIR__ . '/TckAgentExecutor.php';

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

const REST_URL = '/a2a/rest';

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
