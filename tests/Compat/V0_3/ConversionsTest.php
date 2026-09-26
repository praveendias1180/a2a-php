<?php

declare(strict_types=1);

namespace A2A\Tests\Compat\V0_3;

use A2A\Compat\V0_3\Conversions;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\Part;
use A2A\Types\SendMessageRequest;
use A2A\Types\StreamResponse;
use A2A\Utils\Errors\VersionNotSupportedError;
use Google\Protobuf\Internal\Message as ProtobufMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v0.3 ↔ v1.0 conversions: each case is the exact v0.3 JSON and the exact
 * v1.0 ProtoJSON. Ported from a2a-python tests/compat/v0_3/test_conversions.py
 * (Python compares pydantic / proto objects; here both sides are JSON).
 */
final class ConversionsTest extends TestCase
{
    /**
     * Pairs that convert both ways and round-trip exactly.
     *
     * @return iterable<string, array{string, class-string<ProtobufMessage>, string, string}>
     */
    public static function symmetricCases(): iterable
    {
        yield 'text part' => ['Part', Part::class, '{"kind":"text","text":"Hello, World!","metadata":{"test":"val"}}', '{"text":"Hello, World!","metadata":{"test":"val"}}'];
        yield 'data part' => ['Part', Part::class, '{"kind":"data","data":{"key":"val","nested":{"a":1}}}', '{"data":{"key":"val","nested":{"a":1}}}'];
        yield 'file part (uri)' => ['Part', Part::class,
            '{"kind":"file","file":{"uri":"http://example.com/file","mimeType":"text/plain","name":"file.txt"}}',
            '{"url":"http://example.com/file","filename":"file.txt","mediaType":"text/plain"}'];
        yield 'file part (bytes)' => ['Part', Part::class,
            '{"kind":"file","file":{"bytes":"aGVsbG8gd29ybGQ=","mimeType":"application/octet-stream","name":"file.bin"}}',
            '{"raw":"aGVsbG8gd29ybGQ=","filename":"file.bin","mediaType":"application/octet-stream"}'];
        yield 'message' => ['Message', \A2A\Types\Message::class,
            '{"kind":"message","messageId":"msg-1","role":"user","contextId":"ctx-1","taskId":"task-1","referenceTaskIds":["t0"],"metadata":{"k":"v"},"extensions":["ext1"],"parts":[{"kind":"text","text":"Hi"}]}',
            '{"messageId":"msg-1","contextId":"ctx-1","taskId":"task-1","role":"ROLE_USER","parts":[{"text":"Hi"}],"metadata":{"k":"v"},"extensions":["ext1"],"referenceTaskIds":["t0"]}'];
        yield 'message (minimal)' => ['Message', \A2A\Types\Message::class,
            '{"kind":"message","messageId":"m","role":"agent","parts":[]}',
            '{"messageId":"m","role":"ROLE_AGENT"}'];
        yield 'task status' => ['TaskStatus', \A2A\Types\TaskStatus::class,
            '{"state":"working","message":{"kind":"message","messageId":"s","role":"agent","parts":[{"kind":"text","text":"on it"}]},"timestamp":"2023-10-27T10:00:00Z"}',
            '{"state":"TASK_STATE_WORKING","message":{"messageId":"s","role":"ROLE_AGENT","parts":[{"text":"on it"}]},"timestamp":"2023-10-27T10:00:00Z"}'];
        yield 'task status (input-required)' => ['TaskStatus', \A2A\Types\TaskStatus::class, '{"state":"input-required"}', '{"state":"TASK_STATE_INPUT_REQUIRED"}'];
        yield 'task status (auth-required)' => ['TaskStatus', \A2A\Types\TaskStatus::class, '{"state":"auth-required"}', '{"state":"TASK_STATE_AUTH_REQUIRED"}'];
        yield 'task' => ['Task', \A2A\Types\Task::class,
            '{"kind":"task","id":"t1","contextId":"c1","status":{"state":"completed"},"history":[{"kind":"message","messageId":"m1","role":"user","parts":[{"kind":"text","text":"q"}]}],"artifacts":[{"artifactId":"a1","parts":[{"kind":"text","text":"r"}]}],"metadata":{"m":1}}',
            '{"id":"t1","contextId":"c1","status":{"state":"TASK_STATE_COMPLETED"},"artifacts":[{"artifactId":"a1","parts":[{"text":"r"}]}],"history":[{"messageId":"m1","role":"ROLE_USER","parts":[{"text":"q"}]}],"metadata":{"m":1}}'];
        yield 'authentication info' => ['AuthenticationInfo', \A2A\Types\AuthenticationInfo::class, '{"schemes":["Bearer"],"credentials":"tok"}', '{"scheme":"Bearer","credentials":"tok"}'];
        yield 'push notification config' => ['PushNotificationConfig', \A2A\Types\TaskPushNotificationConfig::class,
            '{"url":"https://example.com/hook","id":"c1","token":"tk","authentication":{"schemes":["Bearer"],"credentials":"x"}}',
            '{"id":"c1","url":"https://example.com/hook","token":"tk","authentication":{"scheme":"Bearer","credentials":"x"}}'];
        yield 'send message configuration' => ['SendMessageConfiguration', \A2A\Types\SendMessageConfiguration::class,
            '{"acceptedOutputModes":["text/plain"],"historyLength":5,"blocking":false}',
            '{"acceptedOutputModes":["text/plain"],"historyLength":5,"returnImmediately":true}'];
        yield 'artifact' => ['Artifact', \A2A\Types\Artifact::class,
            '{"artifactId":"a1","name":"n","description":"d","parts":[{"kind":"data","data":{"x":1}}],"metadata":{"k":"v"},"extensions":["e"]}',
            '{"artifactId":"a1","name":"n","description":"d","parts":[{"data":{"x":1}}],"metadata":{"k":"v"},"extensions":["e"]}'];
        yield 'artifact update event' => ['TaskArtifactUpdateEvent', \A2A\Types\TaskArtifactUpdateEvent::class,
            '{"kind":"artifact-update","taskId":"t","contextId":"c","artifact":{"artifactId":"a","parts":[{"kind":"text","text":"x"}]},"append":true,"lastChunk":false}',
            '{"taskId":"t","contextId":"c","artifact":{"artifactId":"a","parts":[{"text":"x"}]},"append":true}'];
        yield 'task push notification config' => ['TaskPushNotificationConfig', \A2A\Types\TaskPushNotificationConfig::class,
            '{"taskId":"t1","pushNotificationConfig":{"url":"https://h","id":"c"}}',
            '{"id":"c","taskId":"t1","url":"https://h"}'];
        yield 'security scheme (api key)' => ['SecurityScheme', \A2A\Types\SecurityScheme::class,
            '{"type":"apiKey","in":"header","name":"X-Key","description":"d"}',
            '{"apiKeySecurityScheme":{"description":"d","location":"header","name":"X-Key"}}'];
        yield 'security scheme (http)' => ['SecurityScheme', \A2A\Types\SecurityScheme::class,
            '{"type":"http","scheme":"Bearer","bearerFormat":"JWT"}',
            '{"httpAuthSecurityScheme":{"scheme":"Bearer","bearerFormat":"JWT"}}'];
        yield 'security scheme (oauth2)' => ['SecurityScheme', \A2A\Types\SecurityScheme::class,
            '{"type":"oauth2","flows":{"clientCredentials":{"tokenUrl":"https://t","scopes":{"read":"Read"}}},"oauth2MetadataUrl":"https://m"}',
            '{"oauth2SecurityScheme":{"flows":{"clientCredentials":{"tokenUrl":"https://t","scopes":{"read":"Read"}}},"oauth2MetadataUrl":"https://m"}}'];
        yield 'security scheme (oidc)' => ['SecurityScheme', \A2A\Types\SecurityScheme::class,
            '{"type":"openIdConnect","openIdConnectUrl":"https://o"}',
            '{"openIdConnectSecurityScheme":{"openIdConnectUrl":"https://o"}}'];
        yield 'security scheme (mtls)' => ['SecurityScheme', \A2A\Types\SecurityScheme::class,
            '{"type":"mutualTLS","description":"certs"}',
            '{"mtlsSecurityScheme":{"description":"certs"}}'];
        yield 'oauth flows (auth code)' => ['OauthFlows', \A2A\Types\OAuthFlows::class,
            '{"authorizationCode":{"authorizationUrl":"https://a","tokenUrl":"https://t","scopes":{"s":"S"},"refreshUrl":"https://r"}}',
            '{"authorizationCode":{"authorizationUrl":"https://a","tokenUrl":"https://t","refreshUrl":"https://r","scopes":{"s":"S"}}}'];
        yield 'oauth flows (implicit)' => ['OauthFlows', \A2A\Types\OAuthFlows::class,
            '{"implicit":{"authorizationUrl":"https://a","scopes":{"s":"S"}}}',
            '{"implicit":{"authorizationUrl":"https://a","scopes":{"s":"S"}}}'];
        yield 'oauth flows (password)' => ['OauthFlows', \A2A\Types\OAuthFlows::class,
            '{"password":{"tokenUrl":"https://t","scopes":{"s":"S"}}}',
            '{"password":{"tokenUrl":"https://t","scopes":{"s":"S"}}}'];
        yield 'agent skill' => ['AgentSkill', \A2A\Types\AgentSkill::class,
            '{"id":"s","name":"S","description":"d","tags":["t"],"examples":["e"],"inputModes":["text"],"outputModes":["text"],"security":[{"bearer":["read"]}]}',
            '{"id":"s","name":"S","description":"d","tags":["t"],"examples":["e"],"inputModes":["text"],"outputModes":["text"],"securityRequirements":[{"schemes":{"bearer":{"list":["read"]}}}]}'];
        yield 'agent extension' => ['AgentExtension', \A2A\Types\AgentExtension::class,
            '{"uri":"https://ext","description":"d","required":true,"params":{"p":1}}',
            '{"uri":"https://ext","description":"d","required":true,"params":{"p":1}}'];
        yield 'agent card signature' => ['AgentCardSignature', \A2A\Types\AgentCardSignature::class,
            '{"protected":"p","signature":"s","header":{"kid":"k"}}',
            '{"protected":"p","signature":"s","header":{"kid":"k"}}'];
    }

