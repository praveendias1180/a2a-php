<?php

declare(strict_types=1);

namespace A2A\Client;

use A2A\Client\Http\HttpSender;
use A2A\Client\Http\HttpSenderFactory;
use A2A\Client\Transports\ClientTransport;
use A2A\Client\Transports\JsonRpcTransport;
use A2A\Client\Transports\RestTransport;
use A2A\Client\Transports\TenantTransportDecorator;
use A2A\Compat\V0_3\CompatJsonRpcTransport;
use A2A\Compat\V0_3\CompatRestTransport;
use A2A\Compat\V0_3\Versions;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Utils\Constants;
use A2A\Utils\TransportProtocol;

/**
 * Creates clients for agents, picking a transport both sides support.
 *
 *     $factory = new ClientFactory($config);
 *     $factory->register('my-transport', $producer);    // optional
 *     $client = $factory->create($card);                 // from a card
 *     $client = $factory->createFromUrl('https://...');  // or resolve the card first
 *
 * Or in one call: ClientFactory::createClient('https://...').
 *
 * Mirrors a2a-python: ClientFactory, create_client() and
 * minimal_agent_card() in src/a2a/client/client_factory.py. Python's
 * module-level create_client() is the static createClient() here (PHP can't
 * have a static and an instance method both named create()). gRPC is not
 * available yet. An agent whose chosen interface is A2A 0.3 gets the v0.3
 * compatibility transports (Compat\V0_3), as in Python.
 */
final class ClientFactory
{
    private readonly ClientConfig $config;
    private readonly HttpSender $httpSender;

    /** @var array<string, callable(AgentCard, string, ClientConfig): ClientTransport> */
    private array $registry = [];

    public function __construct(?ClientConfig $config = null)
    {
        $this->config = $config ?? new ClientConfig();
        $this->httpSender = HttpSenderFactory::create($this->config->httpClient);
        $this->registerDefaults($this->config->supportedProtocolBindings);
    }

    /**
     * Registers a transport producer for a protocol binding label.
     *
     * @param callable(AgentCard, string, ClientConfig): ClientTransport $producer
     */
    public function register(string $label, callable $producer): void
    {
        $this->registry[$label] = $producer;
    }

    /**
     * Creates a client for $card.
     *
     * @param list<ClientCallInterceptor> $interceptors
     *
     * @throws \ValueError when no transport matches
     */
    public function create(AgentCard $card, array $interceptors = []): Client
    {
        $clientSet = $this->config->supportedProtocolBindings !== []
            ? $this->config->supportedProtocolBindings
            : [TransportProtocol::JSONRPC->value];
        $interfaces = iterator_to_array($card->getSupportedInterfaces(), false);

        $transportProtocol = null;
        $selected = null;
        if ($this->config->useClientPreference) {
            foreach ($clientSet as $binding) {
                $selected = self::findBestInterface($interfaces, [$binding]);
                if ($selected !== null) {
                    $transportProtocol = $binding;

                    break;
                }
            }
        } else {
            foreach ($interfaces as $interface) {
                if (in_array($interface->getProtocolBinding(), $clientSet, true)) {
                    $transportProtocol = $interface->getProtocolBinding();
                    $selected = self::findBestInterface($interfaces, [$transportProtocol]);

                    break;
                }
            }
        }
        if ($transportProtocol === null || $selected === null) {
            throw new \ValueError('no compatible transports found.');
        }
        if (!isset($this->registry[$transportProtocol])) {
            throw new \ValueError('no client available for ' . $transportProtocol);
        }

        $transport = ($this->registry[$transportProtocol])($card, $selected->getUrl(), $this->config);
        if ($selected->getTenant() !== '') {
            $transport = new TenantTransportDecorator($transport, $selected->getTenant());
        }

        return new BaseClient($card, $this->config, $transport, $interceptors);
    }

    /**
     * Resolves the agent card from $url and creates a client for it.
     *
     * @param list<ClientCallInterceptor>                              $interceptors
     * @param array{headers?: array<string, string>, timeout?: float} $resolverHttpKwargs
     * @param (callable(AgentCard): void)|null                          $signatureVerifier
     */
    public function createFromUrl(
        string $url,
        array $interceptors = [],
        ?string $relativeCardPath = null,
        array $resolverHttpKwargs = [],
        ?callable $signatureVerifier = null,
    ): Client {
        $resolver = new A2ACardResolver($this->httpSender, $url);
        $card = $resolver->getAgentCard($relativeCardPath, $resolverHttpKwargs, $signatureVerifier);

        return $this->create($card, $interceptors);
    }

