<?php

declare(strict_types=1);

namespace A2A\Utils;

use A2A\Types\Message;
use A2A\Types\Meta\RequiredFields;
use A2A\Types\StreamResponse;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Errors\InvalidParamsError;
use Google\Protobuf\Descriptor;
use Google\Protobuf\DescriptorPool;
use Google\Protobuf\FieldDescriptor;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\Message as ProtobufMessage;
use Google\Protobuf\ListValue;
use Google\Protobuf\NullValue;
use Google\Protobuf\Struct;
use Google\Protobuf\Value;
use Google\Rpc\BadRequest;
use Google\Rpc\BadRequest\FieldViolation;

/**
 * Utilities for working with proto types.
 *
 * Mirrors a2a-python: src/a2a/utils/proto_utils.py
 *
 * @phpstan-type ValidationDetail array{field: string, message: string}
 */
final class ProtoUtils
{
    private function __construct() {}

    /**
     * Wraps an event in the StreamResponse oneof.
     */
    public static function toStreamResponse(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): StreamResponse
    {
        $response = new StreamResponse();
        if ($event instanceof Task) {
            $response->setTask($event);
        } elseif ($event instanceof Message) {
            $response->setMessage($event);
        } elseif ($event instanceof TaskStatusUpdateEvent) {
            $response->setStatusUpdate($event);
        } else {
            $response->setArtifactUpdate($event);
        }

        return $response;
    }

    /**
     * Converts values that have no JSON form into strings, recursively.
     *
     * Python falls back to `str(value)`. PHP objects have no universal string
     * form, so Stringable and enums are converted and anything else becomes
     * its type name.
     */
    public static function makeDictSerializable(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(self::makeDictSerializable(...), $value);
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return get_debug_type($value);
    }

    /**
     * Converts integers above JavaScript's safe range to strings, recursively.
     *
     * PHP ints are 64-bit, so values past PHP_INT_MAX are already floats before
     * they reach this function; only real ints are converted, as in Python.
     */
    public static function normalizeLargeIntegersToStrings(mixed $value, int $maxSafeDigits = 15): mixed
    {
        $maxSafeInt = 10 ** $maxSafeDigits - 1;
        if (is_int($value)) {
            return abs($value) > $maxSafeInt ? (string) $value : $value;
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::normalizeLargeIntegersToStrings($item, $maxSafeDigits), $value);
        }

