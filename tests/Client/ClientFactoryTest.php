<?php

declare(strict_types=1);

namespace A2A\Tests\Client;

use A2A\Client\BaseClient;
use A2A\Client\ClientConfig;
use A2A\Client\ClientFactory;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Transports\ClientTransport;
use A2A\Client\Transports\JsonRpcTransport;
use A2A\Client\Transports\RestTransport;
use A2A\Client\Transports\TenantTransportDecorator;
use A2A\Tests\Client\Support\FakeHttpSender;
use A2A\Tests\Client\Support\RecordingTransport;
use A2A\Tests\Client\Support\Reflect;
use A2A\Tests\Client\Support\SpyInterceptor;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Utils\TransportProtocol;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/test_client_factory.py (gRPC cases excluded).
 */
final class ClientFactoryTest extends TestCase
{
    private AgentCard $card;
    private FakeHttpSender $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpSender();
        $this->card = new AgentCard([
            'name' => 'Test Agent',
            'description' => 'An agent for testing.',
            'supported_interfaces' => [new AgentInterface(['protocol_binding' => 'JSONRPC', 'url' => 'http://primary-url.com'])],
            'version' => '1.0.0',
            'capabilities' => new AgentCapabilities(),
        ]);
    }

    public function testSelectsPreferredTransport(): void
    {
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http, supportedProtocolBindings: ['JSONRPC', 'HTTP+JSON']));

        $transport = self::transportOf($factory->create($this->card));

        self::assertInstanceOf(JsonRpcTransport::class, $transport);
        self::assertSame('http://primary-url.com', $transport->url);
        self::assertSame($this->http, $transport->httpSender);
    }

    public function testSelectsSecondaryTransportUrl(): void
    {
        $this->card->getSupportedInterfaces()[] = new AgentInterface(['protocol_binding' => 'HTTP+JSON', 'url' => 'http://secondary-url.com']);
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http, supportedProtocolBindings: ['HTTP+JSON', 'JSONRPC'], useClientPreference: true));

        $transport = self::transportOf($factory->create($this->card));

        self::assertInstanceOf(RestTransport::class, $transport);
        self::assertSame('http://secondary-url.com', $transport->url);
    }

    public function testServerPreference(): void
    {
        $this->card->setSupportedInterfaces([
            new AgentInterface(['protocol_binding' => 'HTTP+JSON', 'url' => 'http://primary-url.com']),
            new AgentInterface(['protocol_binding' => 'JSONRPC', 'url' => 'http://secondary-url.com']),
        ]);
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http, supportedProtocolBindings: ['JSONRPC', 'HTTP+JSON']));

        $transport = self::transportOf($factory->create($this->card));

        self::assertInstanceOf(RestTransport::class, $transport);
        self::assertSame('http://primary-url.com', $transport->url);
    }

    public function testNoCompatibleTransport(): void
    {
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http, supportedProtocolBindings: ['UNKNOWN_PROTOCOL']));

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('no compatible transports found');
        $factory->create($this->card);
    }

    public function testCreateWithDefaultConfig(): void
    {
        $transport = self::transportOf((new ClientFactory())->create($this->card));

        self::assertInstanceOf(JsonRpcTransport::class, $transport);
        self::assertSame('http://primary-url.com', $transport->url);
    }

    public function testCreateFromUrl(): void
    {
        $this->http->queueBody($this->card->serializeToJsonString());
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http));

        $client = $factory->createFromUrl('http://example.com');

        self::assertSame('http://example.com/.well-known/agent-card.json', $this->http->lastRequest()->url);
        self::assertInstanceOf(JsonRpcTransport::class, self::transportOf($client));
    }

    public function testCreateFromUrlPassesResolverArgs(): void
    {
        $this->http->queueBody($this->card->serializeToJsonString());
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http));

        $factory->createFromUrl('http://example.com', [], '/extended-card', ['headers' => ['Authorization' => 'Bearer test-token'], 'timeout' => 5.0]);

        $request = $this->http->lastRequest();
        self::assertSame('http://example.com/extended-card', $request->url);
        self::assertSame('Bearer test-token', $this->http->lastHeader('Authorization'));
        self::assertSame(5.0, $request->timeout);
    }

    public function testRegisterAndCreateCustomTransport(): void
    {
        $this->card->setSupportedInterfaces([
            new AgentInterface(['protocol_binding' => 'custom', 'url' => 'custom://foo']),
            new AgentInterface(['protocol_binding' => 'JSONRPC', 'url' => 'http://primary-url.com']),
        ]);
        $custom = new RecordingTransport();
        $seen = [];
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http, supportedProtocolBindings: ['custom']));
        $factory->register('custom', static function (AgentCard $card, string $url, ClientConfig $config) use ($custom, &$seen): ClientTransport {
            $seen[] = $url;

            return $custom;
        });

        $client = $factory->create($this->card);

        self::assertSame($custom, self::transportOf($client));
        self::assertSame(['custom://foo'], $seen);
    }

    public function testCreateFromUrlUsesRegisteredTransports(): void
    {
        $this->card->setSupportedInterfaces([new AgentInterface(['protocol_binding' => 'custom', 'url' => 'custom://foo'])]);
        $this->http->queueBody($this->card->serializeToJsonString());
        $custom = new RecordingTransport();
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http, supportedProtocolBindings: ['custom']));
        $factory->register('custom', static fn(): ClientTransport => $custom);

        self::assertSame($custom, self::transportOf($factory->createFromUrl('http://example.com')));
    }

    public function testCreateWithInterceptors(): void
    {
        $interceptor = new SpyInterceptor();

        $client = (new ClientFactory(new ClientConfig(httpClient: $this->http)))->create($this->card, [$interceptor]);

        self::assertSame([$interceptor], Reflect::get($client, 'interceptors'));
    }

    public function testAppliesTenantDecorator(): void
    {
        $interface = $this->card->getSupportedInterfaces()[0];
        self::assertInstanceOf(AgentInterface::class, $interface);
        $interface->setTenant('my-tenant');
        $factory = new ClientFactory(new ClientConfig(httpClient: $this->http, supportedProtocolBindings: ['JSONRPC']));

        $transport = self::transportOf($factory->create($this->card));

        self::assertInstanceOf(TenantTransportDecorator::class, $transport);
        self::assertSame('my-tenant', Reflect::get($transport, 'tenant'));
        self::assertInstanceOf(JsonRpcTransport::class, Reflect::get($transport, 'base'));
    }

    public function testCreateClientWithAgentCard(): void
    {
        $transport = self::transportOf(ClientFactory::createClient($this->card, new ClientConfig(httpClient: $this->http)));

        self::assertInstanceOf(JsonRpcTransport::class, $transport);
        self::assertSame('http://primary-url.com', $transport->url);
    }

    public function testCreateClientWithUrlAndConfig(): void
    {
        $this->http->queueBody($this->card->serializeToJsonString());

        $client = ClientFactory::createClient('http://example.com', new ClientConfig(httpClient: $this->http));

        self::assertSame('http://example.com/.well-known/agent-card.json', $this->http->lastRequest()->url);
        self::assertInstanceOf(JsonRpcTransport::class, self::transportOf($client));
    }

    public function testPrefersTheVersion10InterfaceForABinding(): void
    {
        $this->card->setSupportedInterfaces([
            new AgentInterface(['protocol_binding' => 'JSONRPC', 'url' => 'http://old', 'protocol_version' => '0.3']),
            new AgentInterface(['protocol_binding' => 'JSONRPC', 'url' => 'http://new', 'protocol_version' => '1.0']),
        ]);

        $transport = self::transportOf((new ClientFactory(new ClientConfig(httpClient: $this->http)))->create($this->card));

        self::assertInstanceOf(JsonRpcTransport::class, $transport);
        self::assertSame('http://new', $transport->url);
    }

    public function testRefusesAnAgentThatOnlySpeaksA2A03(): void
    {
        $this->card->setSupportedInterfaces([new AgentInterface(['protocol_binding' => 'JSONRPC', 'url' => 'http://old', 'protocol_version' => '0.3.0'])]);

        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('0.3 compatibility is planned');
        (new ClientFactory(new ClientConfig(httpClient: $this->http)))->create($this->card);
    }

    public function testFindBestInterfaceOrder(): void
    {
        $make = static fn(string $version, string $url): AgentInterface => new AgentInterface(['protocol_binding' => 'JSONRPC', 'url' => $url, 'protocol_version' => $version]);

        self::assertSame('b', ClientFactory::findBestInterface([$make('0.3', 'a'), $make('1.1', 'b'), $make('', 'c')])?->getUrl());
        self::assertSame('a', ClientFactory::findBestInterface([$make('0.3', 'a'), $make('', 'c')])?->getUrl());
        self::assertSame('c', ClientFactory::findBestInterface([$make('0.2', 'a'), $make('', 'c')])?->getUrl());
        self::assertSame('c', ClientFactory::findBestInterface([$make('garbage', 'a'), $make('', 'c')])?->getUrl());
        self::assertNull(ClientFactory::findBestInterface([$make('1.0', 'a')], ['HTTP+JSON']));
        self::assertSame('a', ClientFactory::findBestInterface([$make('1.0', 'a'), $make('1.0', 'b')], null, 'a')?->getUrl());
    }

    public function testIsLegacyVersion(): void
    {
        self::assertTrue(ClientFactory::isLegacyVersion('0.3'));
        self::assertTrue(ClientFactory::isLegacyVersion('0.3.0'));
        self::assertTrue(ClientFactory::isLegacyVersion('0.9'));
        self::assertFalse(ClientFactory::isLegacyVersion('1.0'));
        self::assertFalse(ClientFactory::isLegacyVersion('0.2.6'));
        self::assertFalse(ClientFactory::isLegacyVersion(''));
        self::assertFalse(ClientFactory::isLegacyVersion(null));
        self::assertFalse(ClientFactory::isLegacyVersion('not-a-version'));
    }

    public function testMinimalAgentCard(): void
    {
        $card = ClientFactory::minimalAgentCard('https://agent.example.com', [TransportProtocol::JSONRPC->value, TransportProtocol::HTTP_JSON->value]);

        self::assertCount(2, $card->getSupportedInterfaces());
        self::assertSame('https://agent.example.com', $card->getSupportedInterfaces()[1]->getUrl());
        self::assertTrue($card->getCapabilities()?->getExtendedAgentCard());
        self::assertSame('', $card->getName());
    }

    private static function transportOf(\A2A\Client\Client $client): ClientTransport
    {
        self::assertInstanceOf(BaseClient::class, $client);
        $transport = Reflect::get($client, 'transport');
        self::assertInstanceOf(ClientTransport::class, $transport);

        return $transport;
    }
}
