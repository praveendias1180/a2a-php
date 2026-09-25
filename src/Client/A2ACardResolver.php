<?php

declare(strict_types=1);

namespace A2A\Client;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\AgentCardResolutionError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpSender;
use A2A\Client\Http\HttpSenderFactory;
use A2A\Client\Transports\HttpHelpers;
use A2A\Types\AgentCard;
use A2A\Utils\Constants;

/**
 * Fetches and parses an agent's card, by default from
 * `/.well-known/agent-card.json`.
 *
 * Mirrors a2a-python: A2ACardResolver (and parse_agent_card) in
 * src/a2a/client/card_resolver.py, including the mapping of pre-1.0 card
 * fields (url / preferredTransport / additionalInterfaces, legacy security,
 * supportsAuthenticatedExtendedCard) onto the 1.0 shape.
 */
final class A2ACardResolver
{
    public readonly string $baseUrl;
    public readonly string $agentCardPath;
    public readonly HttpSender $httpSender;

    /**
     * @param HttpSender|object|null $httpClient an HttpSender or any client HttpSenderFactory accepts
     */
    public function __construct(
        ?object $httpClient,
        string $baseUrl,
        string $agentCardPath = Constants::AGENT_CARD_WELL_KNOWN_PATH,
    ) {
        $this->httpSender = HttpSenderFactory::create($httpClient);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->agentCardPath = ltrim($agentCardPath, '/');
    }

    /**
     * Fetches the card from $relativeCardPath (relative to the base URL), or
     * from the resolver's default path when null. Use '/' for the base URL
     * itself.
     *
     * @param array{headers?: array<string, string>, timeout?: float} $httpKwargs
     * @param (callable(AgentCard): void)|null                         $signatureVerifier
     *
     * @throws AgentCardResolutionError
     */
    public function getAgentCard(?string $relativeCardPath = null, array $httpKwargs = [], ?callable $signatureVerifier = null): AgentCard
    {
        $pathSegment = ($relativeCardPath === null || $relativeCardPath === '') ? $this->agentCardPath : ltrim($relativeCardPath, '/');
        $targetUrl = $pathSegment !== '' ? $this->baseUrl . '/' . $pathSegment : $this->baseUrl;

        $headers = ($httpKwargs['headers'] ?? []) + [Constants::VERSION_HEADER => Constants::PROTOCOL_VERSION_CURRENT];

        try {
            $response = $this->httpSender->send(new HttpRequest('GET', $targetUrl, $headers, null, $httpKwargs['timeout'] ?? null));
        } catch (A2AClientError $e) {
            throw new AgentCardResolutionError(sprintf('Network communication error fetching agent card from %s: %s', $targetUrl, $e->getMessage()), null, $e);
        }

        if ($response->statusCode >= 400) {
            throw new AgentCardResolutionError(
                sprintf('Failed to fetch agent card from %s (HTTP %d)', $targetUrl, $response->statusCode),
                $response->statusCode,
            );
        }

        try {
            $data = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AgentCardResolutionError(sprintf('Failed to parse JSON for agent card from %s: %s', $targetUrl, $e->getMessage()), null, $e);
        }
        if (!$data instanceof \stdClass) {
            throw new AgentCardResolutionError(sprintf('Failed to validate agent card structure from %s: expected a JSON object', $targetUrl));
        }

        try {
            $card = self::parseAgentCard($data);
        } catch (A2AClientError $e) {
            throw new AgentCardResolutionError(sprintf('Failed to validate agent card structure from %s: %s', $targetUrl, $e->getMessage()), null, $e);
        }
        if ($signatureVerifier !== null) {
            $signatureVerifier($card);
        }

        return $card;
    }

    /**
     * Parses a decoded card (objects as stdClass), mapping legacy fields, and
     * ignoring unknown fields. Python: parse_agent_card().
     */
    public static function parseAgentCard(\stdClass $data): AgentCard
    {
        // Deep copy: the compatibility steps edit nested objects in place.
        $copy = HttpHelpers::decodeJson(HttpHelpers::encodeJson($data));
        $data = $copy instanceof \stdClass ? $copy : new \stdClass();
        self::handleExtendedCardCompatibility($data);
        self::handleConnectionFieldsCompatibility($data);
        self::handleSecurityCompatibility($data);

        return HttpHelpers::parseInto($data, new AgentCard(), true);
    }

