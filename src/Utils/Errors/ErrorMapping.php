<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * HTTP status, gRPC status and ErrorInfo reason for an A2A error, plus the
 * JSON-RPC code table.
 *
 * Mirrors a2a-python: ErrorMapping, JSON_RPC_ERROR_CODE_MAP, A2A_ERROR_MAPPING,
 * A2A_ERROR_REASONS and A2A_REASON_TO_ERROR in src/a2a/utils/errors.py.
 * Python exposes module-level dicts; PHP constants cannot hold objects, so the
 * tables are const arrays and ErrorMapping::for() builds the value object.
 */
final class ErrorMapping
{
    /** @var array<class-string<A2AError>, int> */
    public const JSON_RPC_ERROR_CODE_MAP = [
        TaskNotFoundError::class => -32001,
        TaskNotCancelableError::class => -32002,
        PushNotificationNotSupportedError::class => -32003,
        UnsupportedOperationError::class => -32004,
        ContentTypeNotSupportedError::class => -32005,
        InvalidAgentResponseError::class => -32006,
        ExtendedAgentCardNotConfiguredError::class => -32007,
        ExtensionSupportRequiredError::class => -32008,
        VersionNotSupportedError::class => -32009,
        InvalidParamsError::class => -32602,
        InvalidRequestError::class => -32600,
        MethodNotFoundError::class => -32601,
        InternalError::class => -32603,
        JSONParseError::class => -32700,
    ];

    /**
     * [http code, gRPC status, ErrorInfo reason]. JSONParseError has no entry,
     * exactly as in Python: it only exists on the JSON-RPC transport.
     *
     * @var array<class-string<A2AError>, array{int, string, string}>
     */
    public const A2A_ERROR_MAPPING = [
        TaskNotFoundError::class => [404, 'NOT_FOUND', 'TASK_NOT_FOUND'],
        TaskNotCancelableError::class => [400, 'FAILED_PRECONDITION', 'TASK_NOT_CANCELABLE'],
        PushNotificationNotSupportedError::class => [400, 'FAILED_PRECONDITION', 'PUSH_NOTIFICATION_NOT_SUPPORTED'],
        UnsupportedOperationError::class => [400, 'FAILED_PRECONDITION', 'UNSUPPORTED_OPERATION'],
        ContentTypeNotSupportedError::class => [400, 'INVALID_ARGUMENT', 'CONTENT_TYPE_NOT_SUPPORTED'],
        InvalidAgentResponseError::class => [500, 'INTERNAL', 'INVALID_AGENT_RESPONSE'],
        ExtendedAgentCardNotConfiguredError::class => [400, 'FAILED_PRECONDITION', 'EXTENDED_AGENT_CARD_NOT_CONFIGURED'],
        ExtensionSupportRequiredError::class => [400, 'FAILED_PRECONDITION', 'EXTENSION_SUPPORT_REQUIRED'],
        VersionNotSupportedError::class => [400, 'FAILED_PRECONDITION', 'VERSION_NOT_SUPPORTED'],
        InvalidParamsError::class => [400, 'INVALID_ARGUMENT', 'INVALID_PARAMS'],
        InvalidRequestError::class => [400, 'INVALID_ARGUMENT', 'INVALID_REQUEST'],
        MethodNotFoundError::class => [404, 'NOT_FOUND', 'METHOD_NOT_FOUND'],
        InternalError::class => [500, 'INTERNAL', 'INTERNAL_ERROR'],
    ];

    public function __construct(
        public readonly int $httpCode,
        public readonly string $grpcStatus,
        public readonly string $reason,
    ) {}

    /**
     * Mapping for an error class. Like Python, lookup is by exact class, so a
     * user subclass of TaskNotFoundError has no mapping of its own.
     */
    public static function for(string $errorClass): ?self
    {
        $row = self::A2A_ERROR_MAPPING[$errorClass] ?? null;

        return $row === null ? null : new self($row[0], $row[1], $row[2]);
    }

    public static function jsonRpcCodeFor(string $errorClass): ?int
    {
        return self::JSON_RPC_ERROR_CODE_MAP[$errorClass] ?? null;
    }

    /**
     * @return class-string<A2AError>|null
     */
    public static function errorClassForJsonRpcCode(int $code): ?string
    {
        $class = array_search($code, self::JSON_RPC_ERROR_CODE_MAP, true);

        return $class === false ? null : $class;
    }

    public static function reasonFor(string $errorClass): ?string
    {
        return self::A2A_ERROR_MAPPING[$errorClass][2] ?? null;
    }

    /**
     * @return class-string<A2AError>|null
     */
    public static function errorClassForReason(string $reason): ?string
    {
        foreach (self::A2A_ERROR_MAPPING as $class => $row) {
            if ($row[2] === $reason) {
                return $class;
            }
        }

        return null;
    }
}
