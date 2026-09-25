<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Auth;

use A2A\Client\Auth\AuthInterceptor;
use A2A\Client\Auth\InMemoryContextCredentialStore;
use A2A\Client\BeforeArgs;
use A2A\Client\ClientCallContext;
use A2A\Client\ClientConfig;
use A2A\Client\ClientFactory;
use A2A\Helpers\ProtoHelpers;
use A2A\Tests\Client\Support\FakeHttpSender;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\APIKeySecurityScheme;
use A2A\Types\AuthorizationCodeOAuthFlow;
use A2A\Types\HTTPAuthSecurityScheme;
use A2A\Types\Message;
use A2A\Types\OAuth2SecurityScheme;
use A2A\Types\OAuthFlows;
use A2A\Types\OpenIdConnectSecurityScheme;
use A2A\Types\Role;
use A2A\Types\SecurityRequirement;
use A2A\Types\SecurityScheme;
use A2A\Types\SendMessageRequest;
use A2A\Types\StringList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/test_auth_interceptor.py
 */
final class AuthInterceptorTest extends TestCase
{
    public function testSkipsWhenNoAgentCardSecurity(): void
    {
        $context = new ClientCallContext();
        $args = new BeforeArgs(new SendMessageRequest(['message' => new Message()]), 'send_message', new AgentCard(), $context);

        (new AuthInterceptor(new InMemoryContextCredentialStore()))->before($args);

        self::assertNull($context->serviceParameters);
    }

    public function testInMemoryContextCredentialStore(): void
    {
        $store = new InMemoryContextCredentialStore();
        $store->setCredentials('session-id', 'test-scheme', 'test-token');
        $context = new ClientCallContext(state: ['sessionId' => 'session-id']);

        self::assertSame('test-token', $store->getCredentials('test-scheme', $context));
        self::assertNull($store->getCredentials('test-scheme', new ClientCallContext(state: ['sessionId' => 'wrong-session'])));
        self::assertNull($store->getCredentials('test-scheme', null));
        self::assertNull($store->getCredentials('test-scheme', new ClientCallContext()));

        $store->setCredentials('session-id', 'test-scheme', 'new-token');
        self::assertSame('new-token', $store->getCredentials('test-scheme', $context));
    }

    /**
     * @return iterable<string, array{string, SecurityScheme, string, string}>
     */
    public static function variants(): iterable
    {
        yield 'api key' => ['apikey', new SecurityScheme(['api_key_security_scheme' => new APIKeySecurityScheme(['name' => 'X-API-Key', 'location' => 'header'])]), 'X-API-Key', 'secret-api-key'];
        yield 'oauth2' => ['oauth2', new SecurityScheme(['oauth2_security_scheme' => new OAuth2SecurityScheme(['flows' => new OAuthFlows(['authorization_code' => new AuthorizationCodeOAuthFlow(['authorization_url' => 'http://provider.com/auth', 'token_url' => 'http://provider.com/token'])])])]), 'Authorization', 'Bearer secret-api-key'];
        yield 'oidc' => ['oidc', new SecurityScheme(['open_id_connect_security_scheme' => new OpenIdConnectSecurityScheme(['open_id_connect_url' => 'http://provider.com/.well-known/openid-configuration'])]), 'Authorization', 'Bearer secret-api-key'];
        yield 'bearer' => ['bearer', new SecurityScheme(['http_auth_security_scheme' => new HTTPAuthSecurityScheme(['scheme' => 'bearer'])]), 'Authorization', 'Bearer secret-api-key'];
    }

    #[DataProvider('variants')]
    public function testAuthInterceptorVariants(string $schemeName, SecurityScheme $scheme, string $header, string $expected): void
    {
        $store = new InMemoryContextCredentialStore();
        $store->setCredentials('session-id', $schemeName, 'secret-api-key');
        $card = self::card($schemeName, [$schemeName => $scheme]);
        $http = new FakeHttpSender();
        $http->queueJson(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['message' => ['messageId' => 'r']]]);
        $client = (new ClientFactory(new ClientConfig(streaming: false, httpClient: $http, supportedProtocolBindings: ['JSONRPC'])))
            ->create($card, [new AuthInterceptor($store)]);

        iterator_to_array($client->sendMessage(
            new SendMessageRequest(['message' => ProtoHelpers::newTextMessage('Hello, agent!', role: Role::ROLE_USER)]),
            new ClientCallContext(state: ['sessionId' => 'session-id']),
        ));

        self::assertSame($expected, $http->lastHeader($header));
    }

    public function testApiKeyOutsideAHeaderIsSkipped(): void
    {
        $store = new InMemoryContextCredentialStore();
        $store->setCredentials('s', 'k', 'secret');
        $context = new ClientCallContext(state: ['sessionId' => 's']);
        $card = self::card('k', ['k' => new SecurityScheme(['api_key_security_scheme' => new APIKeySecurityScheme(['name' => 'key', 'location' => 'query'])])]);

        (new AuthInterceptor($store))->before(new BeforeArgs(new SendMessageRequest(), 'send_message', $card, $context));

        self::assertNull($context->serviceParameters);
    }

    public function testCreatesAContextWhenTheCallHasNone(): void
    {
        $store = new class extends \A2A\Tests\Client\Auth\FixedCredentialService {};
        $card = self::card('bearer', ['bearer' => new SecurityScheme(['http_auth_security_scheme' => new HTTPAuthSecurityScheme(['scheme' => 'Bearer'])])]);
        $args = new BeforeArgs(new SendMessageRequest(), 'send_message', $card, null);

        (new AuthInterceptor($store))->before($args);

        self::assertSame(['Authorization' => 'Bearer fixed-token'], $args->context?->serviceParameters);
    }

    public function testSkipsWhenSchemeNotInSecuritySchemes(): void
    {
        $store = new InMemoryContextCredentialStore();
        $store->setCredentials('session-id', 'missing', 'test-token');
        $context = new ClientCallContext(state: ['sessionId' => 'session-id']);
        $card = self::card('missing', []);

        (new AuthInterceptor($store))->before(new BeforeArgs(new SendMessageRequest(['message' => new Message()]), 'send_message', $card, $context));

        self::assertNull($context->serviceParameters);
    }

    /**
     * @param array<string, SecurityScheme> $schemes
     */
    private static function card(string $schemeName, array $schemes): AgentCard
    {
        return new AgentCard([
            'supported_interfaces' => [new AgentInterface(['url' => 'http://agent.com/rpc', 'protocol_binding' => 'JSONRPC'])],
            'name' => $schemeName . 'bot',
            'description' => 'A bot that uses ' . $schemeName,
            'version' => '1.0',
            'capabilities' => new AgentCapabilities(),
            'security_requirements' => [new SecurityRequirement(['schemes' => [$schemeName => new StringList()]])],
            'security_schemes' => $schemes,
        ]);
    }
}
