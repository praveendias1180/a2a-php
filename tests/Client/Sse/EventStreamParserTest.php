<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Sse;

use A2A\Client\Sse\EventStreamParser;
use A2A\Client\Sse\SseEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventStreamParserTest extends TestCase
{
    /**
     * Port of test_parse_sse_stream_edge_cases (tests/client/transports/test_http_helpers.py).
     */
    public function testPythonEdgeCases(): void
    {
        $events = self::parse([
            ": comment line (should be ignored)\n",
            "event: custom_event\n",
            "data:  hello\n",
            "data: world\n",
            "\n",
            "\n",
            "data: \n",
            "\n",
        ]);

        self::assertSame([['custom_event', " hello\nworld"], ['message', '']], $events);
    }

    /**
     * @return iterable<string, array{list<string>, list<array{string, string}>}>
     */
    public static function chunkings(): iterable
    {
        $stream = "data: {\"a\":1}\n\nevent: error\ndata: line1\ndata: line2\n\n";
        $expected = [['message', '{"a":1}'], ['error', "line1\nline2"]];

        yield 'one chunk' => [[$stream], $expected];
        yield 'byte by byte' => [str_split($stream), $expected];
        yield 'split inside a line' => [['data: {"a"', ":1}\n", "\nevent: err", "or\ndata: line1\ndata: line2\n\n"], $expected];
        yield 'CRLF line endings' => [[str_replace("\n", "\r\n", $stream)], $expected];
        yield 'CRLF split across chunks' => [["data: {\"a\":1}\r", "\n\r", "\nevent: error\r\ndata: line1\r\ndata: line2\r\n\r\n"], $expected];
        yield 'bare CR line endings' => [[str_replace("\n", "\r", $stream)], $expected];
    }

    /**
     * @param list<string>                $chunks
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('chunkings')]
    public function testChunkBoundariesAndLineEndings(array $chunks, array $expected): void
    {
        self::assertSame($expected, self::parse($chunks));
    }

    public function testCommentsAreKeepAlivesAndProduceNothing(): void
    {
        self::assertSame([['message', 'x']], self::parse([": ping\n\n", ": ping\n\n", "data: x\n\n", ": ping\n\n"]));
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        self::assertSame([['message', 'ok']], self::parse(["id: 7\nretry: 1000\nfoo: bar\n", "data: ok\n\n"]));
    }

    public function testFieldNameWithoutColonHasAnEmptyValue(): void
    {
        // Per the spec (and Python's parser), a bare "data" line adds an empty data line.
        self::assertSame([['message', "\nok"]], self::parse(["data\n", "data: ok\n\n"]));
    }

    public function testDataWithoutASpaceAfterTheColonKeepsItsText(): void
    {
        self::assertSame([['message', 'tight:value']], self::parse(["data:tight:value\n\n"]));
    }

    public function testEventNameResetsAfterEachEvent(): void
    {
        self::assertSame([['custom', 'a'], ['message', 'b']], self::parse(["event: custom\ndata: a\n\ndata: b\n\n"]));
    }

    public function testBlankLineWithoutDataDropsTheEventName(): void
    {
        self::assertSame([['message', 'b']], self::parse(["event: custom\n\ndata: b\n\n"]));
    }

    public function testUnterminatedLastEventIsDiscarded(): void
    {
        self::assertSame([['message', 'a']], self::parse(["data: a\n\ndata: never finished"]));
    }

    public function testLeadingByteOrderMarkIsSkipped(): void
    {
        self::assertSame([['message', 'a']], self::parse(["\u{FEFF}data: a\n\n"]));
    }

    public function testMultiByteUtf8SplitAcrossChunks(): void
    {
        $bytes = "data: h\u{e9}llo \u{1F44B}\n\n";

        self::assertSame([['message', "h\u{e9}llo \u{1F44B}"]], self::parse(str_split($bytes, 3)));
    }

    /**
     * @param list<string> $chunks
     *
     * @return list<array{string, string}>
     */
    private static function parse(array $chunks): array
    {
        $parser = new EventStreamParser();
        $events = [];
        foreach ($chunks as $chunk) {
            array_push($events, ...$parser->feed($chunk));
        }
        array_push($events, ...$parser->finish());

        return array_map(static fn(SseEvent $e): array => [$e->event, $e->data], $events);
    }
}
