<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Utils\JsonUtils;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/utils/test_json_utils.py.
 */
final class JsonUtilsTest extends TestCase
{
    public function testDumpsEmitsRawUtf8ForNonAscii(): void
    {
        $out = JsonUtils::dumps(['text' => '你好']);

        self::assertStringContainsString('你好', $out);
        self::assertStringNotContainsString('\\u4f60\\u597d', $out);
    }

    public function testDumpsEmitsEmojiAsRawUtf8(): void
    {
        self::assertStringContainsString('🎉', JsonUtils::dumps(['emoji' => '🎉']));
    }

    public function testDumpsRoundTripsThroughJsonDecode(): void
    {
        $payload = ['msg' => '你好', 'list' => ['a', 'é', '日本語'], 'n' => 1];

        self::assertSame($payload, json_decode(JsonUtils::dumps($payload), true));
    }

    public function testDumpsKeepsSlashesAndZeroFractions(): void
    {
        // json.dumps writes 1.0 as "1.0" and never escapes "/".
        self::assertSame('{"url":"https://a.b/c","f":1.0}', JsonUtils::dumps(['url' => 'https://a.b/c', 'f' => 1.0]));
    }
}
