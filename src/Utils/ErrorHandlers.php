<?php

declare(strict_types=1);

namespace A2A\Utils;

use A2A\Utils\Errors\A2AError;
use A2A\Utils\Errors\ErrorMapping;
use A2A\Utils\Errors\InvalidParamsError;
use Google\Protobuf\Internal\GPBDecodeException;

/**
 * Turns exceptions into A2A error bodies for the REST and JSON-RPC transports.
 *
 * Mirrors a2a-python: src/a2a/utils/error_handlers.py (REST payload + typed
 * details) and build_error_response() in
 * src/a2a/server/request_handlers/response_helpers.py (the JSON-RPC error
 * object). Python's rest_error_handler decorators also turn the payload into a
 * Starlette response and log it; in PHP that belongs to the PSR-15
 * dispatchers (phase 3), which call these builders.
 *
 * @phpstan-type ErrorDetail array<string, mixed>
 */
final class ErrorHandlers
{
    public const ERROR_INFO_TYPE = 'type.googleapis.com/google.rpc.ErrorInfo';
    public const BAD_REQUEST_TYPE = 'type.googleapis.com/google.rpc.BadRequest';
    public const A2A_DOMAIN = 'a2a-protocol.org';
    public const JSONRPC_INTERNAL_ERROR_MESSAGE = 'Internal error';

    private function __construct() {}

    /**
     * The typed-details array for an A2AError.
     *
     * Always starts with a google.rpc.ErrorInfo carrying the A2A reason and
     * the error's data as metadata. An InvalidParamsError whose data holds an
     * `errors` list also gets a google.rpc.BadRequest, so every transport
     * reports field-level violations the same way.
     *
     * @return list<ErrorDetail>
     */
    public static function buildErrorDetails(A2AError $error): array
    {
        $reason = ErrorMapping::reasonFor($error::class) ?? 'UNKNOWN_ERROR';
        $details = [self::errorInfo($reason, $error->data ?? [])];

        $errors = $error->data['errors'] ?? null;
        if ($error instanceof InvalidParamsError && is_array($errors) && $errors !== []) {
            /** @var list<array{field: string, message: string}> $errors */
            $badRequest = json_decode(
                ProtoUtils::validationErrorsToBadRequest($errors)->serializeToJsonString(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $details[] = [
                '@type' => self::BAD_REQUEST_TYPE,
                'fieldViolations' => is_array($badRequest) ? ($badRequest['fieldViolations'] ?? []) : [],
            ];
        }

        return $details;
    }

    /**
     * The REST error body: `{"error": {code, status, message, details?}}`.
     *
     * SECURITY: an A2AError's data is sent to the client unaltered. Any other
     * exception is reported as a generic 500 without its message.
     *
     * @return array{error: array<string, mixed>}
     */
    public static function buildRestErrorPayload(\Throwable $error): array
    {
        if ($error instanceof A2AError) {
            $mapping = $error->mapping() ?? new ErrorMapping(500, 'INTERNAL', 'INTERNAL_ERROR');

            return self::payload($mapping->httpCode, $mapping->grpcStatus, $error->getMessage(), self::buildErrorDetails($error));
        }
        if ($error instanceof GPBDecodeException) {
            // PHP's equivalent of google.protobuf.json_format.ParseError.
            return self::payload(400, 'INVALID_ARGUMENT', $error->getMessage(), [self::errorInfo('INVALID_REQUEST')]);
        }

        return self::payload(500, 'INTERNAL', 'unknown exception');
    }

    /**
     * The HTTP status to send with buildRestErrorPayload()'s body.
     */
    public static function restStatusCode(\Throwable $error): int
    {
        $code = self::buildRestErrorPayload($error)['error']['code'] ?? 500;

        return is_int($code) ? $code : 500;
    }

    /**
     * The JSON-RPC `error` member: `{code, message, data?}`.
     *
     * An A2AError gets its JSON-RPC code and the typed details as `data`.
     * Any other exception becomes -32603 with the generic message
     * "Internal error". This deliberately differs from Python, which sends
     * str(error) and so can leak internal details (SQL, paths, hostnames) to
     * the caller. The REST binding already hides it ("unknown exception").
     * Callers log the original exception (the phase-3 dispatchers do).
     *
     * @return array{code: int, message: string, data?: list<ErrorDetail>}
     */
    public static function buildJsonRpcError(\Throwable $error): array
    {
        if ($error instanceof A2AError) {
            return [
                'code' => $error->jsonRpcCode() ?? -32603,
                'message' => $error->getMessage(),
                'data' => self::buildErrorDetails($error),
            ];
        }

        return ['code' => -32603, 'message' => self::JSONRPC_INTERNAL_ERROR_MESSAGE];
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return ErrorDetail
     */
    private static function errorInfo(string $reason, array $metadata = []): array
    {
        return [
            '@type' => self::ERROR_INFO_TYPE,
            'reason' => $reason,
            'domain' => self::A2A_DOMAIN,
            // An empty PHP array would encode as [] instead of {}.
            'metadata' => $metadata === [] ? new \stdClass() : $metadata,
        ];
    }

    /**
     * @param list<ErrorDetail> $details
     *
     * @return array{error: array<string, mixed>}
     */
    private static function payload(int $code, string $status, string $message, array $details = []): array
    {
        $payload = ['code' => $code, 'status' => $status, 'message' => $message];
        if ($details !== []) {
            $payload['details'] = $details;
        }

        return ['error' => $payload];
    }
}
