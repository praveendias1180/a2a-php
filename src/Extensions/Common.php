<?php

declare(strict_types=1);

namespace A2A\Extensions;

use A2A\Types\AgentCard;
use A2A\Types\AgentExtension;

/**
 * Helpers for A2A protocol extensions.
 *
 * Negotiation (spec §4.6 and the extensions guide): the client lists the
 * extension URIs it wants in the `A2A-Extensions` header; the agent
 * activates the ones it declares in its card and SHOULD echo the activated
 * list back in the same header; a request that does not ask for an
 * extension the card marks `required` MUST fail with
 * ExtensionSupportRequiredError. activatableExtensions() and
 * missingRequiredExtensions() are the two halves of that; the
 * DefaultRequestHandler applies them.
 *
 * Mirrors a2a-python: src/a2a/extensions/common.py (Python stops at parsing
 * the header; activation, echoing and the required check are PHP additions
 * that follow the spec).
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

    /**
     * The requested URIs the card declares, in request order: what the
     * agent activates for the request.
     *
     * @param list<string> $requested
     *
     * @return list<string>
     */
    public static function activatableExtensions(AgentCard $card, array $requested): array
    {
        return array_values(array_filter($requested, static fn(string $uri): bool => self::findExtensionByUri($card, $uri) !== null));
    }

    /**
     * URIs the card marks required that the client did not request.
     *
     * @param list<string> $requested
     *
     * @return list<string>
     */
    public static function missingRequiredExtensions(AgentCard $card, array $requested): array
    {
        $missing = [];
        foreach ($card->getCapabilities()?->getExtensions() ?? [] as $extension) {
            if ($extension->getRequired() && !in_array($extension->getUri(), $requested, true)) {
                $missing[] = $extension->getUri();
            }
        }

        return $missing;
    }
}