    /**
     * @param class-string<ProtobufMessage> $coreClass
     */
    #[DataProvider('symmetricCases')]
    public function testConvertsBothWays(string $type, string $coreClass, string $compatJson, string $coreJson): void
    {
        $toCore = [Conversions::class, 'toCore' . $type];
        $toCompat = [Conversions::class, 'toCompat' . $type];
        self::assertIsCallable($toCore);
        self::assertIsCallable($toCompat);

        $core = $toCore(self::obj($compatJson));
        self::assertInstanceOf($coreClass, $core);
        self::assertJsonStringEqualsJsonString($coreJson, $core->serializeToJsonString(), 'v0.3 → v1.0');

        $expected = new $coreClass();
        $expected->mergeFromJsonString($coreJson);
        $compat = $toCompat($expected);
        self::assertJsonStringEqualsJsonString($compatJson, json_encode($compat, JSON_THROW_ON_ERROR), 'v1.0 → v0.3');
    }

    public function testNonObjectDataTravelsAsAValueWithACompatMarker(): void
    {
        foreach (['"Primitive String"', '42', '3.14', 'true', 'false', '["a","b"]', '[1,2,3]', 'null'] as $value) {
            $core = new Part();
            $core->mergeFromJsonString('{"data":' . $value . '}');

            $compat = Conversions::toCompatPart($core);
            self::assertSame('data', $compat->kind);
            self::assertJsonStringEqualsJsonString('{"value":' . $value . '}', json_encode($compat->data, JSON_THROW_ON_ERROR));
            self::assertTrue(self::path($compat, 'metadata.data_part_compat'));

            self::assertJsonStringEqualsJsonString($core->serializeToJsonString(), Conversions::toCorePart($compat)->serializeToJsonString(), "round trip of {$value}");
        }
    }

