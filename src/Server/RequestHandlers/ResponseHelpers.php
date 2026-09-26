<?php

declare(strict_types=1);

namespace A2A\Server\RequestHandlers;

use A2A\Compat\V0_3\Conversions;
use A2A\Server\Routes\Common;
use A2A\Types\AgentCard;
use A2A\Utils\Errors\VersionNotSupportedError;

/**
 * Response helpers for the request handlers and routes.
 *
 * Mirrors a2a-python: agent_card_to_dict() in
 * src/a2a/server/request_handlers/response_helpers.py (the JSON-RPC
 * builders it also holds live in Utils\ErrorHandlers here).
 */
final class ResponseHelpers
{
    private function __construct() {}

    /**
     * The card as JSON with the v0.3 fields merged in (`url`,
     * `preferredTransport`, `additionalInterfaces`, `protocolVersion`, the
     * v0.3 `security` and scheme shapes, ...), so v0.3 clients can read a
     * v1.0 card that offers a v0.3 interface. v1.0 fields always win; a
     * card without a v0.3 interface is served unchanged.
     */
    public static function agentCardToDict(AgentCard $card): \stdClass
    {
        $result = Common::toJsonValue($card);
        if (!$result instanceof \stdClass) {
            $result = new \stdClass();
        }

        try {
            $compat = Conversions::toCompatAgentCard($card);
        } catch (VersionNotSupportedError) {
            return $result;
        }
        // Left out when false, as in Python.
        if (($compat->supportsAuthenticatedExtendedCard ?? false) !== true) {
            unset($compat->supportsAuthenticatedExtendedCard);
        }

        return self::merge($result, $compat);
    }

    /**
     * Adds what $extra has and $base lacks, recursing into objects and
     * into lists of objects element by element (Python's merge()).
     */
    private static function merge(\stdClass $base, \stdClass $extra): \stdClass
    {
        foreach (get_object_vars($extra) as $key => $value) {
            if (!property_exists($base, $key)) {
                $base->{$key} = $value;
            } elseif ($value instanceof \stdClass && $base->{$key} instanceof \stdClass) {
                self::merge($base->{$key}, $value);
            } elseif (is_array($value) && is_array($base->{$key})) {
                $list = $base->{$key};
                foreach ($list as $i => $item) {
                    if ($item instanceof \stdClass && ($value[$i] ?? null) instanceof \stdClass) {
                        self::merge($item, $value[$i]);
                    }
                }
            }
        }

        return $base;
    }
}
