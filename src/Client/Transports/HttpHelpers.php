<?php

declare(strict_types=1);

namespace A2A\Client\Transports;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpResponse;
use A2A\Client\Http\HttpSender;
use A2A\Client\Sse\EventStreamParser;
use A2A\Utils\Constants;
use Google\Protobuf\Internal\Message as ProtobufMessage;

/**
 * HTTP plumbing shared by the JSON-RPC and REST transports.
 *
 * Mirrors a2a-python: src/a2a/client/transports/http_helpers.py
 * (get_http_args, send_http_request, send_http_stream_request).
 *
 * @internal
 */
final class HttpHelpers
{
    private const JSON_DECODE_FLAGS = JSON_THROW_ON_ERROR;
    private const JSON_ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private function __construct() {}

    /**
     * Headers for a call: A2A-Version (the ClientFactory in Python sets it on
     * the shared httpx client; here every transport request carries it) plus
     * the context's service parameters, which win on a name clash.
     *
     * @return array<string, string>
     */
    public static function getHttpHeaders(?ClientCallContext $context): array
    {
        $headers = [Constants::VERSION_HEADER => Constants::PROTOCOL_VERSION_CURRENT];
        foreach ($context->serviceParameters ?? [] as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($headers[$existing]);
                }
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Sends a request and returns the response body, turning HTTP error
     * statuses into exceptions.
     *
     * @param (callable(HttpResponse, HttpRequest): never)|null $statusErrorHandler
     */
    public static function sendHttpRequest(HttpSender $sender, HttpRequest $request, ?callable $statusErrorHandler = null): string
    {
        $response = $sender->send($request);
        if ($response->statusCode >= 400) {
            self::raiseForStatus($response, $request, $statusErrorHandler);
        }

        return $response->body;
    }

    /**
     * Sends a streaming request and yields the data of each SSE event.
     *
     * A response that is not `text/event-stream` (e.g. an up-front JSON-RPC
     * error) is yielded whole, once. `event: error` events go to
     * $sseErrorHandler, which must throw.
     *
     * @param (callable(HttpResponse, HttpRequest): never)|null $statusErrorHandler
     * @param (callable(string): never)|null                  $sseErrorHandler defaults to defaultSseErrorHandler()
     *
     * @return \Generator<int, string>
     */
    public static function sendHttpStreamRequest(
        HttpSender $sender,
        HttpRequest $request,
        ?callable $statusErrorHandler = null,
        ?callable $sseErrorHandler = null,
    ): \Generator {
        $sseErrorHandler ??= self::defaultSseErrorHandler(...);
        $request = $request
            ->withDefaultHeader('Accept', 'text/event-stream')
            ->withDefaultHeader('Cache-Control', 'no-store');

        $response = $sender->stream($request);
        try {
            if ($response->statusCode >= 400) {
                $buffered = new HttpResponse($response->statusCode, $response->headers, $response->readAll());
                self::raiseForStatus($buffered, $request, $statusErrorHandler);
            }

            if (!str_contains($response->header('content-type'), 'text/event-stream')) {
                yield $response->readAll();

                return;
            }

            $parser = new EventStreamParser();
            foreach ($response->chunks() as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    if ($event->data === '') {
                        continue;
                    }
                    if ($event->event === 'error') {
                        $sseErrorHandler($event->data);
                    }
                    yield $event->data;
                }
            }
            foreach ($parser->finish() as $event) {
                if ($event->data === '') {
                    continue;
                }
                if ($event->event === 'error') {
                    $sseErrorHandler($event->data);
                }
                yield $event->data;
            }
        } finally {
            $response->close();
        }
    }

    public static function defaultSseErrorHandler(string $data): never
    {
        throw new A2AClientError('SSE stream error event received: ' . $data);
    }

    /**
     * Decodes JSON keeping objects as objects, so that `{}` and `[]` survive
     * the round trip back into ProtoJSON.
     */
    public static function decodeJson(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, self::JSON_DECODE_FLAGS);
        } catch (\JsonException $e) {
            throw new A2AClientError('JSON Decode Error: ' . $e->getMessage(), null, $e);
        }
    }

    public static function encodeJson(mixed $value): string
    {
        return json_encode($value, self::JSON_ENCODE_FLAGS);
    }

    /**
     * Fills $message from a decoded JSON value (ProtoJSON).
     *
     * @template T of ProtobufMessage
     *
     * @param T $message
     *
     * @return T
     */
    public static function parseInto(mixed $decoded, ProtobufMessage $message, bool $ignoreUnknown = false): ProtobufMessage
    {
        return self::parseJsonInto(self::encodeJson($decoded), $message, $ignoreUnknown);
    }

    /**
     * @template T of ProtobufMessage
     *
     * @param T $message
     *
     * @return T
     */
    public static function parseJsonInto(string $json, ProtobufMessage $message, bool $ignoreUnknown = false): ProtobufMessage
    {
        try {
            $message->mergeFromJsonString($json, $ignoreUnknown);
        } catch (\Exception $e) {
            // Python lets json_format.ParseError escape; PHP callers get one
            // catchable type for "the agent sent something we can't read".
            throw new A2AClientError(sprintf('Invalid %s in response: %s', $message::class, $e->getMessage()), null, $e);
        }

        return $message;
    }

    /**
     * @param (callable(HttpResponse, HttpRequest): never)|null $statusErrorHandler
     */
    private static function raiseForStatus(HttpResponse $response, HttpRequest $request, ?callable $statusErrorHandler): never
    {
        if ($statusErrorHandler !== null) {
            $statusErrorHandler($response, $request);
        }

        throw new A2AClientError(sprintf('HTTP Error %d: %s', $response->statusCode, self::describe($response, $request)));
    }

    public static function describe(HttpResponse $response, HttpRequest $request): string
    {
        return sprintf("Client error '%d' for url '%s'", $response->statusCode, $request->url);
    }
}