    public function testDataPartMetadataIsKeptWhenItIsNotTheMarker(): void
    {
        $core = Conversions::toCorePart(self::obj('{"kind":"data","data":{"a":1},"metadata":{"other":"x"}}'));

        self::assertJsonStringEqualsJsonString('{"data":{"a":1},"metadata":{"other":"x"}}', $core->serializeToJsonString());
    }

    public function testStatusUpdateEventMarksTerminalStatesFinal(): void
    {
        foreach (['completed' => true, 'canceled' => true, 'failed' => true, 'rejected' => true, 'working' => false, 'input-required' => false] as $state => $final) {
            $compat = self::obj(sprintf('{"kind":"status-update","taskId":"t","contextId":"c","status":{"state":"%s"},"final":false}', $state));
            $event = Conversions::toCompatTaskStatusUpdateEvent(Conversions::toCoreTaskStatusUpdateEvent($compat));

            self::assertSame($final, $event->final, $state);
            self::assertSame('status-update', $event->kind);
        }
    }

    public function testMissingPiecesBecomeDefaults(): void
    {
        self::assertSame(\A2A\Types\Role::ROLE_UNSPECIFIED, Conversions::toCoreMessage(self::obj('{"messageId":"m","parts":[]}'))->getRole());
        self::assertSame(\A2A\Types\TaskState::TASK_STATE_UNSPECIFIED, Conversions::toCoreTaskStatus(new \stdClass())->getState());
        self::assertFalse(Conversions::toCoreTaskStatusUpdateEvent(self::obj('{"taskId":"t","contextId":"c"}'))->hasStatus());
        self::assertFalse(Conversions::toCoreTaskArtifactUpdateEvent(self::obj('{"taskId":"t","contextId":"c"}'))->hasArtifact());
        self::assertFalse(Conversions::toCoreTask(self::obj('{"id":"t","contextId":"c"}'))->hasStatus());

        // v1.0 → v0.3: a task with no status is "unknown".
        self::assertSame('unknown', self::path(Conversions::toCompatTask(new \A2A\Types\Task(['id' => 't'])), 'status.state'));
    }

