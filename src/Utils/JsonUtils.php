<?php

declare(strict_types=1);

namespace A2A\Utils;

/**
 * JSON serialization helpers.
 *
 * Mirrors a2a-python: src/a2a/utils/json_utils.py
 */
final class JsonUtils
{
    private function __construct() {}

    /**
     * Serializes to JSON with raw UTF-8 (no \uXXXX escapes) and unescaped
     * slashes, for the SSE/streaming paths that write JSON by hand.
     *
     * @throws \JsonException
     */
    public static function dumps(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
