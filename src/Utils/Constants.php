<?php

declare(strict_types=1);

namespace A2A\Utils;

/**
 * Well-known paths, headers and limits used throughout the SDK.
 *
 * Mirrors a2a-python: src/a2a/utils/constants.py
 */
final class Constants
{
    public const AGENT_CARD_WELL_KNOWN_PATH = '/.well-known/agent-card.json';
    public const DEFAULT_RPC_URL = '/';

    /** Default page size for ListTasks. */
    public const DEFAULT_LIST_TASKS_PAGE_SIZE = 50;

    /** Maximum page size for ListTasks. */
    public const MAX_LIST_TASKS_PAGE_SIZE = 100;

    public const JSONRPC_PARSE_ERROR_CODE = -32700;

    public const VERSION_HEADER = 'A2A-Version';
    public const EXTENSIONS_HEADER = 'A2A-Extensions';

    public const PROTOCOL_VERSION_1_0 = '1.0';
    public const PROTOCOL_VERSION_0_3 = '0.3';
    public const PROTOCOL_VERSION_CURRENT = self::PROTOCOL_VERSION_1_0;

    private function __construct() {}
}
