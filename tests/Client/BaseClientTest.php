<?php

declare(strict_types=1);

namespace A2A\Tests\Client;

use A2A\Client\BaseClient;
use A2A\Client\ClientCallContext;
use A2A\Client\ClientConfig;
use A2A\Tests\Client\Support\RecordingTransport;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\CancelTaskRequest;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\SendMessageResponse;
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Utils\ProtoUtils;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/test_base_client.py
 */
final class BaseClientTest extends TestCase
{
    private RecordingTransport $transport;
    private ClientConfig $config;
    private AgentCard $card;
    private BaseClient $client;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->config = new ClientConfig(streaming: true);
        $this->card = new AgentCard([
            'name' => 'Test Agent',
            'description' => 'An agent for testing',
            'supported_interfaces' => [new AgentInterface(['url' => 'http://test.com', 'protocol_binding' => 'HTTP+JSON'])],
            'version' => '1.0',
            'capabilities' => new AgentCapabilities(['streaming' => true]),
            'default_input_modes' => ['text/plain'],
            'default_output_modes' => ['text/plain'],
        ]);
        $this->client = new BaseClient($this->card, $this->config, $this->transport);
    }

    public function testCloseClosesTheTransport(): void
    {
        $this->client->close();

        self::assertTrue($this->transport->closed);
    }

    public function testSendMessageStreaming(): void
    {
        $this->transport->streamEvents = [new StreamResponse(['task' => self::task('task-123')])];
        $request = new SendMessageRequest(['message' => self::message(), 'metadata' => ProtoUtils::toStruct(['test' => 1])]);

        $events = iterator_to_array($this->client->sendMessage($request), false);

        self::assertSame(['sendMessageStreaming'], $this->transport->methods());
        $sent = $this->transport->calls[0][1];
        self::assertInstanceOf(SendMessageRequest::class, $sent);
        self::assertNotNull($sent->getMetadata());
        self::assertEquals(['test' => 1], ProtoUtils::fromStruct($sent->getMetadata()));
        self::assertCount(1, $events);
        self::assertSame('task-123', $events[0]->getTask()?->getId());
    }

    public function testSendMessageNonStreaming(): void
    {
        $this->config->streaming = false;
        $this->transport->sendMessageResponse = new SendMessageResponse(['task' => self::task('task-456')]);
        $request = new SendMessageRequest(['message' => self::message(), 'metadata' => ProtoUtils::toStruct(['test' => 1])]);

        $events = iterator_to_array($this->client->sendMessage($request), false);

        self::assertSame(['sendMessage'], $this->transport->methods());
        self::assertCount(1, $events);
        self::assertSame('task-456', $events[0]->getTask()?->getId());
    }

    public function testSendMessageNonStreamingWrapsAMessageResponse(): void
    {
        $this->config->streaming = false;
        $this->transport->sendMessageResponse = new SendMessageResponse(['message' => new Message(['message_id' => 'reply'])]);

        $events = iterator_to_array($this->client->sendMessage(new SendMessageRequest(['message' => self::message()])), false);

        self::assertSame('reply', $events[0]->getMessage()?->getMessageId());
    }

    public function testSendMessageNonStreamingRejectsAnEmptyResponse(): void
    {
        $this->config->streaming = false;
        $this->transport->sendMessageResponse = new SendMessageResponse();

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Response has neither task nor message');
        iterator_to_array($this->client->sendMessage(new SendMessageRequest(['message' => self::message()])));
    }

    public function testSendMessageNonStreamingAgentCapabilityFalse(): void
    {
        $this->card->getCapabilities()?->setStreaming(false);
        $this->transport->sendMessageResponse = new SendMessageResponse(['task' => self::task('task-789')]);

        $events = iterator_to_array($this->client->sendMessage(new SendMessageRequest(['message' => self::message()])), false);

        self::assertSame(['sendMessage'], $this->transport->methods());
        self::assertSame('task-789', $events[0]->getTask()?->getId());
    }

    public function testSendMessageCallsiteConfigOverridesNonStreaming(): void
    {
        $this->config->streaming = false;
        $this->transport->sendMessageResponse = new SendMessageResponse(['task' => self::task('task-cfg-ns-1')]);
        $configuration = new SendMessageConfiguration(['history_length' => 2, 'return_immediately' => true, 'accepted_output_modes' => ['application/json']]);

        $events = iterator_to_array($this->client->sendMessage(new SendMessageRequest(['message' => self::message(), 'configuration' => $configuration])), false);

        self::assertSame('task-cfg-ns-1', $events[0]->getTask()?->getId());
        $sent = $this->transport->calls[0][1];
        self::assertInstanceOf(SendMessageRequest::class, $sent);
        $sentConfiguration = $sent->getConfiguration();
        self::assertNotNull($sentConfiguration);
        self::assertSame(2, $sentConfiguration->getHistoryLength());
        self::assertTrue($sentConfiguration->getReturnImmediately());
        self::assertSame(['application/json'], iterator_to_array($sentConfiguration->getAcceptedOutputModes()));
    }

    public function testSendMessageCallsiteConfigOverridesStreaming(): void
    {
        $this->transport->streamEvents = [new StreamResponse(['task' => self::task('task-cfg-s-1')])];
        $configuration = new SendMessageConfiguration(['history_length' => 0, 'accepted_output_modes' => ['text/plain']]);

        $events = iterator_to_array($this->client->sendMessage(new SendMessageRequest(['message' => self::message(), 'configuration' => $configuration])), false);

        self::assertSame(['sendMessageStreaming'], $this->transport->methods());
        self::assertSame('task-cfg-s-1', $events[0]->getTask()?->getId());
        $sent = $this->transport->calls[0][1];
        self::assertInstanceOf(SendMessageRequest::class, $sent);
        $sentConfiguration = $sent->getConfiguration();
        self::assertNotNull($sentConfiguration);
        self::assertSame(0, $sentConfiguration->getHistoryLength());
        self::assertFalse($sentConfiguration->getReturnImmediately());
        self::assertSame(['text/plain'], iterator_to_array($sentConfiguration->getAcceptedOutputModes()));
    }

    public function testClientConfigFillsWhatTheRequestLeavesOut(): void
    {
        $push = new TaskPushNotificationConfig(['url' => 'https://example.com/hook']);
        $client = new BaseClient($this->card, new ClientConfig(streaming: false, polling: true, acceptedOutputModes: ['text/plain'], pushNotificationConfig: $push), $this->transport);

        iterator_to_array($client->sendMessage(new SendMessageRequest(['message' => self::message()])));

        $sent = $this->transport->calls[0][1];
        self::assertInstanceOf(SendMessageRequest::class, $sent);
        $configuration = $sent->getConfiguration();
        self::assertNotNull($configuration);
        self::assertTrue($configuration->getReturnImmediately());
        self::assertSame(['text/plain'], iterator_to_array($configuration->getAcceptedOutputModes()));
        self::assertSame('https://example.com/hook', $configuration->getTaskPushNotificationConfig()?->getUrl());
        self::assertNotSame($push, $configuration->getTaskPushNotificationConfig(), 'the config object is copied, not shared');
    }

    public function testStreamStopsAfterAMessage(): void
    {
        $this->transport->streamEvents = [
            new StreamResponse(['message' => new Message(['message_id' => 'm'])]),
            new StreamResponse(['task' => self::task('never')]),
        ];

        $events = iterator_to_array($this->client->sendMessage(new SendMessageRequest(['message' => self::message()])), false);

        self::assertCount(1, $events);
    }

    public function testSendMessageIsLazy(): void
    {
        $generator = $this->client->sendMessage(new SendMessageRequest(['message' => self::message()]));

        self::assertSame([], $this->transport->calls);
        unset($generator);
    }

    public function testUnaryMethodsDelegateToTheTransport(): void
    {
        $context = new ClientCallContext(timeout: 3.0);

        self::assertSame($this->transport->task, $this->client->getTask(new GetTaskRequest(['id' => 't']), $context));
        self::assertCount(1, $this->client->listTasks(new ListTasksRequest())->getTasks());
        self::assertSame($this->transport->task, $this->client->cancelTask(new CancelTaskRequest(['id' => 't'])));
        self::assertSame('https://x', $this->client->createTaskPushNotificationConfig(new TaskPushNotificationConfig(['url' => 'https://x']))->getUrl());
        self::assertSame('c', $this->client->getTaskPushNotificationConfig(new GetTaskPushNotificationConfigRequest(['id' => 'c']))->getId());
        $this->client->listTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest());
        $this->client->deleteTaskPushNotificationConfig(new DeleteTaskPushNotificationConfigRequest());

        self::assertSame([
            'getTask', 'listTasks', 'cancelTask', 'createTaskPushNotificationConfig',
            'getTaskPushNotificationConfig', 'listTaskPushNotificationConfigs', 'deleteTaskPushNotificationConfig',
        ], $this->transport->methods());
        self::assertSame($context, $this->transport->calls[0][2]);
    }

    public function testSubscribe(): void
    {
        $this->transport->streamEvents = [new StreamResponse(['task' => self::task('t')])];

        $events = iterator_to_array($this->client->subscribe(new SubscribeToTaskRequest(['id' => 't'])), false);

        self::assertCount(1, $events);
        self::assertSame(['subscribe'], $this->transport->methods());
    }

    public function testSubscribeNeedsStreamingOnBothSides(): void
    {
        $this->config->streaming = false;

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('client and/or server do not support resubscription.');
        iterator_to_array($this->client->subscribe(new SubscribeToTaskRequest(['id' => 't'])));
    }

    public function testGetExtendedAgentCardReplacesTheCardAndRunsTheVerifier(): void
    {
        $verified = null;

        $card = $this->client->getExtendedAgentCard(new GetExtendedAgentCardRequest(), null, static function (AgentCard $c) use (&$verified): void {
            $verified = $c->getName();
        });

        self::assertSame('extended', $card->getName());
        self::assertSame('extended', $verified);
        self::assertSame($card, $this->client->agentCard());
    }

    private static function message(): Message
    {
        return new Message(['role' => Role::ROLE_USER, 'message_id' => 'msg-1', 'parts' => [new Part(['text' => 'Hello'])]]);
    }

    private static function task(string $id): Task
    {
        return new Task(['id' => $id, 'context_id' => 'ctx', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_COMPLETED])]);
    }
}
