<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Utils\Constants;
use A2A\Utils\TransportProtocol;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/utils/test_constants.py.
 */
final class ConstantsTest extends TestCase
{
    public function testAgentCardConstants(): void
    {
        self::assertSame('/.well-known/agent-card.json', Constants::AGENT_CARD_WELL_KNOWN_PATH);
    }

    public function testDefaultRpcUrl(): void
    {
        self::assertSame('/', Constants::DEFAULT_RPC_URL);
    }

    public function testVersionHeader(): void
    {
        self::assertSame('A2A-Version', Constants::VERSION_HEADER);
    }

    public function testProtocolVersions(): void
    {
        self::assertSame('1.0', Constants::PROTOCOL_VERSION_1_0);
        self::assertSame('1.0', Constants::PROTOCOL_VERSION_CURRENT);
    }

    public function testTransportProtocolValues(): void
    {
        self::assertSame(['JSONRPC', 'HTTP+JSON', 'GRPC'], array_map(static fn(TransportProtocol $p): string => $p->value, TransportProtocol::cases()));
    }
}
