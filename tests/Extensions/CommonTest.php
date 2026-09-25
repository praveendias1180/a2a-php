<?php

declare(strict_types=1);

namespace A2A\Tests\Extensions;

use A2A\Extensions\Common;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentExtension;
use A2A\Types\AgentInterface;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/extensions/test_common.py. Python returns a
 * set; the PHP list is compared order-insensitively.
 */
final class CommonTest extends TestCase
{
    public function testGetRequestedExtensions(): void
    {
        self::assertSame([], Common::getRequestedExtensions([]));
        self::assertSame(['foo'], Common::getRequestedExtensions(['foo']));
        self::assertEqualsCanonicalizing(['foo', 'bar'], Common::getRequestedExtensions(['foo', 'bar']));
        self::assertEqualsCanonicalizing(['foo', 'bar'], Common::getRequestedExtensions(['foo, bar']));
        self::assertEqualsCanonicalizing(['foo', 'bar'], Common::getRequestedExtensions(['foo,bar']));
        self::assertEqualsCanonicalizing(['foo', 'bar', 'baz'], Common::getRequestedExtensions(['foo', 'bar,baz']));
        self::assertEqualsCanonicalizing(['foo', 'bar', 'baz'], Common::getRequestedExtensions(['foo,, bar', 'baz']));
        self::assertEqualsCanonicalizing(['foo', 'bar', 'baz'], Common::getRequestedExtensions([' foo , bar ', 'baz']));
        self::assertSame(['foo'], Common::getRequestedExtensions(['foo', 'foo, foo']));
    }

    public function testFindExtensionByUri(): void
    {
        $ext1 = new AgentExtension(['uri' => 'foo', 'description' => 'The Foo extension']);
        $ext2 = new AgentExtension(['uri' => 'bar', 'description' => 'The Bar extension']);
        $card = $this->card(new AgentCapabilities(['extensions' => [$ext1, $ext2]]));

        self::assertSame($ext1, Common::findExtensionByUri($card, 'foo'));
        self::assertSame($ext2, Common::findExtensionByUri($card, 'bar'));
        self::assertNull(Common::findExtensionByUri($card, 'baz'));
    }

    public function testFindExtensionByUriNoExtensions(): void
    {
        self::assertNull(Common::findExtensionByUri($this->card(new AgentCapabilities()), 'foo'));
        self::assertNull(Common::findExtensionByUri(new AgentCard(), 'foo'));
    }

    public function testHeaderName(): void
    {
        self::assertSame('A2A-Extensions', Common::HTTP_EXTENSION_HEADER);
    }

    private function card(AgentCapabilities $capabilities): AgentCard
    {
        return new AgentCard([
            'name' => 'Test Agent',
            'description' => 'Test Agent Description',
            'version' => '1.0',
            'supported_interfaces' => [new AgentInterface(['url' => 'http://test.com', 'protocol_binding' => 'HTTP+JSON'])],
            'default_input_modes' => ['text/plain'],
            'default_output_modes' => ['text/plain'],
            'capabilities' => $capabilities,
        ]);
    }
}
