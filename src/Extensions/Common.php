<?php

declare(strict_types=1);

namespace A2A\Extensions;

use A2A\Types\AgentCard;
use A2A\Types\AgentExtension;

/**
 * Helpers for A2A protocol extensions.
 *
 * Mirrors a2a-python: src/a2a/extensions/common.py
 */
final class Common
{
    public const HTTP_EXTENSION_HEADER = 'A2A-Extensions';

    private function __construct() {}

    /**
     * The requested extension URIs from header values that may each hold a
     * comma-separated list. Returns unique values in first-seen order (Python
     * returns a set).
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function getRequestedExtensions(array $values): array
    {
        $extensions = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $extension) {
                $extension = trim($extension);
                if ($extension !== '') {
                    $extensions[$extension] = true;
                }
            }
        }

        return array_map('strval', array_keys($extensions));
    }

    public static function findExtensionByUri(AgentCard $card, string $uri): ?AgentExtension
    {
        foreach ($card->getCapabilities()?->getExtensions() ?? [] as $extension) {
            if ($extension->getUri() === $uri) {
                return $extension;
            }
        }

        return null;
    }
}
