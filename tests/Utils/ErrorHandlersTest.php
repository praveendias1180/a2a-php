<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Types\Part;
use A2A\Utils\ErrorHandlers;
use A2A\Utils\Errors\A2AError;
use A2A\Utils\Errors\InternalError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\InvalidRequestError;
use A2A\Utils\Errors\JSONParseError;
use A2A\Utils\Errors\TaskNotFoundError;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/utils/test_error_handlers.py.
 *
 * Python's tests go through the rest_error_handler decorators and a mocked
 * Starlette response; the PHP builders return the same payload directly.
 */
final class ErrorHandlersTest extends TestCase
{
    public function testRestPayloadForA2AError(): void
    {
        $payload = ErrorHandlers::buildRestErrorPayload(new InvalidRequestError('Bad request'));

        self::assertSame(400, ErrorHandlers::restStatusCode(new InvalidRequestError('Bad request')));
        self::assertJsonStringEqualsJsonString(
            (string) json_encode([
                'error' => [
                    'code' => 400,
                    'status' => 'INVALID_ARGUMENT',
                    'message' => 'Bad request',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                        'reason' => 'INVALID_REQUEST',
                        'domain' => 'a2a-protocol.org',
                        'metadata' => new \stdClass(),
                    ]],
                ],
            ]),
            (string) json_encode($payload),
        );
    }

    public function testRestPayloadForUnknownExceptionHidesTheMessage(): void
    {
        $payload = ErrorHandlers::buildRestErrorPayload(new \ValueError('Unexpected error'));

        self::assertSame(['error' => ['code' => 500, 'status' => 'INTERNAL', 'message' => 'unknown exception']], $payload);
        self::assertSame(500, ErrorHandlers::restStatusCode(new \RuntimeException('Stream failed')));
    }

    public function testRestPayloadForInternalError(): void
    {
        self::assertSame(500, ErrorHandlers::restStatusCode(new InternalError('Internal server error')));
    }

    public function testRestPayloadForProtoJsonParseFailure(): void
    {
        try {
            (new Part())->mergeFromJsonString('{"kind":"text"}');
            self::fail('expected a decode failure');
        } catch (\Exception $e) {
            $payload = ErrorHandlers::buildRestErrorPayload($e);
        }

        self::assertSame(400, $payload['error']['code']);
        self::assertSame('INVALID_ARGUMENT', $payload['error']['status']);
        self::assertSame('INVALID_REQUEST', self::firstDetail($payload)['reason']);
    }

    public function testRestPayloadForErrorWithoutMappingDefaultsToInternal(): void
    {
        // JSONParseError only has a JSON-RPC code; Python falls back the same way.
        $payload = ErrorHandlers::buildRestErrorPayload(new JSONParseError());

        self::assertSame(500, $payload['error']['code']);
        self::assertSame('INTERNAL', $payload['error']['status']);
        self::assertSame('UNKNOWN_ERROR', self::firstDetail($payload)['reason']);
    }

    public function testInvalidParamsIncludesBadRequest(): void
    {
        // a2aproject/A2A#1627: REST must carry ErrorInfo AND BadRequest, like gRPC.
        $errors = [
            ['field' => 'message.parts', 'message' => 'At least one required'],
            ['field' => 'message.role', 'message' => 'Unknown role'],
        ];
        $payload = ErrorHandlers::buildRestErrorPayload(new InvalidParamsError('Validation failed', ['errors' => $errors]));

        self::assertSame(400, $payload['error']['code']);
        self::assertSame('INVALID_ARGUMENT', $payload['error']['status']);
        self::assertSame('Validation failed', $payload['error']['message']);

        $details = $payload['error']['details'];
        self::assertIsArray($details);
        self::assertCount(2, $details);
        self::assertSame([
            '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
            'reason' => 'INVALID_PARAMS',
            'domain' => 'a2a-protocol.org',
            'metadata' => ['errors' => $errors],
        ], $details[0]);
        self::assertSame([
            '@type' => 'type.googleapis.com/google.rpc.BadRequest',
            'fieldViolations' => [
                ['field' => 'message.parts', 'description' => 'At least one required'],
                ['field' => 'message.role', 'description' => 'Unknown role'],
            ],
        ], $details[1]);
    }

    public function testInvalidParamsWithoutValidationErrorsHasOnlyErrorInfo(): void
    {
        $payload = ErrorHandlers::buildRestErrorPayload(new InvalidParamsError('Bad params'));

        self::assertIsArray($payload['error']['details']);
        self::assertCount(1, $payload['error']['details']);
        self::assertSame('type.googleapis.com/google.rpc.ErrorInfo', self::firstDetail($payload)['@type']);
    }

    public function testJsonRpcErrorForA2AError(): void
    {
        $error = ErrorHandlers::buildJsonRpcError(new TaskNotFoundError(null, ['taskId' => 't-1']));

        self::assertSame(-32001, $error['code']);
        self::assertSame('Task not found', $error['message']);
        self::assertSame([[
            '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
            'reason' => 'TASK_NOT_FOUND',
            'domain' => 'a2a-protocol.org',
            'metadata' => ['taskId' => 't-1'],
        ]], $error['data'] ?? null);
    }

    public function testJsonRpcErrorForUnmappedA2AErrorIsInternal(): void
    {
        self::assertSame(-32603, ErrorHandlers::buildJsonRpcError(new A2AError('boom'))['code']);
    }

    public function testJsonRpcErrorForOtherExceptionsHidesTheMessage(): void
    {
        // Differs from Python on purpose: never send internal exception text to the caller.
        $error = ErrorHandlers::buildJsonRpcError(new \LogicException('SQLSTATE[08006] connection to db-internal:5432 failed'));

        self::assertSame(['code' => -32603, 'message' => 'Internal error'], $error);
    }

    /**
     * @param array{error: array<string, mixed>} $payload
     *
     * @return array<array-key, mixed>
     */
    private static function firstDetail(array $payload): array
    {
        $details = $payload['error']['details'] ?? null;
        self::assertIsArray($details);
        self::assertIsArray($details[0] ?? null);

        return $details[0];
    }
}
