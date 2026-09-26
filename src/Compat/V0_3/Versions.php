<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Utils\Constants;

/**
 * Protocol-version checks for the v0.3 compatibility layer.
 *
 * Mirrors a2a-python: src/a2a/compat/v0_3/versions.py.
 */
final class Versions
{
    private function __construct() {}

    /**
     * True for a legacy protocol version: at least 0.3 and below 1.0.
     */
    public static function isLegacyVersion(?string $version): bool
    {
        if ($version === null || $version === '' || preg_match('/^v?\d+(\.\d+)*([a-z]+\d*)?$/i', $version) !== 1) {
            return false;
        }

        return version_compare($version, Constants::PROTOCOL_VERSION_0_3, '>=')
            && version_compare($version, Constants::PROTOCOL_VERSION_1_0, '<');
    }
}
