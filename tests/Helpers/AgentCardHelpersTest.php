<?php

declare(strict_types=1);

namespace A2A\Tests\Helpers;

use A2A\Helpers\AgentCardHelpers;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\AgentProvider;
use A2A\Types\AgentSkill;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/helpers/test_agent_card_display.py. The golden
 * strings are Python's, unchanged: both SDKs print the same summary.
 */
final class AgentCardHelpersTest extends TestCase
{
    public function testFullCardOutput(): void
    {
        $card = new AgentCard([
            'name' => 'Sample Agent',
            'description' => 'A sample agent.',
            'version' => '1.0.0',
            'documentation_url' => 'https://docs.example.com',
            'icon_url' => 'https://example.com/icon.png',
            'provider' => new AgentProvider(['organization' => 'Example Org', 'url' => 'https://example.com']),
            'supported_interfaces' => [
                new AgentInterface(['url' => 'http://localhost:9999/a2a/jsonrpc', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0']),
                new AgentInterface(['url' => 'http://localhost:9999/a2a/rest', 'protocol_binding' => 'HTTP+JSON', 'protocol_version' => '1.0', 'tenant' => 'tenant-a']),
            ],
            'capabilities' => new AgentCapabilities(['streaming' => true, 'push_notifications' => false, 'extended_agent_card' => true]),
            'default_input_modes' => ['text'],
            'default_output_modes' => ['text', 'task-status'],
            'skills' => [
                new AgentSkill(['id' => 'skill-1', 'name' => 'My Skill', 'description' => 'Does something useful.', 'tags' => ['foo', 'bar'], 'examples' => ['Do the thing', 'Another example']]),
                new AgentSkill(['id' => 'skill-2', 'name' => 'Other Skill', 'description' => 'Does something else.', 'tags' => ['baz']]),
            ],
        ]);

        $expected = implode("\n", [
            '====================================================',
            '                     AgentCard                      ',
            '====================================================',
            '--- General ---',
            'Name        : Sample Agent',
            'Description : A sample agent.',
            'Version     : 1.0.0',
            'Docs URL    : https://docs.example.com',
            'Icon URL    : https://example.com/icon.png',
            'Provider    : Example Org (https://example.com)',
            '',
            '--- Interfaces ---',
            '  [0] http://localhost:9999/a2a/jsonrpc  (JSONRPC 1.0)',
            '  [1] http://localhost:9999/a2a/rest  (HTTP+JSON 1.0, tenant=tenant-a)',
            '',
            '--- Capabilities ---',
            'Streaming           : True',
            'Push notifications  : False',
            'Extended agent card : True',
            '',
            '--- I/O Modes ---',
            'Input  : text',
            'Output : text, task-status',
            '',
            '--- Skills ---',
            '----------------------------------------------------',
            '  ID          : skill-1',
            '  Name        : My Skill',
            '  Description : Does something useful.',
            '  Tags        : foo, bar',
            '  Example     : Do the thing',
            '  Example     : Another example',
            '----------------------------------------------------',
            '  ID          : skill-2',
            '  Name        : Other Skill',
            '  Description : Does something else.',
            '  Tags        : baz',
            '====================================================',
        ]) . "\n";

        $this->expectOutputString($expected);
        AgentCardHelpers::displayAgentCard($card);
    }

    public function testEmptyCardOutput(): void
    {
        $expected = implode("\n", [
            '====================================================',
            '                     AgentCard                      ',
            '====================================================',
            '--- General ---',
            'Name        : ',
            'Description : ',
            'Version     : ',
            '',
            '--- Interfaces ---',
            '',
            '--- Capabilities ---',
            'Streaming           : False',
            'Push notifications  : False',
            'Extended agent card : False',
            '',
            '--- I/O Modes ---',
            'Input  : (none)',
            'Output : (none)',
            '',
            '--- Skills ---',
            '  (none)',
            '====================================================',
        ]) . "\n";

        self::assertSame($expected, AgentCardHelpers::formatAgentCard(new AgentCard()));
    }

    public function testInterfaceWithoutProtocolVersionHasNoTrailingSpace(): void
    {
        $card = new AgentCard(['supported_interfaces' => [new AgentInterface(['url' => '127.0.0.1:50051', 'protocol_binding' => 'GRPC'])]]);

        self::assertStringContainsString('  [0] 127.0.0.1:50051  (GRPC)', AgentCardHelpers::formatAgentCard($card));
    }

    public function testInterfaceWithoutBindingOrVersionHasNoParentheses(): void
    {
        $card = new AgentCard(['supported_interfaces' => [new AgentInterface(['url' => '127.0.0.1:50051'])]]);

        self::assertStringContainsString("  [0] 127.0.0.1:50051\n", AgentCardHelpers::formatAgentCard($card));
    }

    public function testProviderWithUrl(): void
    {
        $card = new AgentCard(['provider' => new AgentProvider(['organization' => 'Example Org', 'url' => 'https://example.com'])]);

        self::assertStringContainsString('Provider    : Example Org (https://example.com)', AgentCardHelpers::formatAgentCard($card));
    }

    public function testProviderWithoutUrlHasNoEmptyParentheses(): void
    {
        $out = AgentCardHelpers::formatAgentCard(new AgentCard(['provider' => new AgentProvider(['organization' => 'Example Org'])]));

        self::assertStringContainsString('Provider    : Example Org', $out);
        self::assertStringNotContainsString('()', $out);
    }
}
