<?php

declare(strict_types=1);

namespace A2A\Tests\Utils\Errors;

use A2A\Utils\Errors\A2AError;
use A2A\Utils\Errors\ContentTypeNotSupportedError;
use A2A\Utils\Errors\ErrorMapping;
use A2A\Utils\Errors\ExtendedAgentCardNotConfiguredError;
use A2A\Utils\Errors\ExtensionSupportRequiredError;
use A2A\Utils\Errors\InternalError;
use A2A\Utils\Errors\InvalidAgentResponseError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\InvalidRequestError;
use A2A\Utils\Errors\JSONParseError;
use A2A\Utils\Errors\MethodNotFoundError;
use A2A\Utils\Errors\PushNotificationNotSupportedError;
use A2A\Utils\Errors\TaskNotCancelableError;
use A2A\Utils\Errors\TaskNotFoundError;
use A2A\Utils\Errors\UnsupportedOperationError;
use A2A\Utils\Errors\VersionNotSupportedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The code tables must match a2a-python's src/a2a/utils/errors.py exactly:
 * clients of either SDK decode the other's errors by these numbers.
 */
final class ErrorMappingTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<A2AError>, int, int|null, string|null, string|null, string}>
     */
    public static function errors(): iterable
    {
        // class, JSON-RPC code, HTTP code, gRPC status, reason, default message
        yield 'TaskNotFound' => [TaskNotFoundError::class, -32001, 404, 'NOT_FOUND', 'TASK_NOT_FOUND', 'Task not found'];
        yield 'TaskNotCancelable' => [TaskNotCancelableError::class, -32002, 409, 'FAILED_PRECONDITION', 'TASK_NOT_CANCELABLE', 'Task cannot be canceled'];
        yield 'PushNotSupported' => [PushNotificationNotSupportedError::class, -32003, 400, 'FAILED_PRECONDITION', 'PUSH_NOTIFICATION_NOT_SUPPORTED', 'Push Notification is not supported'];
        yield 'UnsupportedOperation' => [UnsupportedOperationError::class, -32004, 400, 'FAILED_PRECONDITION', 'UNSUPPORTED_OPERATION', 'This operation is not supported'];
        yield 'ContentTypeNotSupported' => [ContentTypeNotSupportedError::class, -32005, 400, 'INVALID_ARGUMENT', 'CONTENT_TYPE_NOT_SUPPORTED', 'Incompatible content types'];
        yield 'InvalidAgentResponse' => [InvalidAgentResponseError::class, -32006, 500, 'INTERNAL', 'INVALID_AGENT_RESPONSE', 'Invalid agent response'];
        yield 'ExtendedCardNotConfigured' => [ExtendedAgentCardNotConfiguredError::class, -32007, 400, 'FAILED_PRECONDITION', 'EXTENDED_AGENT_CARD_NOT_CONFIGURED', 'Authenticated Extended Card is not configured'];
        yield 'ExtensionSupportRequired' => [ExtensionSupportRequiredError::class, -32008, 400, 'FAILED_PRECONDITION', 'EXTENSION_SUPPORT_REQUIRED', 'Extension support required'];
        yield 'VersionNotSupported' => [VersionNotSupportedError::class, -32009, 400, 'FAILED_PRECONDITION', 'VERSION_NOT_SUPPORTED', 'Version not supported'];
        yield 'InvalidParams' => [InvalidParamsError::class, -32602, 400, 'INVALID_ARGUMENT', 'INVALID_PARAMS', 'Invalid params'];
        yield 'InvalidRequest' => [InvalidRequestError::class, -32600, 400, 'INVALID_ARGUMENT', 'INVALID_REQUEST', 'Invalid Request'];
        yield 'MethodNotFound' => [MethodNotFoundError::class, -32601, 404, 'NOT_FOUND', 'METHOD_NOT_FOUND', 'Method not found'];
        yield 'Internal' => [InternalError::class, -32603, 500, 'INTERNAL', 'INTERNAL_ERROR', 'Internal error'];
        yield 'JSONParse (JSON-RPC only)' => [JSONParseError::class, -32700, null, null, null, 'Invalid JSON payload'];
    }

    /**
     * @param class-string<A2AError> $class
     */
    #[DataProvider('errors')]
    public function testCodesAndDefaults(string $class, int $jsonRpcCode, ?int $http, ?string $grpc, ?string $reason, string $message): void
    {
        $error = new $class();

        self::assertInstanceOf(A2AError::class, $error);
        self::assertSame($message, $error->getMessage());
        self::assertNull($error->data);
        self::assertSame($jsonRpcCode, $error->jsonRpcCode());
        self::assertSame($class, ErrorMapping::errorClassForJsonRpcCode($jsonRpcCode));

        $mapping = $error->mapping();
        if ($http === null) {
            self::assertNull($mapping);

            return;
        }
        self::assertNotNull($mapping);
        self::assertSame([$http, $grpc, $reason], [$mapping->httpCode, $mapping->grpcStatus, $mapping->reason]);
        self::assertSame($class, ErrorMapping::errorClassForReason((string) $reason));
    }

    public function testMessageAndDataOverrideDefaults(): void
    {
        $previous = new \RuntimeException('cause');
        $error = new TaskNotFoundError('Task t-1 not found', ['taskId' => 't-1'], $previous);

        self::assertSame('Task t-1 not found', $error->getMessage());
        self::assertSame(['taskId' => 't-1'], $error->data);
        self::assertSame($previous, $error->getPrevious());
    }

    public function testEmptyMessageFallsBackToDefault(): void
    {
        // Python: `if message: self.message = message`.
        self::assertSame('Task not found', (new TaskNotFoundError(''))->getMessage());
    }

    public function testBaseErrorAndUnknownLookups(): void
    {
        $error = new A2AError();

        self::assertSame('A2A Error', $error->getMessage());
        self::assertNull($error->jsonRpcCode());
        self::assertNull($error->mapping());
        self::assertNull(ErrorMapping::errorClassForJsonRpcCode(-1));
        self::assertNull(ErrorMapping::errorClassForReason('NOPE'));
    }

    public function testTablesCoverTheSameClasses(): void
    {
        $withoutJsonParse = array_diff(array_keys(ErrorMapping::JSON_RPC_ERROR_CODE_MAP), [JSONParseError::class]);

        self::assertEqualsCanonicalizing($withoutJsonParse, array_keys(ErrorMapping::A2A_ERROR_MAPPING));
    }
}
