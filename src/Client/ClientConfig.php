<?php

declare(strict_types=1);

namespace A2A\Client;

use A2A\Types\TaskPushNotificationConfig;

/**
 * Configuration for ClientFactory.
 *
 * Mirrors a2a-python: ClientConfig in src/a2a/client/client.py. Differences:
 * `httpClient` replaces `httpx_client` and takes any client
 * HttpSenderFactory understands (Guzzle, Symfony HttpClient, any PSR-18
 * client, or an HttpSender); there is no `grpc_channel_factory` yet (gRPC
 * is post-1.0).
 */
final class ClientConfig
{
    /**
     * @param bool                    $streaming                 whether the client supports streaming
     * @param bool                    $polling                   whether the client prefers to poll after message:send
     *                                                           (sets returnImmediately; the caller runs the polling loop)
     * @param object|null             $httpClient                HTTP client to connect to agents with (null: discovered)
     * @param list<string>            $supportedProtocolBindings transports in order of preference; empty means JSON-RPC only
     * @param bool                    $useClientPreference       use the client's transport order instead of the server's
     * @param list<string>            $acceptedOutputModes       output modes to send when a request sets none
     * @param TaskPushNotificationConfig|null $pushNotificationConfig push config to send on every request that sets none
     */
    public function __construct(
        public bool $streaming = true,
        public bool $polling = false,
        public ?object $httpClient = null,
        public array $supportedProtocolBindings = [],
        public bool $useClientPreference = false,
        public array $acceptedOutputModes = [],
        public ?TaskPushNotificationConfig $pushNotificationConfig = null,
    ) {}
}