    public function testBlockingIsTheInverseOfReturnImmediately(): void
    {
        self::assertFalse(Conversions::toCoreSendMessageConfiguration(self::obj('{"blocking":true}'))->getReturnImmediately());
        self::assertTrue(Conversions::toCoreSendMessageConfiguration(self::obj('{"blocking":false}'))->getReturnImmediately());
        // Unset: blocking, the v1.0 default.
        self::assertFalse(Conversions::toCoreSendMessageConfiguration(new \stdClass())->getReturnImmediately());
        self::assertTrue(Conversions::toCompatSendMessageConfiguration(new \A2A\Types\SendMessageConfiguration())->blocking);
    }

    public function testOauthFlowsDropDeviceCode(): void
    {
        $flows = new \A2A\Types\OAuthFlows();
        $flows->mergeFromJsonString('{"deviceCode":{"deviceAuthorizationUrl":"https://d","tokenUrl":"https://t","scopes":{}}}');

        self::assertEquals(new \stdClass(), Conversions::toCompatOauthFlows($flows));
    }

    public function testUnknownSecuritySchemeTypeIsEmpty(): void
    {
        self::assertSame('', Conversions::toCoreSecurityScheme(self::obj('{"type":"magic"}'))->getScheme());
    }

    public function testToCompatSecuritySchemeRejectsAnEmptyScheme(): void
    {
        $this->expectException(\ValueError::class);
        Conversions::toCompatSecurityScheme(new \A2A\Types\SecurityScheme());
    }

    public function testToCompatPartRejectsAnEmptyPart(): void
    {
        $this->expectException(\ValueError::class);
        Conversions::toCompatPart(new Part());
    }

    public function testAgentCardConversion(): void
    {
        $compat = self::obj(<<<'JSON'
            {"name":"Agent","description":"Desc","version":"1.0","url":"http://a/rpc","preferredTransport":"JSONRPC","protocolVersion":"0.3.0",
             "additionalInterfaces":[{"url":"http://a/rest","transport":"HTTP+JSON"}],
             "provider":{"url":"https://p","organization":"Org"},"documentationUrl":"https://docs","iconUrl":"https://icon",
             "capabilities":{"streaming":true,"pushNotifications":false},"supportsAuthenticatedExtendedCard":true,
             "securitySchemes":{"bearer":{"type":"http","scheme":"Bearer"}},"security":[{"bearer":[]}],
             "defaultInputModes":["text"],"defaultOutputModes":["text"],
             "skills":[{"id":"s","name":"S","description":"d","tags":["t"]}],
             "signatures":[{"protected":"p","signature":"s"}]}
            JSON);

        $core = Conversions::toCoreAgentCard($compat);

        self::assertJsonStringEqualsJsonString(<<<'JSON'
            {"name":"Agent","description":"Desc","version":"1.0",
             "supportedInterfaces":[{"url":"http://a/rpc","protocolBinding":"JSONRPC","protocolVersion":"0.3.0"},{"url":"http://a/rest","protocolBinding":"HTTP+JSON","protocolVersion":"0.3"}],
             "provider":{"url":"https://p","organization":"Org"},"documentationUrl":"https://docs","iconUrl":"https://icon",
             "capabilities":{"streaming":true,"pushNotifications":false,"extendedAgentCard":true},
             "securitySchemes":{"bearer":{"httpAuthSecurityScheme":{"scheme":"Bearer"}}},"securityRequirements":[{"schemes":{"bearer":{}}}],
             "defaultInputModes":["text"],"defaultOutputModes":["text"],
             "skills":[{"id":"s","name":"S","description":"d","tags":["t"]}],
             "signatures":[{"protected":"p","signature":"s"}]}
            JSON, $core->serializeToJsonString());

        $back = Conversions::toCompatAgentCard($core);
        self::assertSame('http://a/rpc', $back->url);
        self::assertSame('JSONRPC', $back->preferredTransport);
        self::assertSame('0.3.0', $back->protocolVersion);
        self::assertEquals([(object) ['url' => 'http://a/rest', 'transport' => 'HTTP+JSON']], $back->additionalInterfaces);
        self::assertTrue($back->supportsAuthenticatedExtendedCard);
        self::assertEquals((object) ['type' => 'http', 'scheme' => 'Bearer'], self::path($back, 'securitySchemes.bearer'));
    }

