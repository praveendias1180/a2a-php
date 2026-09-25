<?php

/**
 * Proves a long task (20 s) runs on a queue worker, streams live to the
 * client while it runs, and survives the web request that started it.
 *
 *     php long-task-proof.php http://127.0.0.1:9998
 *
 * Run from the Laravel TCK app (its vendor/autoload.php is used).
 */

declare(strict_types=1);

require getcwd() . '/vendor/autoload.php';

use A2A\Client\ClientConfig;
use A2A\Client\ClientFactory;
use A2A\Types\GetTaskRequest;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\TaskState;

$base = rtrim($argv[1] ?? 'http://127.0.0.1:9998', '/') . '/long';
$message = static fn(string $text): Message => new Message(['message_id' => bin2hex(random_bytes(8)), 'role' => Role::ROLE_USER, 'parts' => [new Part(['text' => $text])]]);

echo "== 1. Streaming a 20 s task (events should arrive about every 2 s, live) ==\n";
$client = ClientFactory::createClient($base, new ClientConfig(streaming: true));
$start = microtime(true);
$taskId = '';
foreach ($client->sendMessage(new SendMessageRequest(['message' => $message('long 20')])) as $event) {
    $t = microtime(true) - $start;
    if ($event->hasTask()) {
        $taskId = $event->getTask()->getId();
        printf("%6.2fs  task %s (%s)\n", $t, $taskId, TaskState::name($event->getTask()->getStatus()?->getState() ?? 0));
    } elseif ($event->hasStatusUpdate()) {
        $status = $event->getStatusUpdate()->getStatus();
        $text = $status?->getMessage()?->getParts()[0]?->getText() ?? '';
        printf("%6.2fs  status %s %s\n", $t, TaskState::name($status?->getState() ?? 0), $text);
    } elseif ($event->hasArtifactUpdate()) {
        $parts = $event->getArtifactUpdate()->getArtifact()?->getParts();
        printf("%6.2fs  artifact: %s\n", $t, $parts !== null && count($parts) > 0 ? $parts[count($parts) - 1]->getText() : '');
    }
}
printf("stream ended after %.2fs\n\n", microtime(true) - $start);

echo "== 2. Start a 20 s task with returnImmediately, then poll it after the request has ended ==\n";
$blocking = ClientFactory::createClient($base, new ClientConfig(streaming: false));
$start = microtime(true);
$result = null;
foreach ($blocking->sendMessage(new SendMessageRequest([
    'message' => $message('long 20'),
    'configuration' => new SendMessageConfiguration(['return_immediately' => true]),
])) as $event) {
    $result = $event->getTask();
}
if ($result === null) {
    fwrite(STDERR, "No task came back.\n");
    exit(1);
}
printf("%6.2fs  SendMessage returned: task %s is %s (the HTTP request is over)\n", microtime(true) - $start, $result->getId(), TaskState::name($result->getStatus()?->getState() ?? 0));

$state = 0;
do {
    sleep(3);
    $task = $blocking->getTask(new GetTaskRequest(['id' => $result->getId()]));
    $state = $task->getStatus()?->getState() ?? 0;
    $artifact = $task->getArtifacts()[0] ?? null;
    $parts = $artifact?->getParts();
    printf("%6.2fs  GetTask: %s, progress: %s\n", microtime(true) - $start, TaskState::name($state), $parts !== null && count($parts) > 0 ? $parts[count($parts) - 1]->getText() : '-');
} while ($state !== TaskState::TASK_STATE_COMPLETED && $state !== TaskState::TASK_STATE_FAILED && microtime(true) - $start < 60);

$final = $task->getStatus()?->getMessage()?->getParts()[0]?->getText() ?? '';
printf("final: %s, \"%s\"\n", TaskState::name($state), $final);
exit($state === TaskState::TASK_STATE_COMPLETED ? 0 : 1);
