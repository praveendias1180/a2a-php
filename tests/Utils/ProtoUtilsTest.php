<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentExtension;
use A2A\Types\AgentSkill;
use A2A\Types\APIKeySecurityScheme;
use A2A\Types\ListTasksRequest;
use A2A\Types\Message;
use A2A\Types\Meta\RequiredFields;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\SecurityScheme;
use A2A\Types\StreamResponse;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\ProtoUtils;
use Google\Protobuf\Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/utils/test_proto_utils.py, plus the
 * Struct/Value conversions that Python gets from json_format.
 *
 * Not ported: TestFieldIsRepeatedFallback, which covers a Python protobuf
 * version shim that has no PHP counterpart.
 */
final class ProtoUtilsTest extends TestCase
{
    // --- toStreamResponse ---

    public function testStreamResponseWithTask(): void
    {
        $result = ProtoUtils::toStreamResponse(new Task(['id' => 'task-1', 'context_id' => 'ctx-1', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_WORKING])]));

        self::assertInstanceOf(StreamResponse::class, $result);
        self::assertTrue($result->hasTask());
        self::assertSame('task-1', $result->getTask()?->getId());
    }

    public function testStreamResponseWithMessage(): void
    {
        $result = ProtoUtils::toStreamResponse(new Message(['message_id' => 'msg-1', 'role' => Role::ROLE_AGENT, 'parts' => [new Part(['text' => 'Hello'])]]));

        self::assertTrue($result->hasMessage());
        self::assertSame('msg-1', $result->getMessage()?->getMessageId());
    }

    public function testStreamResponseWithStatusUpdate(): void
    {
        $result = ProtoUtils::toStreamResponse(new TaskStatusUpdateEvent(['task_id' => 'task-1', 'context_id' => 'ctx-1', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_WORKING])]));

        self::assertTrue($result->hasStatusUpdate());
        self::assertSame('task-1', $result->getStatusUpdate()?->getTaskId());
    }

    public function testStreamResponseWithArtifactUpdate(): void
    {
        $result = ProtoUtils::toStreamResponse(new TaskArtifactUpdateEvent(['task_id' => 'task-1', 'context_id' => 'ctx-1']));

        self::assertTrue($result->hasArtifactUpdate());
        self::assertSame('task-1', $result->getArtifactUpdate()?->getTaskId());
    }

    // --- dictionary normalization ---

    public function testMakeDictSerializable(): void
    {
        $custom = new class implements \Stringable {
            public function __toString(): string
            {
                return 'custom_str';
            }
        };

        $result = ProtoUtils::makeDictSerializable([
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'none' => null,
            'custom' => $custom,
            'list' => [1, 'two', $custom],
            'nested' => ['inner_custom' => $custom, 'inner_normal' => 'value'],
            'opaque' => new \ArrayObject(),
        ]);

        self::assertSame([
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'none' => null,
            'custom' => 'custom_str',
            'list' => [1, 'two', 'custom_str'],
            'nested' => ['inner_custom' => 'custom_str', 'inner_normal' => 'value'],
            'opaque' => 'ArrayObject',
        ], $result);
    }

    public function testNormalizeLargeIntegersToStrings(): void
    {
        // Python uses 9999999999999999999, which does not fit a PHP int.
        $large = 999999999999999999;
        $result = ProtoUtils::normalizeLargeIntegersToStrings([
            'small_int' => 42,
            'large_int' => $large,
            'negative_large' => -$large,
            'float' => 3.14,
            'string' => 'hello',
            'list' => [123, $large, 'text'],
            'nested' => ['inner_large' => $large, 'inner_small' => 100],
        ]);

        self::assertSame([
            'small_int' => 42,
            'large_int' => '999999999999999999',
            'negative_large' => '-999999999999999999',
            'float' => 3.14,
            'string' => 'hello',
            'list' => [123, '999999999999999999', 'text'],
            'nested' => ['inner_large' => '999999999999999999', 'inner_small' => 100],
        ], $result);
    }

    public function testParseStringIntegersInDict(): void
    {
        $result = ProtoUtils::parseStringIntegersInDict([
            'regular_string' => 'hello',
            'numeric_string_small' => '123',
            'numeric_string_large' => '999999999999999999',
            'negative_large_string' => '-999999999999999999',
            'too_large_for_php' => '9999999999999999999',
            'float_string' => '3.14',
            'mixed_string' => '123abc',
            'int' => 42,
            'list' => ['hello', '999999999999999999', '123'],
            'nested' => ['inner_large_string' => '999999999999999999', 'inner_regular' => 'value'],
        ]);

        self::assertSame([
            'regular_string' => 'hello',
            'numeric_string_small' => '123',
            'numeric_string_large' => 999999999999999999,
            'negative_large_string' => -999999999999999999,
            'too_large_for_php' => '9999999999999999999',
            'float_string' => '3.14',
            'mixed_string' => '123abc',
            'int' => 42,
            'list' => ['hello', 999999999999999999, '123'],
            'nested' => ['inner_large_string' => 999999999999999999, 'inner_regular' => 'value'],
        ], $result);
    }

    // --- Struct / Value ---

    public function testValueRoundTrip(): void
    {
        $data = ['key' => 'value', 'count' => 42, 'ratio' => 0.5, 'ok' => true, 'none' => null, 'list' => [1, 'two', ['x' => 1]]];

        self::assertSame($data, ProtoUtils::fromValue(ProtoUtils::toValue($data)));
        self::assertSame([1, 2, 3], ProtoUtils::fromValue(ProtoUtils::toValue([1, 2, 3])));
        self::assertSame('str', ProtoUtils::fromValue(ProtoUtils::toValue('str')));
        self::assertNull(ProtoUtils::fromValue(ProtoUtils::toValue(null)));
    }

    public function testEmptyArrayIsAListAndStdClassIsAnObject(): void
    {
        self::assertSame('list_value', ProtoUtils::toValue([])->getKind());
        self::assertSame('struct_value', ProtoUtils::toValue(new \stdClass())->getKind());
    }

    public function testToValueRejectsWhatJsonCannotHold(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProtoUtils::toValue(NAN);
    }

    public function testToValueRejectsArbitraryObjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProtoUtils::toValue(new \ArrayObject());
    }

    public function testToValueUsesJsonSerializable(): void
    {
        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['a' => 1];
            }
        };

        self::assertSame(['a' => 1], ProtoUtils::fromValue(ProtoUtils::toValue($object)));
    }

    // --- REST params ---

    public function testRestParamsRoundTrip(): void
    {
        $timestamp = new Timestamp();
        $timestamp->mergeFromJsonString('"2024-03-09T16:00:00Z"');
        $original = new ListTasksRequest([
            'tenant' => 'tenant-1',
            'context_id' => 'ctx-1',
            'status' => TaskState::TASK_STATE_WORKING,
            'page_size' => 10,
            'include_artifacts' => true,
            'status_timestamp_after' => $timestamp,
            'history_length' => 5,
        ]);

        $query = http_build_query([
            'tenant' => 'tenant-1',
            'contextId' => 'ctx-1',
            'status' => 'TASK_STATE_WORKING',
            'pageSize' => '10',
            'includeArtifacts' => 'true',
            'statusTimestampAfter' => '2024-03-09T16:00:00Z',
            'historyLength' => '5',
            'unknownParam' => 'ignored',
        ]);

        $converted = new ListTasksRequest();
        ProtoUtils::parseParams($query, $converted);

        self::assertSame($original->serializeToString(), $converted->serializeToString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function repeatedQueryStrings(): iterable
    {
        yield 'repeated keys' => ['id=skill-1&tags=tag1&tags=tag2&tags=tag3'];
        yield 'comma separated' => ['id=skill-1&tags=tag1,tag2,tag3'];
    }

    #[DataProvider('repeatedQueryStrings')]
    public function testRepeatedFieldsParsing(string $query): void
    {
        $converted = new AgentSkill();
        ProtoUtils::parseParams($query, $converted);

        $expected = new AgentSkill(['id' => 'skill-1', 'tags' => ['tag1', 'tag2', 'tag3']]);
        self::assertSame($expected->serializeToString(), $converted->serializeToString());
    }

    public function testParamsAsArray(): void
    {
        $converted = new AgentSkill();
        ProtoUtils::parseParams(['id' => ['first', 'skill-1'], 'tags' => ['a,b', '', 'c']], $converted);

        self::assertSame('skill-1', $converted->getId());
        self::assertSame(['a', 'b', 'c'], iterator_to_array($converted->getTags(), false));
    }

    public function testFalseBooleanParam(): void
    {
        $converted = new ListTasksRequest();
        ProtoUtils::parseParams('includeArtifacts=FALSE', $converted);

        self::assertTrue($converted->hasIncludeArtifacts());
        self::assertFalse($converted->getIncludeArtifacts());
    }

    // --- required fields ---

    public function testValidRequiredFields(): void
    {
        $this->expectNotToPerformAssertions();

        ProtoUtils::validateProtoRequiredFields(new Message(['message_id' => 'msg-1', 'role' => Role::ROLE_USER, 'parts' => [new Part(['text' => 'hello'])]]));
    }

    public function testMissingRequiredFields(): void
    {
        $fields = array_column($this->violations(new Message()), 'field');

        self::assertEqualsCanonicalizing(['message_id', 'role', 'parts'], $fields);
    }

    public function testMissingRequiredFieldsMessages(): void
    {
        $byField = array_column($this->violations(new Message()), 'message', 'field');

        self::assertSame('Field is required.', $byField['message_id']);
        self::assertSame('Field is required.', $byField['role']);
        self::assertSame('Field must contain at least one element.', $byField['parts']);
    }

    public function testRepeatedNestedMessageValidation(): void
    {
        $task = new Task([
            'id' => 'task-1',
            'context_id' => 'ctx-1',
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_WORKING]),
            'history' => [new Message()],
        ]);
        $fields = array_column($this->violations($task), 'field');

        self::assertContains('history[0].message_id', $fields);
        self::assertContains('history[0].role', $fields);
        self::assertContains('history[0].parts', $fields);
    }

    public function testNestedRequiredFields(): void
    {
        $fields = array_column($this->violations(new Task(['id' => 'task-1', 'status' => new TaskStatus()])), 'field');

        self::assertContains('status.state', $fields);
    }

    public function testMissingMessageFieldIsReported(): void
    {
        // Task.status has presence: unset (null) is a violation, not a recursion.
        $fields = array_column($this->violations(new Task(['id' => 'task-1'])), 'field');

        self::assertSame(['status'], $fields);
    }

    public function testMapFieldValuesAreValidated(): void
    {
        $card = new AgentCard();
        $card->getSecuritySchemes()['broken'] = new SecurityScheme(['api_key_security_scheme' => new APIKeySecurityScheme()]);
        $fields = array_column($this->violations($card), 'field');

        self::assertContains('security_schemes[broken].api_key_security_scheme.location', $fields);
        self::assertContains('security_schemes[broken].api_key_security_scheme.name', $fields);
    }

    public function testStructValuesDoNotTripValidation(): void
    {
        $extension = new AgentExtension(['uri' => 'x', 'params' => ProtoUtils::toStruct(['nested' => ['a' => 1]])]);
        $card = new AgentCard(['capabilities' => new AgentCapabilities(['extensions' => [$extension]])]);
        $fields = array_column($this->violations($card), 'field');

        self::assertNotContains('capabilities.extensions[0].params', $fields);
        self::assertContains('name', $fields);
    }

    public function testRequiredFieldsTableMatchesTheProto(): void
    {
        // Spot checks against a2a.proto v1.0.0; the table is generated.
        self::assertSame(['message_id', 'role', 'parts'], RequiredFields::forMessage('lf.a2a.v1.Message'));
        self::assertSame(['id', 'status'], RequiredFields::forMessage('lf.a2a.v1.Task'));
        self::assertSame(['id', 'name', 'description', 'tags'], RequiredFields::forMessage('lf.a2a.v1.AgentSkill'));
        self::assertSame([], RequiredFields::forMessage('lf.a2a.v1.StringList'));
        self::assertSame([], RequiredFields::forMessage('google.protobuf.Struct'));
        self::assertCount(28, RequiredFields::BY_MESSAGE);
    }

    public function testBadRequestConversionsRoundTrip(): void
    {
        $errors = [['field' => 'a', 'message' => 'x'], ['field' => 'b.c', 'message' => 'y']];

        self::assertSame($errors, ProtoUtils::badRequestToValidationErrors(ProtoUtils::validationErrorsToBadRequest($errors)));
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function violations(\Google\Protobuf\Internal\Message $message): array
    {
        try {
            ProtoUtils::validateProtoRequiredFields($message);
        } catch (InvalidParamsError $e) {
            self::assertSame('Validation failed', $e->getMessage());
            $errors = $e->data['errors'] ?? [];
            self::assertIsArray($errors);

            /** @var list<array{field: string, message: string}> $errors */
            return $errors;
        }

        self::fail('expected InvalidParamsError');
    }
}
