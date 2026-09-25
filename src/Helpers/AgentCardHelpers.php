<?php

declare(strict_types=1);

namespace A2A\Helpers;

use A2A\Types\AgentCard;

/**
 * Utility functions for inspecting AgentCard instances.
 *
 * Mirrors a2a-python: src/a2a/helpers/agent_card.py. The output is identical
 * to Python's, including "True"/"False" for booleans, so the same card prints
 * the same summary from either SDK.
 */
final class AgentCardHelpers
{
    private const WIDTH = 52;

    private function __construct() {}

    /**
     * Prints a human-readable summary of an AgentCard.
     */
    public static function displayAgentCard(AgentCard $card): void
    {
        echo self::formatAgentCard($card);
    }

    /**
     * The summary displayAgentCard() prints, ending in a newline.
     */
    public static function formatAgentCard(AgentCard $card): string
    {
        $sep = str_repeat('=', self::WIDTH);
        $thin = str_repeat('-', self::WIDTH);

        $lines = [$sep, str_pad('AgentCard', self::WIDTH, ' ', STR_PAD_BOTH), $sep];

        $lines[] = '--- General ---';
        $lines[] = 'Name        : ' . $card->getName();
        $lines[] = 'Description : ' . $card->getDescription();
        $lines[] = 'Version     : ' . $card->getVersion();
        if ($card->getDocumentationUrl() !== '') {
            $lines[] = 'Docs URL    : ' . $card->getDocumentationUrl();
        }
        if ($card->getIconUrl() !== '') {
            $lines[] = 'Icon URL    : ' . $card->getIconUrl();
        }
        $provider = $card->getProvider();
        if ($provider !== null) {
            $urlSuffix = $provider->getUrl() !== '' ? ' (' . $provider->getUrl() . ')' : '';
            $lines[] = 'Provider    : ' . $provider->getOrganization() . $urlSuffix;
        }

        $lines[] = '';
        $lines[] = '--- Interfaces ---';
        $i = 0;
        foreach ($card->getSupportedInterfaces() as $interface) {
            $binding = trim($interface->getProtocolBinding() . ' ' . $interface->getProtocolVersion());
            $parts = array_values(array_filter(
                [$binding, $interface->getTenant() !== '' ? 'tenant=' . $interface->getTenant() : ''],
                static fn(string $p): bool => $p !== '',
            ));
            $suffix = $parts !== [] ? '  (' . implode(', ', $parts) . ')' : '';
            $lines[] = '  [' . $i++ . '] ' . $interface->getUrl() . $suffix;
        }

        $capabilities = $card->getCapabilities();
        $lines[] = '';
        $lines[] = '--- Capabilities ---';
        $lines[] = 'Streaming           : ' . self::pyBool($capabilities?->getStreaming() ?? false);
        $lines[] = 'Push notifications  : ' . self::pyBool($capabilities?->getPushNotifications() ?? false);
        $lines[] = 'Extended agent card : ' . self::pyBool($capabilities?->getExtendedAgentCard() ?? false);

        $lines[] = '';
        $lines[] = '--- I/O Modes ---';
        $lines[] = 'Input  : ' . self::joinOrNone($card->getDefaultInputModes());
        $lines[] = 'Output : ' . self::joinOrNone($card->getDefaultOutputModes());

        $lines[] = '';
        $lines[] = '--- Skills ---';
        if (count($card->getSkills()) > 0) {
            foreach ($card->getSkills() as $skill) {
                $lines[] = $thin;
                $lines[] = '  ID          : ' . $skill->getId();
                $lines[] = '  Name        : ' . $skill->getName();
                $lines[] = '  Description : ' . $skill->getDescription();
                $lines[] = '  Tags        : ' . self::joinOrNone($skill->getTags());
                foreach ($skill->getExamples() as $example) {
                    $lines[] = '  Example     : ' . $example;
                }
            }
        } else {
            $lines[] = '  (none)';
        }

        $lines[] = $sep;

        return implode("\n", $lines) . "\n";
    }

    private static function pyBool(bool $value): string
    {
        return $value ? 'True' : 'False';
    }

    /**
     * @param iterable<mixed> $values
     */
    private static function joinOrNone(iterable $values): string
    {
        $strings = [];
        foreach ($values as $value) {
            $strings[] = is_string($value) ? $value : '';
        }

        return $strings !== [] ? implode(', ', $strings) : '(none)';
    }
}