    /**
     * Creates a client from a base URL (the card is fetched) or a card.
     * Python: create_client().
     *
     * @param list<ClientCallInterceptor>                              $interceptors
     * @param array{headers?: array<string, string>, timeout?: float} $resolverHttpKwargs
     * @param (callable(AgentCard): void)|null                          $signatureVerifier
     */
    public static function createClient(
        string|AgentCard $agent,
        ?ClientConfig $clientConfig = null,
        array $interceptors = [],
        ?string $relativeCardPath = null,
        array $resolverHttpKwargs = [],
        ?callable $signatureVerifier = null,
    ): Client {
        $factory = new self($clientConfig);
        if (is_string($agent)) {
            return $factory->createFromUrl($agent, $interceptors, $relativeCardPath, $resolverHttpKwargs, $signatureVerifier);
        }

        return $factory->create($agent, $interceptors);
    }

    /**
     * A bare card for a known URL and transports: enough to create a client
     * and then fetch the real card with getExtendedAgentCard().
     *
     * @param list<string> $transports
     */
    public static function minimalAgentCard(string $url, array $transports = []): AgentCard
    {
        return new AgentCard([
            'supported_interfaces' => array_map(
                static fn(string $t): AgentInterface => new AgentInterface(['protocol_binding' => $t, 'url' => $url]),
                $transports,
            ),
            'capabilities' => new AgentCapabilities(['extended_agent_card' => true]),
            'default_input_modes' => [],
            'default_output_modes' => [],
            'description' => '',
            'skills' => [],
            'version' => '',
            'name' => '',
        ]);
    }

    /**
     * The best interface for the given bindings (and URL): version 1.0 first,
     * then the first above 1.0, then the first at or above 0.3, then the first
     * without a version.
     *
     * @param list<AgentInterface> $interfaces
     * @param list<string>|null    $protocolBindings
     *
     * @internal
     */
    public static function findBestInterface(array $interfaces, ?array $protocolBindings = null, ?string $url = null): ?AgentInterface
    {
        $candidates = array_values(array_filter(
            $interfaces,
            static fn(AgentInterface $i): bool => ($protocolBindings === null || in_array($i->getProtocolBinding(), $protocolBindings, true))
                && ($url === null || $i->getUrl() === $url),
        ));
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate->getProtocolVersion() === Constants::PROTOCOL_VERSION_1_0) {
                return $candidate;
            }
        }

        $bestGt10 = null;
        $bestGe03 = null;
        $bestNoVersion = null;
        foreach ($candidates as $candidate) {
            $version = $candidate->getProtocolVersion();
            if ($version === '') {
                $bestNoVersion ??= $candidate;

                continue;
            }
            if (!self::isValidVersion($version)) {
                continue;
            }
            if ($bestGt10 === null && version_compare($version, Constants::PROTOCOL_VERSION_1_0, '>')) {
                $bestGt10 = $candidate;
            }
            if ($bestGe03 === null && version_compare($version, Constants::PROTOCOL_VERSION_0_3, '>=')) {
                $bestGe03 = $candidate;
            }
        }

        return $bestGt10 ?? $bestGe03 ?? $bestNoVersion;
    }

    /**
     * True for versions at or above 0.3 and below 1.0. Python:
     * a2a.compat.v0_3.versions.is_legacy_version().
     */
    public static function isLegacyVersion(?string $version): bool
    {
        return Versions::isLegacyVersion($version);
    }

    /**
     * @param list<string> $supported
     */
    private function registerDefaults(array $supported): void
    {
        // An empty list means JSON-RPC only. For a v0.3 interface the v0.3
        // compatibility transport is used, so calling code stays on v1.0 types.
        if ($supported === [] || in_array(TransportProtocol::JSONRPC->value, $supported, true)) {
            $this->register(TransportProtocol::JSONRPC->value, function (AgentCard $card, string $url, ClientConfig $config): ClientTransport {
                return $this->isLegacyInterface($card, TransportProtocol::JSONRPC->value, $url)
                    ? new CompatJsonRpcTransport($this->httpSender, $card, $url)
                    : new JsonRpcTransport($this->httpSender, $card, $url);
            });
        }
        if (in_array(TransportProtocol::HTTP_JSON->value, $supported, true)) {
            $this->register(TransportProtocol::HTTP_JSON->value, function (AgentCard $card, string $url, ClientConfig $config): ClientTransport {
                return $this->isLegacyInterface($card, TransportProtocol::HTTP_JSON->value, $url)
                    ? new CompatRestTransport($this->httpSender, $card, $url)
                    : new RestTransport($this->httpSender, $card, $url);
            });
        }
    }

    private function isLegacyInterface(AgentCard $card, string $binding, string $url): bool
    {
        $interface = self::findBestInterface(iterator_to_array($card->getSupportedInterfaces(), false), [$binding], $url);

        return self::isLegacyVersion($interface?->getProtocolVersion() ?? Constants::PROTOCOL_VERSION_CURRENT);
    }

    private static function isValidVersion(string $version): bool
    {
        return preg_match('/^v?\d+(\.\d+)*([a-z]+\d*)?$/i', $version) === 1;
    }
}
