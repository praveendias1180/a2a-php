<?php

declare(strict_types=1);

namespace A2A\Tests\Client;

use A2A\Client\BaseClient;
use A2A\Client\ClientCallContext;
use A2A\Client\ClientCallInterceptor;
use A2A\Client\ClientConfig;
use A2A\Tests\Client\Support\RecordingTransport;
use A2A\Tests\Client\Support\SpyInterceptor;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\GetTaskRequest;
use A2A\Types\Message;
use A2A\Types\SendMessageRequest;
use A2A\Types\StreamResponse;
use A2A\Types\Task;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/test_base_client_interceptors.py, through the public
 * API (Python calls the private _execute_* helpers directly).
 */
final class BaseClientInterceptorsTest extends TestCase
{
    public function testExecuteWithInterceptorsNormalFlow(): void
    {
        $transport = new RecordingTransport();
        $interceptor = new SpyInterceptor();
        $client = self::client($transport, [$interceptor]);
        $context = new ClientCallContext();
        $request = new GetTaskRequest(['id' => 't']);

        $result = $client->getTask($request, $context);

        self::assertSame($transport->task, $result);
        self::assertCount(1, $interceptor->before);
        self::assertSame($request, $interceptor->before[0]->input);
        self::assertSame($context, $interceptor->before[0]->context);
        self::assertSame(['getTask'], $transport->methods());
        self::assertSame($context, $transport->calls[0][2]);
        self::assertCount(1, $interceptor->after);
        self::assertSame('get_task', $interceptor->after[0]->method);
        self::assertSame($transport->task, $interceptor->after[0]->result);
        self::assertSame($context, $interceptor->after[0]->context);
    }

    public function testExecuteWithInterceptorsEarlyReturn(): void
    {
        $transport = new RecordingTransport();
        $early = new Task(['id' => 'early']);
        $interceptor = new SpyInterceptor(earlyReturn: $early);
        $context = new ClientCallContext();

        $result = self::client($transport, [$interceptor])->getTask(new GetTaskRequest(['id' => 't']), $context);

        self::assertSame($early, $result);
        self::assertCount(1, $interceptor->before);
        self::assertSame([], $transport->calls, 'the transport is not called');
        self::assertCount(1, $interceptor->after);
        self::assertSame($early, $interceptor->after[0]->result);
        self::assertSame($context, $interceptor->after[0]->context);
    }

    public function testEarlyReturnOnlyRunsAfterOnInterceptorsThatRanBefore(): void
    {
        $first = new SpyInterceptor();
        $second = new SpyInterceptor(earlyReturn: new Task(['id' => 'early']));
        $third = new SpyInterceptor();

        self::client(new RecordingTransport(), [$first, $second, $third])->getTask(new GetTaskRequest());

        self::assertCount(1, $first->after);
        self::assertCount(1, $second->after);
        self::assertSame([], $third->before);
        self::assertSame([], $third->after);
    }

    public function testAfterRunsInReverseOrderAndCanStopTheChain(): void
    {
        $order = [];
        $outer = new SpyInterceptor(log: $order, name: 'outer');
        $inner = new SpyInterceptor(log: $order, name: 'inner', stopAfter: true);

        self::client(new RecordingTransport(), [$outer, $inner])->getTask(new GetTaskRequest());

        self::assertSame(['before:outer', 'before:inner', 'after:inner'], $order);
    }

    public function testInterceptorCanReplaceTheContext(): void
    {
        $transport = new RecordingTransport();
        $replacement = new ClientCallContext(serviceParameters: ['X-Test' => '1']);
        $interceptor = new SpyInterceptor(replaceContext: $replacement);

        self::client($transport, [$interceptor])->getTask(new GetTaskRequest());

        self::assertSame($replacement, $transport->calls[0][2]);
    }

    public function testExecuteStreamWithInterceptorsNormalFlow(): void
    {
        $transport = new RecordingTransport();
        $transport->streamEvents = [new StreamResponse(['message' => new Message(['message_id' => '1'])])];
        $interceptor = new SpyInterceptor();
        $context = new ClientCallContext();
        $request = new SendMessageRequest();

        $events = iterator_to_array(self::client($transport, [$interceptor])->sendMessage($request, $context), false);

        self::assertCount(1, $events);
        self::assertCount(1, $interceptor->before);
        self::assertSame($request, $interceptor->before[0]->input);
        self::assertSame($context, $interceptor->before[0]->context);
        self::assertCount(1, $interceptor->after);
        self::assertSame('send_message_streaming', $interceptor->after[0]->method);
    }

    public function testExecuteStreamWithInterceptorsEarlyReturn(): void
    {
        $transport = new RecordingTransport();
        $early = new StreamResponse(['message' => new Message(['message_id' => '2'])]);
        $interceptor = new SpyInterceptor(earlyReturn: $early);
        $context = new ClientCallContext();

        $events = iterator_to_array(self::client($transport, [$interceptor])->sendMessage(new SendMessageRequest(), $context), false);

        self::assertSame([$early], $events);
        self::assertSame([], $transport->calls);
        self::assertCount(1, $interceptor->after);
        self::assertSame('send_message_streaming', $interceptor->after[0]->method);
        self::assertSame($context, $interceptor->after[0]->context);
    }

    public function testAddInterceptor(): void
    {
        $client = self::client(new RecordingTransport(), []);
        $interceptor = new SpyInterceptor();

        $client->addInterceptor($interceptor);
        $client->getTask(new GetTaskRequest());

        self::assertCount(1, $interceptor->before);
    }

    /**
     * @param list<ClientCallInterceptor> $interceptors
     */
    private static function client(RecordingTransport $transport, array $interceptors): BaseClient
    {
        $card = new AgentCard(['name' => 'a', 'capabilities' => new AgentCapabilities(['streaming' => true])]);

        return new BaseClient($card, new ClientConfig(), $transport, $interceptors);
    }
}