    private static function handleExtendedCardCompatibility(\stdClass $data): void
    {
        $legacy = $data->supportsAuthenticatedExtendedCard ?? null;
        unset($data->supportsAuthenticatedExtendedCard);
        if ($legacy) {
            if (!isset($data->capabilities) || !$data->capabilities instanceof \stdClass) {
                $data->capabilities = new \stdClass();
            }
            if (!property_exists($data->capabilities, 'extendedAgentCard')) {
                $data->capabilities->extendedAgentCard = true;
            }
        }
    }

    private static function handleConnectionFieldsCompatibility(\stdClass $data): void
    {
        $mainUrl = $data->url ?? null;
        $mainTransport = $data->preferredTransport ?? 'JSONRPC';
        $version = $data->protocolVersion ?? '0.3.0';
        $additional = $data->additionalInterfaces ?? null;
        unset($data->url, $data->preferredTransport, $data->protocolVersion, $data->additionalInterfaces);

        if (!property_exists($data, 'supportedInterfaces') && $mainUrl) {
            $interfaces = [(object) ['url' => $mainUrl, 'protocolBinding' => $mainTransport, 'protocolVersion' => $version]];
            foreach (is_array($additional) ? $additional : [] as $interface) {
                if ($interface instanceof \stdClass) {
                    $interfaces[] = (object) [
                        'url' => $interface->url ?? null,
                        'protocolBinding' => $interface->transport ?? null,
                        'protocolVersion' => $version,
                    ];
                }
            }
            $data->supportedInterfaces = $interfaces;
        }
    }

    /**
     * @return list<\stdClass>
     */
    private static function mapLegacySecurity(mixed $securityList): array
    {
        $mapped = [];
        foreach (is_array($securityList) ? $securityList : [] as $requirement) {
            $schemes = new \stdClass();
            foreach (is_object($requirement) ? get_object_vars($requirement) : [] as $schemeName => $scopes) {
                $schemes->{$schemeName} = (object) ['list' => $scopes];
            }
            $mapped[] = (object) ['schemes' => $schemes];
        }

        return $mapped;
    }

    private static function handleSecurityCompatibility(\stdClass $data): void
    {
        $legacy = $data->security ?? null;
        $hadLegacy = property_exists($data, 'security');
        unset($data->security);
        if (!property_exists($data, 'securityRequirements') && $hadLegacy && $legacy !== null) {
            $data->securityRequirements = self::mapLegacySecurity($legacy);
        }

        foreach (is_array($data->skills ?? null) ? $data->skills : [] as $skill) {
            if (!$skill instanceof \stdClass) {
                continue;
            }
            $hadSkillLegacy = property_exists($skill, 'security');
            $skillLegacy = $skill->security ?? null;
            unset($skill->security);
            if (!property_exists($skill, 'securityRequirements') && $hadSkillLegacy && $skillLegacy !== null) {
                $skill->securityRequirements = self::mapLegacySecurity($skillLegacy);
            }
        }

        $schemes = $data->securitySchemes ?? null;
        if (!$schemes instanceof \stdClass) {
            return;
        }
        $typeMapping = [
            'apiKey' => 'apiKeySecurityScheme',
            'http' => 'httpAuthSecurityScheme',
            'oauth2' => 'oauth2SecurityScheme',
            'openIdConnect' => 'openIdConnectSecurityScheme',
            'mutualTLS' => 'mtlsSecurityScheme',
        ];
        foreach (get_object_vars($schemes) as $name => $scheme) {
            if (!$scheme instanceof \stdClass) {
                continue;
            }
            $type = $scheme->type ?? null;
            unset($scheme->type);
            if (!is_string($type) || !isset($typeMapping[$type])) {
                continue;
            }
            if ($type === 'apiKey' && property_exists($scheme, 'in')) {
                $scheme->location = $scheme->in;
                unset($scheme->in);
            }
            $schemes->{$name} = (object) [$typeMapping[$type] => $scheme];
        }
    }
}
