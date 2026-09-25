<?php

declare(strict_types=1);

namespace A2A\Tests\Client;

use A2A\Client\A2ACardResolver;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\AgentCardResolutionError;
use A2A\Tests\Client\Support\FakeHttpSender;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\AgentSkill;
use A2A\Types\SecurityRequirement;
use A2A\Types\SecurityScheme;
use A2A\Types\StringList;
use A2A\Utils\Constants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/test_card_resolver.py
 */
final class A2ACardResolverTest extends TestCase
{
    private const BASE_URL = 'https://example.com';

    private FakeHttpSender $http;
    private A2ACardResolver $resolver;

    protected function setUp(): void
    {
        $this->http = new FakeHttpSender();
        $this->resolver = new A2ACardResolver($this->http, self::BASE_URL);
    }

    public function testInitWithDefaults(): void
    {
        self::assertSame(self::BASE_URL, $this->resolver->baseUrl);
        self::assertSame(substr(Constants::AGENT_CARD_WELL_KNOWN_PATH, 1), $this->resolver->agentCardPath);
        self::assertSame($this->http, $this->resolver->httpSender);
    }

    public function testInitWithCustomPathAndTrailingSlash(): void
    {
        $resolver = new A2ACardResolver($this->http, self::BASE_URL . '/', '/custom/agent/card');

        self::assertSame(self::BASE_URL, $resolver->baseUrl);
        self::assertSame('custom/agent/card', $resolver->agentCardPath);
    }

    public function testGetAgentCardSuccessDefaultPath(): void
    {
        $this->http->queueJson(self::validCardData());

        $card = $this->resolver->getAgentCard();

        self::assertSame('Test Agent', $card->getName());
        self::assertSame(self::BASE_URL . '/.well-known/agent-card.json', $this->http->lastRequest()->url);
        self::assertSame('GET', $this->http->lastRequest()->method);
        self::assertSame('1.0', $this->http->lastHeader('A2A-Version'));
    }

    public function testGetAgentCardSuccessCustomPath(): void
    {
        $this->http->queueJson(self::validCardData());

        $this->resolver->getAgentCard('custom/path/card');

        self::assertSame(self::BASE_URL . '/custom/path/card', $this->http->lastRequest()->url);
    }

    public function testGetAgentCardStripsLeadingSlashFromRelativePath(): void
    {
        $this->http->queueJson(self::validCardData());

        $this->resolver->getAgentCard('/custom/path/card');

        self::assertSame(self::BASE_URL . '/custom/path/card', $this->http->lastRequest()->url);
    }

    public function testGetAgentCardWithHttpKwargs(): void
    {
        $this->http->queueJson(self::validCardData());

        $this->resolver->getAgentCard(null, ['headers' => ['Authorization' => 'Bearer token'], 'timeout' => 30.0]);

        self::assertSame('Bearer token', $this->http->lastHeader('Authorization'));
        self::assertSame(30.0, $this->http->lastRequest()->timeout);
    }

    public function testGetAgentCardRootPath(): void
    {
        $this->http->queueJson(self::validCardData());

        $this->resolver->getAgentCard('/');

        self::assertSame(self::BASE_URL, $this->http->lastRequest()->url);
    }

    public function testGetAgentCardWithEmptyResolverAgentCardPath(): void
    {
        $this->http->queueJson(self::validCardData());

        (new A2ACardResolver($this->http, self::BASE_URL, ''))->getAgentCard();

        self::assertSame(self::BASE_URL, $this->http->lastRequest()->url);
    }

