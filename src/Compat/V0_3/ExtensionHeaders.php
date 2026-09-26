<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Extensions\Common;

/**
 * The v0.3 extension header name. The current spec uses `A2A-Extensions`;
 * v0.3 used `X-A2A-Extensions`, so v0.3 compat servers and clients accept
 * and send both.
 *
 * Mirrors a2a-python: src/a2a/compat/v0_3/extension_headers.py.
 */
final class ExtensionHeaders
{
    public const LEGACY_HTTP_EXTENSION_HEADER = 'X-' . Common::HTTP_EXTENSION_HEADER;

    private function __construct() {}

    /**
     * Copies the `A2A-Extensions` header under its legacy name, so older
     * v0.3 servers that only read `X-A2A-Extensions` see it. PHP arrays are
     * values, so this returns the updated headers instead of mutating them.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function addLegacyExtensionHeader(array $headers): array
    {
        $current = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, self::LEGACY_HTTP_EXTENSION_HEADER) === 0) {
                return $headers;
            }
            if (strcasecmp($name, Common::HTTP_EXTENSION_HEADER) === 0) {
                $current = $value;
            }
        }
        if ($current !== null) {
            $headers[self::LEGACY_HTTP_EXTENSION_HEADER] = $current;
        }

        return $headers;
    }
}
