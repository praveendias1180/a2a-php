<?php

declare(strict_types=1);

namespace A2A\Utils;

/**
 * Random (version 4) UUIDs, the PHP stand-in for Python's uuid.uuid4().
 *
 * @internal
 */
final class Uuid
{
    private function __construct() {}

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
