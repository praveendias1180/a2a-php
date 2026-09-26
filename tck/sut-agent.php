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
 *
 * A2A_SUT_PROFILE picks the capabilities, because some TCK requirements
 * are mutually exclusive (e.g. "push operations fail when unsupported" vs
 * "push notifications are delivered"), and the TCK client never sends
 * A2A-Extensions, so a required extension would fail every other test:
 *
 * - minimal (default): streaming only;
 * - full: + push notifications (webhooks on localhost allowed, since the
 *   TCK's receiver runs there) + an extended card that is declared but not
 *   configured (CARD-EXT-002);
 * - required-extension: + urn:a2a:tck:required-extension marked required
 *   (run only the CORE-CAP-004 tests against it).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/TckAgentExecutor.php';

use A2A\Server\Events\PdoQueueManager;
use A2A\Server\Tasks\BasePushNotificationSender;
use A2A\Server\Tasks\PdoPushNotificationConfigStore;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\ResponseEmitter;
use A2A\Server\Routes\Routes;
use A2A\Server\Routes\ServerRequestFactory;
use A2A\Server\Tasks\PdoTaskStore;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentExtension;
use A2A\Types\AgentInterface;
use A2A\Types\AgentProvider;
use A2A\Types\AgentSkill;
use A2A\Utils\PushUrlValidator;

const REST_URL = '/a2a/rest';

$env = static fn(string $name, string $default): string => ($v = getenv($name)) !== false && $v !== '' ? $v : $default;
$host = $env('SUT_HOST', 'localhost:9999');
$database = $env('A2A_SUT_DB', sys_get_temp_dir() . '/a2a-php-tck-sut.sqlite');
$profile = $env('A2A_SUT_PROFILE', 'minimal');
if (!in_array($profile, ['minimal', 'full', 'required-extension'], true)) {
    http_response_code(500);
    exit("Unknown A2A_SUT_PROFILE {$profile}\n");
}
$push = $profile === 'full';

$capabilities = new AgentCapabilities(['streaming' => true, 'push_notifications' => $push]);
if ($profile === 'full') {
    $capabilities->setExtendedAgentCard(true);
}
if ($profile === 'required-extension') {
    $capabilities->setExtensions([new AgentExtension([
        'uri' => 'urn:a2a:tck:required-extension',
        'description' => 'A required extension the TCK does not request (CORE-CAP-004)',
        'required' => true,
    ])]);
}

$agentCard = new AgentCard([
    'name' => 'A2A PHP SDK System Under Test (SUT)',
    'description' => 'System Under Test for A2A TCK conformance, built on praveendias1180/a2a-php',
    'version' => '1.0.0',
    'provider' => new AgentProvider(['organization' => 'a2a-php', 'url' => 'https://github.com/praveendias1180/a2a-php']),
    'supported_interfaces' => [
        new AgentInterface(['url' => "http://{$host}", 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0']),
        new AgentInterface(['url' => "http://{$host}" . REST_URL, 'protocol_binding' => 'HTTP+JSON', 'protocol_version' => '1.0']),
    ],
    'capabilities' => $capabilities,
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
$pushStore = $push ? new PdoPushNotificationConfigStore($pdo) : null;
// The TCK's webhook receiver listens on localhost; everything else must
// still resolve to a public address.
$pushUrls = new PushUrlValidator(allowedHosts: ['localhost', '127.0.0.1', '::1']);
$handler = new DefaultRequestHandler(
    agentExecutor: new TckAgentExecutor(),
    taskStore: new PdoTaskStore($pdo),
    agentCard: $agentCard,
    queueManager: new PdoQueueManager($pdo),
    pushConfigStore: $pushStore,
    pushUrlValidator: $push ? $pushUrls : null,
    keepAliveSeconds: 2.0,
    maxSubscribeIdleSeconds: 12.0,
    pushSender: $pushStore === null ? null : new BasePushNotificationSender(
        $pushStore,
        pushUrlValidator: $pushUrls,
        maxAttempts: 2,
        timeoutSeconds: 2.0,
    ),
);

$router = Routes::router($handler, $agentCard, jsonRpcPath: '/', restPrefix: REST_URL);
(new ResponseEmitter($handler))->emit($router->handle(ServerRequestFactory::fromGlobals()));
