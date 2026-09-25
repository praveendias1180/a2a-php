<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Transports;

use A2A\Client\Transports\TenantTransportDecorator;
use A2A\Tests\Client\Support\RecordingTransport;
use A2A\Types\CancelTaskRequest;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\SendMessageRequest;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\TaskPushNotificationConfig;
use Google\Protobuf\Internal\Message as ProtobufMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/transports/test_tenant_decorator.py
 */
final class TenantTransportDecoratorTest extends TestCase
{
    public function testResolveTenantLogic(): void
    {
        $base = new RecordingTransport();
        $decorator = new TenantTransportDecorator($base, 'default-tenant');

        $explicit = new GetTaskRequest(['id' => 't', 'tenant' => 'explicit-tenant']);
        $decorator->getTask($explicit);
        self::assertSame('explicit-tenant', $explicit->getTenant());

        $implicit = new GetTaskRequest(['id' => 't']);
        $decorator->getTask($implicit);
        self::assertSame('default-tenant', $implicit->getTenant());
    }

    public function testResolveTenantLogicEmptyTenant(): void
    {
        $decorator = new TenantTransportDecorator(new RecordingTransport(), '');
        $request = new GetTaskRequest(['id' => 't']);

        $decorator->getTask($request);

        self::assertSame('', $request->getTenant());
    }

    /**
     * @return iterable<string, array{string, ProtobufMessage}>
     */
    public static function methods(): iterable
    {
        yield 'sendMessage' => ['sendMessage', new SendMessageRequest()];
        yield 'getTask' => ['getTask', new GetTaskRequest()];
        yield 'listTasks' => ['listTasks', new ListTasksRequest()];
        yield 'cancelTask' => ['cancelTask', new CancelTaskRequest()];
        yield 'createTaskPushNotificationConfig' => ['createTaskPushNotificationConfig', new TaskPushNotificationConfig()];
        yield 'getTaskPushNotificationConfig' => ['getTaskPushNotificationConfig', new GetTaskPushNotificationConfigRequest()];
        yield 'listTaskPushNotificationConfigs' => ['listTaskPushNotificationConfigs', new ListTaskPushNotificationConfigsRequest()];
        yield 'deleteTaskPushNotificationConfig' => ['deleteTaskPushNotificationConfig', new DeleteTaskPushNotificationConfigRequest()];
        yield 'getExtendedAgentCard' => ['getExtendedAgentCard', new GetExtendedAgentCardRequest()];
    }

    #[DataProvider('methods')]
    public function testMethods(string $method, ProtobufMessage $request): void
    {
        $base = new RecordingTransport();
        $decorator = new TenantTransportDecorator($base, 'my-tenant');

        $decorator->{$method}($request);

        self::assertSame([$method], $base->methods());
        self::assertSame($request, $base->calls[0][1]);
        self::assertTrue(method_exists($request, 'getTenant'));
        self::assertSame('my-tenant', $request->getTenant());
    }

    /**
     * @return iterable<string, array{string, ProtobufMessage}>
     */
    public static function streamingMethods(): iterable
    {
        yield 'sendMessageStreaming' => ['sendMessageStreaming', new SendMessageRequest()];
        yield 'subscribe' => ['subscribe', new SubscribeToTaskRequest()];
    }

    #[DataProvider('streamingMethods')]
    public function testStreamingMethods(string $method, ProtobufMessage $request): void
    {
        $base = new RecordingTransport();
        $base->streamEvents = [new \A2A\Types\StreamResponse()];
        $decorator = new TenantTransportDecorator($base, 'my-tenant');

        $generator = $decorator->{$method}($request);
        self::assertInstanceOf(\Generator::class, $generator);

        self::assertCount(1, iterator_to_array($generator, false));
        self::assertSame([$method], $base->methods());
        self::assertTrue(method_exists($request, 'getTenant'));
        self::assertSame('my-tenant', $request->getTenant());
    }

    public function testCloseIsForwarded(): void
    {
        $base = new RecordingTransport();

        (new TenantTransportDecorator($base, 't'))->close();

        self::assertTrue($base->closed);
    }
}
