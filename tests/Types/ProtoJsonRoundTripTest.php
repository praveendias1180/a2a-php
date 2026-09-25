<?php

declare(strict_types=1);

namespace A2A\Tests\Types;

use A2A\Types\AgentCard;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\SendMessageRequest;
use A2A\Types\StreamResponse;
use A2A\Types\Task;
use A2A\Types\TaskState;
use Google\Protobuf\Internal\Message as ProtoMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The generated types must read and write A2A v1.0 JSON exactly.
 * Fixtures are the JSON shapes from the specification (v1.0.0).
 */
final class ProtoJsonRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<ProtoMessage>, string}>
     */
    public static function fixtures(): iterable
    {
        yield 'text part' => [Part::class, '{"text":"Hello, world!"}'];

        yield 'file part by url' => [
            Part::class,
            '{"url":"https://example.com/diagram.png","filename":"diagram.png","mediaType":"image/png"}',
        ];

        yield 'raw bytes part' => [Part::class, '{"raw":"aGVsbG8=","mediaType":"text/plain"}'];

        yield 'structured data part' => [
            Part::class,
            '{"data":{"city":"Colombo","count":3,"tags":["a","b"],"ok":true,"none":null}}',
        ];

        yield 'user message' => [
            Message::class,
            '{"messageId":"9229e770-767c-417b-a0b0-f0741243c589","role":"ROLE_USER",'
            . '"parts":[{"text":"Find restaurants near me"}],"contextId":"ctx-1"}',
        ];

        yield 'send message request' => [
            SendMessageRequest::class,
            '{"message":{"messageId":"m-1","role":"ROLE_USER","parts":[{"text":"hi"}]},'
            . '"configuration":{"acceptedOutputModes":["text/plain"],"historyLength":5,"returnImmediately":true}}',
        ];

        yield 'completed task with artifact' => [
            Task::class,
            '{"id":"task-1","contextId":"ctx-1",'
            . '"status":{"state":"TASK_STATE_COMPLETED","timestamp":"2026-09-25T10:00:00Z"},'
            . '"artifacts":[{"artifactId":"a-1","name":"response","parts":[{"text":"Hello World!"}]}]}',
        ];

        yield 'stream status update' => [
            StreamResponse::class,
            '{"statusUpdate":{"taskId":"task-1","contextId":"ctx-1","status":{"state":"TASK_STATE_WORKING"}}}',
        ];

        yield 'stream artifact chunk' => [
            StreamResponse::class,
            '{"artifactUpdate":{"taskId":"task-1","contextId":"ctx-1",'
            . '"artifact":{"artifactId":"a-1","parts":[{"text":"chunk"}]},"append":true,"lastChunk":true}}',
        ];

        yield 'agent card (Python hello-world sample)' => [
            AgentCard::class,
            '{"name":"Sample Agent","description":"A sample agent to test the stream functionality.",'
            . '"supportedInterfaces":[{"url":"http://127.0.0.1:41241/a2a/jsonrpc","protocolBinding":"JSONRPC","protocolVersion":"1.0"},'
            . '{"url":"http://127.0.0.1:41241/a2a/rest","protocolBinding":"HTTP+JSON","protocolVersion":"1.0"}],'
            . '"provider":{"url":"https://example.com","organization":"A2A Samples"},"version":"1.0.0",'
            . '"capabilities":{"streaming":true,"pushNotifications":false},'
            . '"securitySchemes":{"bearer":{"httpAuthSecurityScheme":{"scheme":"Bearer","bearerFormat":"JWT"}}},'
            . '"defaultInputModes":["text"],"defaultOutputModes":["text","task-status"],'
            . '"skills":[{"id":"sample_agent","name":"Sample Agent","description":"Say hi.","tags":["sample"],'
            . '"examples":["hi"],"inputModes":["text"],"outputModes":["text","task-status"]}]}',
        ];
    }

    /**
     * @param class-string<ProtoMessage> $class
     */
    #[DataProvider('fixtures')]
    public function testDecodesAndReEncodesToTheSameJson(string $class, string $json): void
    {
        $message = new $class();
        $message->mergeFromJsonString($json);

        self::assertJsonStringEqualsJsonString($json, $message->serializeToJsonString());
    }

    public function testEnumsUseSpecNames(): void
    {
        $task = new Task();
        $task->mergeFromJsonString('{"id":"t","status":{"state":"TASK_STATE_INPUT_REQUIRED"}}');

        self::assertSame(TaskState::TASK_STATE_INPUT_REQUIRED, $task->getStatus()?->getState());
        self::assertSame('ROLE_AGENT', Role::name(Role::ROLE_AGENT));
    }

    public function testPartOneofExposesWhichContentIsSet(): void
    {
        $part = new Part(['text' => 'hi']);

        self::assertSame('text', $part->getContent());
    }

    public function testDefaultValuesFollowProtoJsonPresenceRules(): void
    {
        // Plain proto3 fields drop their default value on the wire; `optional`
        // fields keep it. Same behaviour as the Python SDK's json_format.
        $response = new StreamResponse();
        $response->mergeFromJsonString(
            '{"artifactUpdate":{"taskId":"t","contextId":"c","artifact":{"artifactId":"a"},"lastChunk":false}}',
        );
        self::assertStringNotContainsString('lastChunk', $response->serializeToJsonString());

        $card = new AgentCard();
        $card->mergeFromJsonString('{"capabilities":{"pushNotifications":false}}');
        self::assertStringContainsString('"pushNotifications":false', $card->serializeToJsonString());
    }

    public function testRejectsTheV03KindDiscriminator(): void
    {
        // v0.3 wrote {"kind":"text",...}. v1.0 removed it, so strict decoding must fail.
        // Accepting 0.3 payloads is the job of the Compat\V0_3 layer, not of the types.
        $this->expectException(\Exception::class);

        (new Part())->mergeFromJsonString('{"kind":"text","text":"hi"}');
    }
}
