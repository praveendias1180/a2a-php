<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Transports;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Transports\HttpHelpers;
use A2A\Tests\Client\Support\FakeHttpSender;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/transports/test_http_helpers.py
 */
final class HttpHelpersTest extends TestCase
{
    public function testDefaultSseErrorHandler(): void
    {
        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('SSE stream error event received: error_msg');
        HttpHelpers::defaultSseErrorHandler('error_msg');
    }

    public function testDefaultSseErrorHandlerIsUsedForErrorEvents(): void
    {
        $http = (new FakeHttpSender())->queueSse(["event: error\ndata: boom\n\n"]);

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('SSE stream error event received: boom');
        iterator_to_array(HttpHelpers::sendHttpStreamRequest($http, new HttpRequest('GET', 'http://test')));
    }

    public function testSendHttpStreamRequestNonSse(): void
    {
        $http = (new FakeHttpSender())->queueBody('plain error response', 200, ['Content-Type' => 'application/json']);

        $chunks = iterator_to_array(HttpHelpers::sendHttpStreamRequest($http, new HttpRequest('GET', 'http://test')), false);

        self::assertSame(['plain error response'], $chunks);
    }

    public function testStreamRequestSkipsEmptyDataAndKeepsCallerHeaders(): void
    {
        $http = (new FakeHttpSender())->queueSse(["data: \n\n", "data: one\n\n", ": keep-alive\n\n", "data: two\n\n"]);

        $chunks = iterator_to_array(HttpHelpers::sendHttpStreamRequest($http, new HttpRequest('POST', 'http://test', ['accept' => 'text/event-stream; q=1'])), false);

        self::assertSame(['one', 'two'], $chunks);
        self::assertSame('text/event-stream; q=1', $http->lastHeader('Accept'));
        self::assertSame('no-store', $http->lastHeader('Cache-Control'));
        self::assertSame(1, $http->closedStreams);
    }

    public function testStreamIsClosedWhenTheConsumerStopsEarly(): void
    {
        $http = (new FakeHttpSender())->queueSse(["data: one\n\ndata: two\n\n"]);

        foreach (HttpHelpers::sendHttpStreamRequest($http, new HttpRequest('GET', 'http://test')) as $chunk) {
            break;
        }
        gc_collect_cycles();

        self::assertSame(1, $http->closedStreams);
    }

    public function testStatusErrorHandlerIsCalledWithTheBufferedBody(): void
    {
        $http = (new FakeHttpSender())->queueSse(['{"error": "denied"}'], 403);
        $seen = null;

        try {
            iterator_to_array(HttpHelpers::sendHttpStreamRequest($http, new HttpRequest('GET', 'http://test'), static function ($response) use (&$seen): never {
                $seen = $response->body;

                throw new \DomainException('handled');
            }));
            self::fail('Expected an exception');
        } catch (\DomainException) {
        }

        self::assertSame('{"error": "denied"}', $seen);
    }

    public function testGetHttpHeaders(): void
    {
        self::assertSame(['A2A-Version' => '1.0'], HttpHelpers::getHttpHeaders(null));
        self::assertSame(
            ['A2A-Version' => '1.0', 'Authorization' => 'Bearer t'],
            HttpHelpers::getHttpHeaders(new ClientCallContext(serviceParameters: ['Authorization' => 'Bearer t'])),
        );
        self::assertSame(['a2a-version' => '0.3'], HttpHelpers::getHttpHeaders(new ClientCallContext(serviceParameters: ['a2a-version' => '0.3'])));
    }

    public function testDecodeJsonKeepsObjectsDistinctFromArrays(): void
    {
        self::assertSame('{"a":{},"b":[],"c":[{}]}', HttpHelpers::encodeJson(HttpHelpers::decodeJson('{"a":{},"b":[],"c":[{}]}')));
    }

    public function testNumbersBeyondInt64StayNumbers(): void
    {
        // A2A has no uint64 fields, so a number this big can only be a
        // Struct/Value double: it must stay a number, not become a string.
        $struct = HttpHelpers::parseInto(HttpHelpers::decodeJson('{"n": 12345678901234567890}'), new \Google\Protobuf\Struct());
        $values = \A2A\Utils\ProtoUtils::fromStruct($struct);

        self::assertIsFloat($values['n']);
        self::assertEqualsWithDelta(1.2345678901234567e19, $values['n'], 1e4);
    }
}