    public function testToCompatAgentCardNeedsAV03Interface(): void
    {
        $card = new AgentCard(['name' => 'n', 'supported_interfaces' => [new AgentInterface(['url' => 'u', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0'])]]);

        $this->expectException(VersionNotSupportedError::class);
        Conversions::toCompatAgentCard($card);
    }

    public function testToCompatAgentCardAcceptsAnUnversionedInterface(): void
    {
        $card = new AgentCard(['name' => 'n', 'supported_interfaces' => [new AgentInterface(['url' => 'u', 'protocol_binding' => 'JSONRPC'])]]);

        self::assertSame('0.3', Conversions::toCompatAgentCard($card)->protocolVersion);
    }

    public function testSendMessageRequestConversion(): void
    {
        $params = self::obj('{"message":{"kind":"message","messageId":"m","role":"user","parts":[{"kind":"text","text":"hi"}]},"configuration":{"blocking":true,"historyLength":2},"metadata":{"k":"v"}}');

        $core = Conversions::toCoreSendMessageRequest($params);

        self::assertJsonStringEqualsJsonString('{"message":{"messageId":"m","role":"ROLE_USER","parts":[{"text":"hi"}]},"configuration":{"historyLength":2},"metadata":{"k":"v"}}', $core->serializeToJsonString());
        self::assertJsonStringEqualsJsonString(json_encode($params, JSON_THROW_ON_ERROR), json_encode(Conversions::toCompatSendMessageRequest($core), JSON_THROW_ON_ERROR));
    }

    public function testSendMessageRequestWithoutConfiguration(): void
    {
        $core = Conversions::toCoreSendMessageRequest(self::obj('{"message":{"messageId":"m","role":"user","parts":[]}}'));

        self::assertFalse($core->hasConfiguration());
        self::assertObjectNotHasProperty('configuration', Conversions::toCompatSendMessageRequest(new SendMessageRequest(['message' => $core->getMessage()])));
    }

    public function testRequestParamsConversions(): void
    {
        self::assertJsonStringEqualsJsonString('{"id":"t","historyLength":3}', Conversions::toCoreGetTaskRequest(self::obj('{"id":"t","historyLength":3}'))->serializeToJsonString());
        self::assertEquals((object) ['id' => 't'], Conversions::toCompatGetTaskRequest(new \A2A\Types\GetTaskRequest(['id' => 't'])));
        self::assertJsonStringEqualsJsonString('{"id":"t","metadata":{"why":"x"}}', Conversions::toCoreCancelTaskRequest(self::obj('{"id":"t","metadata":{"why":"x"}}'))->serializeToJsonString());
        self::assertJsonStringEqualsJsonString('{"taskId":"t","id":"c"}', Conversions::toCoreGetTaskPushNotificationConfigRequest(self::obj('{"id":"t","pushNotificationConfigId":"c"}'))->serializeToJsonString());
        self::assertEquals((object) ['id' => 't'], Conversions::toCompatGetTaskPushNotificationConfigRequest(new \A2A\Types\GetTaskPushNotificationConfigRequest(['task_id' => 't'])));
        self::assertJsonStringEqualsJsonString('{"taskId":"t","id":"c"}', Conversions::toCoreDeleteTaskPushNotificationConfigRequest(self::obj('{"id":"t","pushNotificationConfigId":"c"}'))->serializeToJsonString());
        self::assertJsonStringEqualsJsonString('{"id":"t"}', Conversions::toCoreSubscribeToTaskRequest(self::obj('{"id":"t"}'))->serializeToJsonString());
        self::assertJsonStringEqualsJsonString('{"taskId":"t"}', Conversions::toCoreListTaskPushNotificationConfigRequest(self::obj('{"id":"t"}'))->serializeToJsonString());
        self::assertSame('', Conversions::toCoreListTaskPushNotificationConfigRequest(new \stdClass())->getTaskId());
        self::assertSame('{}', Conversions::toCoreGetExtendedAgentCardRequest(new \stdClass())->serializeToJsonString());
    }

    public function testListPushConfigsResponseConversion(): void
    {
        $result = [self::obj('{"taskId":"t","pushNotificationConfig":{"url":"https://h","id":"c"}}')];

        $core = Conversions::toCoreListTaskPushNotificationConfigResponse($result);

        self::assertJsonStringEqualsJsonString('{"configs":[{"id":"c","taskId":"t","url":"https://h"}]}', $core->serializeToJsonString());
        self::assertJsonStringEqualsJsonString(json_encode($result, JSON_THROW_ON_ERROR), json_encode(Conversions::toCompatListTaskPushNotificationConfigResponse($core), JSON_THROW_ON_ERROR));
        self::assertCount(0, Conversions::toCoreListTaskPushNotificationConfigResponse(null)->getConfigs());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function streamResults(): iterable
    {
        yield 'task' => ['{"kind":"task","id":"t","contextId":"c","status":{"state":"submitted"}}', 'task'];
        yield 'message' => ['{"kind":"message","messageId":"m","role":"agent","parts":[]}', 'message'];
        yield 'status update' => ['{"kind":"status-update","taskId":"t","contextId":"c","status":{"state":"working"},"final":false}', 'status_update'];
        yield 'artifact update' => ['{"kind":"artifact-update","taskId":"t","contextId":"c","artifact":{"artifactId":"a","parts":[]}}', 'artifact_update'];
        // Old servers may leave `kind` out (Python infers it the same way).
        yield 'status update without kind' => ['{"taskId":"t","contextId":"c","status":{"state":"working"},"final":false}', 'status_update'];
        yield 'message without kind' => ['{"messageId":"m","role":"agent","parts":[]}', 'message'];
        yield 'task without kind' => ['{"id":"t","contextId":"c","status":{"state":"working"}}', 'task'];
    }

    #[DataProvider('streamResults')]
    public function testStreamResponseConversion(string $compatJson, string $payload): void
    {
        $core = Conversions::toCoreStreamResponse(self::obj($compatJson));

        self::assertSame($payload, $core->getPayload());
        self::assertSame(Conversions::resultKind(self::obj($compatJson)), Conversions::toCompatStreamResponse($core)->kind);
    }

    public function testUnknownStreamResultsAreEmpty(): void
    {
        self::assertSame('', Conversions::toCoreStreamResponse(self::obj('{"kind":"mystery"}'))->getPayload());

        $this->expectException(\ValueError::class);
        Conversions::toCompatStreamResponse(new StreamResponse());
    }

    public function testSendMessageResponseConversion(): void
    {
        self::assertTrue(Conversions::toCoreSendMessageResponse(self::obj('{"kind":"task","id":"t","contextId":"c","status":{"state":"completed"}}'))->hasTask());
        self::assertTrue(Conversions::toCoreSendMessageResponse(self::obj('{"kind":"message","messageId":"m","role":"agent","parts":[]}'))->hasMessage());
        self::assertSame('', Conversions::toCoreSendMessageResponse(new \stdClass())->getPayload());

        $response = new \A2A\Types\SendMessageResponse(['message' => new \A2A\Types\Message(['message_id' => 'm', 'role' => \A2A\Types\Role::ROLE_AGENT])]);
        self::assertSame('message', Conversions::toCompatSendMessageResponse($response)->kind);
    }

    public function testTimestampsWithOffsetsAreNormalisedToUtc(): void
    {
        $status = Conversions::toCoreTaskStatus(self::obj('{"state":"working","timestamp":"2023-10-27T12:00:00+02:00"}'));

        self::assertSame('2023-10-27T10:00:00Z', json_decode($status->getTimestamp()?->serializeToJsonString() ?? 'null'));
    }

    /**
     * The value at a dotted path through objects and lists.
     */
    private static function path(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            $value = match (true) {
                $value instanceof \stdClass => $value->{$key} ?? null,
                is_array($value) => $value[(int) $key] ?? null,
                default => null,
            };
        }

        return $value;
    }

    private static function obj(string $json): \stdClass
    {
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $value);

        return $value;
    }
}
