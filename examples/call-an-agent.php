<?php

// Calls an A2A agent and prints what it streams back.
//
//     php examples/call-an-agent.php https://agent.example.com "hello"
//
// CI runs this against the official Python SDK's sample agent
// (scripts/run-python-interop.sh).

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use A2A\Client\ClientFactory;
use A2A\Helpers\ProtoHelpers;
use A2A\Types\Role;
use A2A\Types\SendMessageRequest;
use A2A\Types\TaskState;

$agentUrl = $argv[1] ?? 'http://127.0.0.1:41241';
$text = $argv[2] ?? 'hello';

// Fetches the agent card and picks a transport both sides support.
$client = ClientFactory::createClient($agentUrl);

$request = new SendMessageRequest([
    'message' => ProtoHelpers::newTextMessage($text, role: Role::ROLE_USER),
]);

// Streams if the agent supports it; either way you get the same events.
foreach ($client->sendMessage($request) as $event) {
    if ($event->hasTask()) {
        echo 'task ', $event->getTask()->getId(), PHP_EOL;
    } elseif ($event->hasStatusUpdate()) {
        echo 'status ', TaskState::name($event->getStatusUpdate()->getStatus()->getState()), PHP_EOL;
    } elseif ($event->hasArtifactUpdate()) {
        echo 'artifact ', ProtoHelpers::getArtifactText($event->getArtifactUpdate()->getArtifact()), PHP_EOL;
    } elseif ($event->hasMessage()) {
        echo 'message ', ProtoHelpers::getMessageText($event->getMessage()), PHP_EOL;
    }
}
