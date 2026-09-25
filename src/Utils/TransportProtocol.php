<?php

declare(strict_types=1);

namespace A2A\Utils;

/**
 * Protocol binding names, as they appear in AgentInterface::protocolBinding.
 *
 * Mirrors a2a-python: TransportProtocol in src/a2a/utils/constants.py
 */
enum TransportProtocol: string
{
    case JSONRPC = 'JSONRPC';
    case HTTP_JSON = 'HTTP+JSON';
    case GRPC = 'GRPC';
}