    public function testNullAndEmptyRelativePathUseTheDefault(): void
    {
        $this->http->queueJson(self::validCardData())->queueJson(self::validCardData());

        $this->resolver->getAgentCard(null);
        $this->resolver->getAgentCard('');

        $expected = self::BASE_URL . '/.well-known/agent-card.json';
        self::assertSame([$expected, $expected], array_map(static fn($r): string => $r->url, $this->http->requests));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function errorStatuses(): iterable
    {
        foreach ([400, 401, 403, 404, 500, 502, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('errorStatuses')]
    public function testGetAgentCardHttpStatusError(int $status): void
    {
        $this->http->queueBody('nope', $status);

        try {
            $this->resolver->getAgentCard();
            self::fail('Expected AgentCardResolutionError');
        } catch (AgentCardResolutionError $e) {
            self::assertSame($status, $e->statusCode);
            self::assertStringContainsString('Failed to fetch agent card from ' . self::BASE_URL . '/.well-known/agent-card.json (HTTP ' . $status . ')', $e->getMessage());
        }
    }

    public function testGetAgentCardJsonDecodeError(): void
    {
        $this->http->queueBody('{not json');

        $this->expectException(AgentCardResolutionError::class);
        $this->expectExceptionMessage('Failed to parse JSON for agent card');
        $this->resolver->getAgentCard();
    }

    public function testGetAgentCardRequestError(): void
    {
        $this->http->queueException(new A2AClientError('Connection timeout'));

        $this->expectException(AgentCardResolutionError::class);
        $this->expectExceptionMessage('Network communication error');
        $this->resolver->getAgentCard();
    }

    public function testGetAgentCardValidationError(): void
    {
        $this->http->queueJson(['name' => ['invalid' => 'type']]);

        $this->expectException(AgentCardResolutionError::class);
        $this->expectExceptionMessage('Failed to validate agent card structure from ' . self::BASE_URL . '/.well-known/agent-card.json');
        $this->resolver->getAgentCard();
    }

    public function testGetAgentCardWithSignatureVerifier(): void
    {
        $this->http->queueJson(self::validCardData());
        $verified = [];

        $card = $this->resolver->getAgentCard(null, [], static function (AgentCard $c) use (&$verified): void {
            $verified[] = $c->getName();
        });

        self::assertSame('Test Agent', $card->getName());
        self::assertSame(['Test Agent'], $verified);
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        $this->http->queueJson(self::validCardData() + ['someFutureField' => ['x' => 1]]);

        self::assertSame('Test Agent', $this->resolver->getAgentCard()->getName());
    }

    public function testParseAgentCardLegacySupport(): void
    {
        $data = self::object(['name' => 'Legacy Agent', 'description' => 'Legacy Description', 'version' => '1.0', 'supportsAuthenticatedExtendedCard' => true]);

        $card = A2ACardResolver::parseAgentCard($data);

        self::assertSame('Legacy Agent', $card->getName());
        self::assertTrue($card->getCapabilities()?->getExtendedAgentCard());
        self::assertTrue(property_exists($data, 'supportsAuthenticatedExtendedCard'), 'the caller\'s data is not modified');
    }

    public function testParseAgentCardNewSupport(): void
    {
        $card = A2ACardResolver::parseAgentCard(self::object(['name' => 'New Agent', 'description' => 'd', 'version' => '1.0', 'capabilities' => ['extendedAgentCard' => true]]));

        self::assertTrue($card->getCapabilities()?->getExtendedAgentCard());
    }

    public function testParseAgentCardNoSupport(): void
    {
        $card = A2ACardResolver::parseAgentCard(self::object(['name' => 'No Support Agent', 'description' => 'd', 'version' => '1.0', 'capabilities' => ['extendedAgentCard' => false]]));

        self::assertFalse($card->getCapabilities()?->getExtendedAgentCard());
    }

    public function testParseAgentCardBothLegacyAndNew(): void
    {
        $card = A2ACardResolver::parseAgentCard(self::object(['name' => 'Mixed Agent', 'description' => 'd', 'version' => '1.0', 'supportsAuthenticatedExtendedCard' => true, 'capabilities' => ['streaming' => true]]));

        self::assertTrue($card->getCapabilities()?->getStreaming());
        self::assertTrue($card->getCapabilities()->getExtendedAgentCard());
    }

    public function testParseTypical030AgentCard(): void
    {
        $card = A2ACardResolver::parseAgentCard(self::object([
            'additionalInterfaces' => [['transport' => 'GRPC', 'url' => 'http://agent.example.com/api/grpc']],
            'capabilities' => ['streaming' => true],
            'defaultInputModes' => ['text/plain'],
            'defaultOutputModes' => ['application/json'],
            'description' => 'A typical agent from 0.3.0',
            'name' => 'Typical Agent 0.3',
            'preferredTransport' => 'JSONRPC',
            'protocolVersion' => '0.3.0',
            'security' => [['test_oauth' => ['read', 'write']]],
            'securitySchemes' => ['test_oauth' => [
                'description' => 'OAuth2 authentication',
                'flows' => ['authorizationCode' => [
                    'authorizationUrl' => 'http://auth.example.com',
                    'scopes' => ['read' => 'Read access', 'write' => 'Write access'],
                    'tokenUrl' => 'http://token.example.com',
                ]],
                'type' => 'oauth2',
            ]],
            'skills' => [[
                'description' => 'The first skill',
                'id' => 'skill-1',
                'name' => 'Skill 1',
                'security' => [['test_oauth' => ['read']]],
                'tags' => ['example'],
            ]],
            'supportsAuthenticatedExtendedCard' => true,
            'url' => 'http://agent.example.com/api',
            'version' => '1.0',
        ]));

        $expected = new AgentCard();
        $expected->mergeFromJsonString((string) json_encode([
            'name' => 'Typical Agent 0.3',
            'description' => 'A typical agent from 0.3.0',
            'version' => '1.0',
            'capabilities' => ['extendedAgentCard' => true, 'streaming' => true],
            'defaultInputModes' => ['text/plain'],
            'defaultOutputModes' => ['application/json'],
            'supportedInterfaces' => [
                ['url' => 'http://agent.example.com/api', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '0.3.0'],
                ['url' => 'http://agent.example.com/api/grpc', 'protocolBinding' => 'GRPC', 'protocolVersion' => '0.3.0'],
            ],
            'securityRequirements' => [['schemes' => ['test_oauth' => ['list' => ['read', 'write']]]]],
            'securitySchemes' => ['test_oauth' => ['oauth2SecurityScheme' => [
                'description' => 'OAuth2 authentication',
                'flows' => ['authorizationCode' => [
                    'authorizationUrl' => 'http://auth.example.com',
                    'tokenUrl' => 'http://token.example.com',
                    'scopes' => ['read' => 'Read access', 'write' => 'Write access'],
                ]],
            ]]],
            'skills' => [[
                'id' => 'skill-1',
                'name' => 'Skill 1',
                'description' => 'The first skill',
                'tags' => ['example'],
                'securityRequirements' => [['schemes' => ['test_oauth' => ['list' => ['read']]]]],
            ]],
        ]));

        self::assertJsonStringEqualsJsonString($expected->serializeToJsonString(), $card->serializeToJsonString());
    }

    public function testParse030RoutePlannerStyleCard(): void
    {
        $card = A2ACardResolver::parseAgentCard(self::object([
            'protocolVersion' => '0.3',
            'name' => 'GeoSpatial Route Planner Agent',
            'description' => 'Provides advanced route planning.',
            'url' => 'https://georoute-agent.example.com/a2a/v1',
            'preferredTransport' => 'JSONRPC',
            'additionalInterfaces' => [
                ['url' => 'https://georoute-agent.example.com/a2a/v1', 'transport' => 'JSONRPC'],
                ['url' => 'https://georoute-agent.example.com/a2a/grpc', 'transport' => 'GRPC'],
                ['url' => 'https://georoute-agent.example.com/a2a/json', 'transport' => 'HTTP+JSON'],
            ],
            'iconUrl' => 'https://georoute-agent.example.com/icon.png',
            'version' => '1.2.0',
            'supportsAuthenticatedExtendedCard' => true,
            'capabilities' => ['streaming' => true, 'pushNotifications' => true, 'stateTransitionHistory' => false],
            'securitySchemes' => ['google' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://accounts.google.com/.well-known/openid-configuration']],
            'security' => [['google' => ['openid', 'profile', 'email']]],
            'skills' => [['id' => 's', 'name' => 'S', 'description' => 'd', 'tags' => ['t'], 'security' => [['example' => []], ['google' => ['openid']]]]],
        ]));

        self::assertSame(['JSONRPC', 'JSONRPC', 'GRPC', 'HTTP+JSON'], array_map(
            static fn(AgentInterface $i): string => $i->getProtocolBinding(),
            iterator_to_array($card->getSupportedInterfaces(), false),
        ));
        self::assertSame('0.3', $card->getSupportedInterfaces()[0]->getProtocolVersion());
        self::assertSame('https://georoute-agent.example.com/icon.png', $card->getIconUrl());
        self::assertTrue($card->getCapabilities()?->getPushNotifications());
        $scheme = $card->getSecuritySchemes()['google'];
        self::assertInstanceOf(SecurityScheme::class, $scheme);
        self::assertSame('https://accounts.google.com/.well-known/openid-configuration', $scheme->getOpenIdConnectSecurityScheme()?->getOpenIdConnectUrl());
        $requirement = $card->getSecurityRequirements()[0];
        self::assertInstanceOf(SecurityRequirement::class, $requirement);
        $scopes = $requirement->getSchemes()['google'];
        self::assertInstanceOf(StringList::class, $scopes);
        self::assertSame(['openid', 'profile', 'email'], iterator_to_array($scopes->getList()));
        $skill = $card->getSkills()[0];
        self::assertInstanceOf(AgentSkill::class, $skill);
        self::assertCount(2, $skill->getSecurityRequirements());
    }

    public function testParseAgentCardSecuritySchemeWithoutIn(): void
    {
        $card = A2ACardResolver::parseAgentCard(self::object(['name' => 'API Key Agent', 'description' => 'd', 'version' => '1.0', 'securitySchemes' => ['test_api_key' => ['type' => 'apiKey', 'name' => 'X-API-KEY']]]));

        $scheme = $card->getSecuritySchemes()['test_api_key'];
        self::assertInstanceOf(SecurityScheme::class, $scheme);
        self::assertSame('X-API-KEY', $scheme->getApiKeySecurityScheme()?->getName());
        self::assertSame('', $scheme->getApiKeySecurityScheme()->getLocation());
    }

    public function testParseAgentCardLegacyApiKeyInBecomesLocation(): void
    {
        $card = A2ACardResolver::parseAgentCard(self::object(['name' => 'n', 'description' => 'd', 'version' => '1.0', 'securitySchemes' => ['k' => ['type' => 'apiKey', 'name' => 'X-API-KEY', 'in' => 'header']]]));

        $scheme = $card->getSecuritySchemes()['k'];
        self::assertInstanceOf(SecurityScheme::class, $scheme);
        self::assertSame('header', $scheme->getApiKeySecurityScheme()?->getLocation());
    }

    public function testParseAgentCardSecuritySchemeUnknownType(): void
    {
        $card = A2ACardResolver::parseAgentCard(self::object([
            'name' => 'Unknown Scheme Agent',
            'description' => 'Has unknown scheme type',
            'version' => '1.0',
            'securitySchemes' => ['test_unknown' => ['type' => 'someFutureType', 'future_prop' => 'value'], 'test_missing_type' => ['prop' => 'value']],
        ]));

        foreach (['test_unknown', 'test_missing_type'] as $name) {
            $scheme = $card->getSecuritySchemes()[$name];
            self::assertInstanceOf(SecurityScheme::class, $scheme);
            self::assertSame('', $scheme->getScheme());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function validCardData(): array
    {
        return [
            'name' => 'Test Agent',
            'description' => 'A test agent',
            'version' => '1.0.0',
            'supportedInterfaces' => [['url' => 'https://example.com/a2a', 'protocolBinding' => 'HTTP+JSON', 'protocolVersion' => '1.0']],
            'capabilities' => new \stdClass(),
            'defaultInputModes' => ['text/plain'],
            'defaultOutputModes' => ['text/plain'],
            'skills' => [['id' => 'test-skill', 'name' => 'Test Skill', 'description' => 'A skill for testing', 'tags' => ['test']]],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function object(array $data): \stdClass
    {
        $object = json_decode((string) json_encode($data));
        self::assertInstanceOf(\stdClass::class, $object);

        return $object;
    }
}
