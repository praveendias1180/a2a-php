<?php

declare(strict_types=1);

/*
 * Micro-benchmarks for the SDK's hot paths. No extra dependencies.
 *
 *   php scripts/benchmark.php            # default: 2 s per case
 *   php scripts/benchmark.php --seconds=5
 *
 * Results depend on the machine; compare runs on the same box. The numbers on
 * docs/reference/performance.md come from this script.
 */

require __DIR__ . '/../vendor/autoload.php';

use A2A\Client\Sse\EventStreamParser;
use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\JsonRpcDispatcher;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\Artifact;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\Task;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use Nyholm\Psr7\Factory\Psr17Factory;

$seconds = 2.0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--seconds=')) {
        $seconds = (float) substr($arg, 10);
    }
}

/**
 * Runs $fn repeatedly for about $seconds and returns operations per second.
 */
function bench(string $name, float $seconds, Closure $fn): void
{
    for ($i = 0; $i < 50; $i++) {
        $fn(); // warm up
    }
    $ops = 0;
    $start = hrtime(true);
    $deadline = $start + (int) ($seconds * 1e9);
    do {
        for ($i = 0; $i < 20; $i++) {
            $fn();
        }
        $ops += 20;
        $now = hrtime(true);
    } while ($now < $deadline);
    $elapsed = ($now - $start) / 1e9;
    printf("%-52s %12s ops/s %10.1f µs/op\n", $name, number_format($ops / $elapsed), $elapsed / $ops * 1e6);
}

// --- A realistic task: 10 history messages, one artifact with 3 parts -------
$history = [];
for ($i = 0; $i < 10; $i++) {
    $history[] = new Message([
        'message_id' => sprintf('m-%02d', $i),
        'context_id' => 'ctx-1',
        'task_id' => 'task-1',
        'role' => $i % 2 === 0 ? Role::ROLE_USER : Role::ROLE_AGENT,
        'parts' => [new Part(['text' => str_repeat('Lorem ipsum dolor sit amet. ', 8)])],
    ]);
}
$task = new Task([
    'id' => 'task-1',
    'context_id' => 'ctx-1',
    'status' => new TaskStatus(['state' => TaskState::TASK_STATE_COMPLETED]),
    'history' => $history,
    'artifacts' => [new Artifact([
        'artifact_id' => 'a-1',
        'name' => 'response',
        'parts' => [
            new Part(['text' => str_repeat('result ', 40)]),
            new Part(['url' => 'https://example.com/report.pdf', 'media_type' => 'application/pdf', 'filename' => 'report.pdf']),
            new Part(['text' => 'done']),
        ],
    ])],
]);
$taskJson = $task->serializeToJsonString();

// --- An in-process server: trivial executor, in-memory stores ----------------
$executor = new class implements AgentExecutor {
    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        $taskId = (string) $context->taskId();
        $contextId = (string) $context->contextId();
        // Same shape as examples/hello-world: publish the Task first.
        $eventQueue->enqueueEvent(new Task([
            'id' => $taskId,
            'context_id' => $contextId,
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_SUBMITTED]),
            'history' => $context->message() !== null ? [$context->message()] : [],
        ]));
        $updater = new TaskUpdater($eventQueue, $taskId, $contextId);
        $updater->startWork();
        $updater->addArtifact([new Part(['text' => 'Hello, ' . $context->getUserInput()])], name: 'response', lastChunk: true);
        $updater->complete();
    }

    public function cancel(RequestContext $context, EventQueue $eventQueue): void
    {
        (new TaskUpdater($eventQueue, (string) $context->taskId(), (string) $context->contextId()))->cancel();
    }
};
$card = new AgentCard([
    'name' => 'Bench', 'description' => 'benchmark agent', 'version' => '1.0.0',
    'capabilities' => new AgentCapabilities(['streaming' => true]),
    'default_input_modes' => ['text/plain'], 'default_output_modes' => ['text/plain'],
    'supported_interfaces' => [new AgentInterface(['protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0', 'url' => 'http://127.0.0.1/a2a'])],
]);
$handler = new DefaultRequestHandler(
    agentExecutor: $executor,
    taskStore: new InMemoryTaskStore(),
    agentCard: $card,
    queueManager: new InMemoryQueueManager(),
);
$dispatcher = new JsonRpcDispatcher($handler);
$psr17 = new Psr17Factory();
$n = 0;
$rpc = static function (string $method) use ($psr17, &$n): Psr\Http\Message\ServerRequestInterface {
    $n++;
    $body = json_encode([
        'jsonrpc' => '2.0', 'id' => $n, 'method' => $method,
        'params' => ['message' => ['messageId' => "bench-$n", 'role' => 'ROLE_USER', 'parts' => [['text' => 'bench']]]],
    ], JSON_THROW_ON_ERROR);

    return $psr17->createServerRequest('POST', 'http://127.0.0.1/a2a')
        ->withHeader('A2A-Version', '1.0')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($psr17->createStream($body));
};

// One captured SSE body, reused by the parse-only case.
$sseBody = (string) $dispatcher->handle($rpc('SendStreamingMessage'))->getBody();
$sseEvents = count((new EventStreamParser())->feed($sseBody));
if (getenv("BENCH_DEBUG")) { fwrite(STDERR, $sseBody . "\n"); }

printf("A2A PHP SDK benchmark: PHP %s, protobuf runtime: %s, %s, %s\n", PHP_VERSION, extension_loaded('protobuf') ? 'ext-protobuf ' . phpversion('protobuf') : 'pure PHP (google/protobuf)', php_uname('m'), trim((string) @file_get_contents('/proc/cpuinfo') !== '' ? (preg_match('/model name\s*:\s*(.+)/', (string) @file_get_contents('/proc/cpuinfo'), $m) ? $m[1] : '') : ''));
printf("Task payload: %d bytes of ProtoJSON; SSE body: %d events, %d bytes\n\n", strlen($taskJson), $sseEvents, strlen($sseBody));

bench('ProtoJSON encode Task (serializeToJsonString)', $seconds, static function () use ($task): void {
    $task->serializeToJsonString();
});
bench('ProtoJSON decode Task (mergeFromJsonString)', $seconds, static function () use ($taskJson): void {
    (new Task())->mergeFromJsonString($taskJson);
});
bench("SSE parse ($sseEvents events, EventStreamParser)", $seconds, static function () use ($sseBody): void {
    $parser = new EventStreamParser();
    $parser->feed($sseBody);
    $parser->finish();
});
$check = (string) $dispatcher->handle($rpc('SendMessage'))->getBody();
if (!str_contains($check, 'TASK_STATE_COMPLETED') || $sseEvents < 3) {
    fwrite(STDERR, "The benchmark agent did not complete a task; refusing to time an error path:\n$check\n$sseBody\n");
    exit(1);
}

bench('JSON-RPC SendMessage, full request (in-memory)', $seconds, static function () use ($dispatcher, $rpc): void {
    (string) $dispatcher->handle($rpc('SendMessage'))->getBody();
});
bench('JSON-RPC SendStreamingMessage, full SSE body', $seconds, static function () use ($dispatcher, $rpc): void {
    (string) $dispatcher->handle($rpc('SendStreamingMessage'))->getBody();
});