        return $value;
    }

    /**
     * Converts long all-digit strings back to integers, recursively.
     *
     * A string past PHP_INT_MAX cannot become an int in PHP, so it stays a
     * string (Python has arbitrary-precision ints and converts it).
     */
    public static function parseStringIntegersInDict(mixed $value, int $maxSafeDigits = 15): mixed
    {
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::parseStringIntegersInDict($item, $maxSafeDigits), $value);
        }
        if (is_string($value)) {
            $digits = ltrim($value, '-');
            if ($digits !== '' && ctype_digit($digits) && strlen($digits) > $maxSafeDigits) {
                $asInt = filter_var($value, FILTER_VALIDATE_INT);
                if ($asInt !== false) {
                    return $asInt;
                }
            }
        }

        return $value;
    }

    /**
     * Converts a PHP value to google.protobuf.Value.
     *
     * An empty PHP array is ambiguous; it becomes an empty list, as json_encode
     * does. Pass `new \stdClass()` for an empty object.
     */
    public static function toValue(mixed $data): Value
    {
        $value = new Value();
        if ($data === null) {
            $value->setNullValue(NullValue::NULL_VALUE);
        } elseif (is_bool($data)) {
            $value->setBoolValue($data);
        } elseif (is_int($data) || is_float($data)) {
            if (is_float($data) && !is_finite($data)) {
                throw new \InvalidArgumentException('google.protobuf.Value cannot hold NaN or Infinity');
            }
            $value->setNumberValue((float) $data);
        } elseif (is_string($data)) {
            $value->setStringValue($data);
        } elseif (is_array($data) && array_is_list($data)) {
            $list = new ListValue();
            $list->setValues(array_map(self::toValue(...), $data));
            $value->setListValue($list);
        } elseif (is_array($data) || $data instanceof \stdClass) {
            $value->setStructValue(self::toStruct((array) $data));
        } elseif ($data instanceof \JsonSerializable) {
            return self::toValue($data->jsonSerialize());
        } else {
            throw new \InvalidArgumentException(get_debug_type($data) . ' has no google.protobuf.Value representation');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function toStruct(array $data): Struct
    {
        $struct = new Struct();
        $fields = $struct->getFields();
        foreach ($data as $key => $item) {
            $fields[(string) $key] = self::toValue($item);
        }

        return $struct;
    }

    /**
     * Converts google.protobuf.Value back to a PHP value.
     *
     * Value stores every number as a double. Integral numbers inside the
     * exactly-representable range come back as int, matching what json_decode
     * would give for the same JSON (Python returns float and relies on 1 == 1.0).
     */
    public static function fromValue(Value $value): mixed
    {
        return match ($value->getKind()) {
            'number_value' => self::fromNumber($value->getNumberValue()),
            'string_value' => $value->getStringValue(),
            'bool_value' => $value->getBoolValue(),
            'struct_value' => self::fromStruct($value->getStructValue() ?? new Struct()),
            'list_value' => array_map(
                self::fromValue(...),
                iterator_to_array(($value->getListValue() ?? new ListValue())->getValues(), false),
            ),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromStruct(Struct $struct): array
    {
        $out = [];
        foreach ($struct->getFields() as $key => $item) {
            $out[is_scalar($key) ? (string) $key : ''] = $item instanceof Value ? self::fromValue($item) : null;
        }

        return $out;
    }

    /**
     * Splits a raw query string into name => list of values, keeping repeated
     * keys (`tags=a&tags=b`). PHP's parse_str() and PSR-7 getQueryParams()
     * keep only the last one, so pass `$request->getUri()->getQuery()` here.
     *
     * @return array<string, list<string>>
     */
    public static function parseQueryString(string $query): array
    {
        $params = [];
        foreach (explode('&', ltrim($query, '?')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($name)][] = urldecode($value);
        }

        return $params;
    }

    /**
     * Converts REST query parameters into a protobuf message.
     *
     * - Booleans: 'true'/'false' become true/false.
     * - Repeated fields: both repeated keys and comma-separated values.
     * - Everything else goes through ProtoJSON (enums, timestamps, numbers).
     * - Unknown parameters are ignored.
     *
     * @param string|array<string, string|list<string>> $params a raw query string, or name => value(s)
     *
     * @see https://a2a-protocol.org/latest/specification/#115-query-parameter-naming-for-request-parameters
     */
    public static function parseParams(string|array $params, ProtobufMessage $message): void
    {
        if (is_string($params)) {
            $params = self::parseQueryString($params);
        }

        $fields = [];
        foreach (self::fieldsOf($message) as $field) {
            $fields[self::jsonName($field->getName())] = $field;
        }

        $processed = [];
        foreach ($params as $key => $values) {
            if (!isset($fields[$key])) {
                continue;
            }
            $field = $fields[$key];
            $values = is_array($values) ? $values : [$values];

            if ($field->isRepeated()) {
                $accumulated = [];
                foreach ($values as $value) {
                    if ($value === '') {
                        continue;
                    }
                    foreach (explode(',', $value) as $part) {
                        if ($part !== '') {
                            $accumulated[] = $part;
                        }
                    }
                }
                $processed[$key] = $accumulated;
            } elseif ($values !== []) {
                // For non-repeated fields, the last one wins.
                $raw = $values[array_key_last($values)];
                $processed[$key] = $field->getType() === GPBType::BOOL ? strtolower($raw) === 'true' : $raw;
            }
        }

        $message->mergeFromJsonString(JsonUtils::dumps((object) $processed), true);
    }

    /**
     * Throws InvalidParamsError listing every REQUIRED field that is missing,
     * including inside nested messages, lists and maps.
     *
     * Field paths use proto names, e.g. `history[0].message_id`, as in Python.
     *
     * @throws InvalidParamsError
     */
    public static function validateProtoRequiredFields(ProtobufMessage $message): void
    {
        $errors = self::validateRequiredFieldsInternal($message);
        if ($errors !== []) {
            throw new InvalidParamsError('Validation failed', ['errors' => $errors]);
        }
    }

    /**
     * @param list<ValidationDetail> $errors
     */
    public static function validationErrorsToBadRequest(array $errors): BadRequest
    {
        $violations = [];
        foreach ($errors as $error) {
            $violations[] = new FieldViolation(['field' => $error['field'], 'description' => $error['message']]);
        }

        return new BadRequest(['field_violations' => $violations]);
    }

    /**
     * @return list<ValidationDetail>
     */
    public static function badRequestToValidationErrors(BadRequest $badRequest): array
    {
        $errors = [];
        foreach ($badRequest->getFieldViolations() as $violation) {
            $errors[] = ['field' => $violation->getField(), 'message' => $violation->getDescription()];
        }

        return $errors;
    }

    /**
     * @return list<ValidationDetail>
     */
    private static function validateRequiredFieldsInternal(ProtobufMessage $message): array
    {
        /** @var Descriptor|null $descriptor not generated for non-proto classes, despite the PHPDoc */
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($message::class);
        if ($descriptor === null) {
            return [];
        }
        $required = RequiredFields::forMessage($descriptor->getFullName());

        $errors = [];
        foreach (self::fieldsOf($message) as $field) {
            if (in_array($field->getName(), $required, true)) {
                $violation = self::checkRequiredFieldViolation($message, $field);
                if ($violation !== null) {
                    $errors[] = $violation;
                }
            }
            array_push($errors, ...self::recurseValidation($message, $field));
        }

        return $errors;
    }

    /**
     * @return ValidationDetail|null
     */
    private static function checkRequiredFieldViolation(ProtobufMessage $message, FieldDescriptor $field): ?array
    {
        $value = self::read($message, $field);
        if ($field->isRepeated()) {
            if (!is_countable($value) || count($value) === 0) {
                return ['field' => $field->getName(), 'message' => 'Field must contain at least one element.'];
            }

            return null;
        }
        if (self::fieldHasPresence($message, $field)) {
            if (!self::hasField($message, $field)) {
                return ['field' => $field->getName(), 'message' => 'Field is required.'];
            }

            return null;
        }
        if ($value === '' || $value === 0 || $value === false || $value === 0.0) {
            return ['field' => $field->getName(), 'message' => 'Field is required.'];
        }

        return null;
    }

    /**
     * @return list<ValidationDetail>
     */
    private static function recurseValidation(ProtobufMessage $message, FieldDescriptor $field): array
    {
        if ($field->getType() !== GPBType::MESSAGE) {
            return [];
        }

        $errors = [];
        $value = self::read($message, $field);
        if ($field->isMap()) {
            if ($value instanceof MapField) {
                foreach ($value as $key => $item) {
                    if ($item instanceof ProtobufMessage && is_scalar($key)) {
                        self::appendNestedErrors($errors, $field->getName() . '[' . $key . ']', self::validateRequiredFieldsInternal($item));
                    }
                }
            }
        } elseif ($field->isRepeated()) {
            if (is_iterable($value)) {
                $index = 0;
                foreach ($value as $item) {
                    if ($item instanceof ProtobufMessage) {
                        self::appendNestedErrors($errors, $field->getName() . '[' . $index . ']', self::validateRequiredFieldsInternal($item));
                    }
                    $index++;
                }
            }
        } elseif ($value instanceof ProtobufMessage) {
            self::appendNestedErrors($errors, $field->getName(), self::validateRequiredFieldsInternal($value));
        }

        return $errors;
    }

    /**
     * @param list<ValidationDetail> $errors
     * @param list<ValidationDetail> $nested
     */
    private static function appendNestedErrors(array &$errors, string $prefix, array $nested): void
    {
        foreach ($nested as $sub) {
            $errors[] = [
                'field' => $sub['field'] !== '' ? $prefix . '.' . $sub['field'] : $prefix,
                'message' => $sub['message'],
            ];
        }
    }

    /**
     * @return list<FieldDescriptor>
     */
    private static function fieldsOf(ProtobufMessage $message): array
    {
        /** @var Descriptor|null $descriptor not generated for non-proto classes, despite the PHPDoc */
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($message::class);
        if ($descriptor === null) {
            return [];
        }
        $fields = [];
        for ($i = 0; $i < $descriptor->getFieldCount(); $i++) {
            $fields[] = $descriptor->getField($i);
        }

        return $fields;
    }

    /**
     * Whether a singular field tracks presence: message fields, oneof members
     * and proto3 `optional` fields. Read off the generated code (message type,
     * or a generated hasX()) rather than FieldDescriptor::hasPresence(), which
     * google/protobuf 4.x does not have. Python's _field_is_repeated is a shim
     * for the same kind of protobuf version gap.
     */
    private static function fieldHasPresence(ProtobufMessage $message, FieldDescriptor $field): bool
    {
        return $field->getType() === GPBType::MESSAGE
            || method_exists($message, 'has' . self::studly($field->getName()));
    }

    private static function read(ProtobufMessage $message, FieldDescriptor $field): mixed
    {
        $getter = 'get' . self::studly($field->getName());

        return method_exists($message, $getter) ? $message->{$getter}() : null;
    }

    private static function hasField(ProtobufMessage $message, FieldDescriptor $field): bool
    {
        // Singular message fields read as null when unset; `optional` scalars
        // and oneof members get a generated hasX().
        $hasser = 'has' . self::studly($field->getName());
        if (method_exists($message, $hasser)) {
            return (bool) $message->{$hasser}();
        }

        return self::read($message, $field) !== null;
    }

    private static function fromNumber(float $number): int|float
    {
        $safe = 9007199254740991.0; // 2**53 - 1
        if (floor($number) === $number && abs($number) <= $safe) {
            return (int) $number;
        }

        return $number;
    }

    private static function studly(string $protoName): string
    {
        return str_replace('_', '', ucwords($protoName, '_'));
    }

    /**
     * protoc's default json_name: drop underscores, capitalize the next letter.
     */
    private static function jsonName(string $protoName): string
    {
        $out = '';
        $upperNext = false;
        foreach (str_split($protoName) as $char) {
            if ($char === '_') {
                $upperNext = true;
            } else {
                $out .= $upperNext ? strtoupper($char) : $char;
                $upperNext = false;
            }
        }

        return $out;
    }
}
