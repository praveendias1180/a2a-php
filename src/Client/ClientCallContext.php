<?php

declare(strict_types=1);

namespace A2A\Client;

/**
 * Per-call settings: free-form state (e.g. a `sessionId` for credential
 * lookup), a timeout in seconds, and service parameters (sent as HTTP
 * headers).
 *
 * Mirrors a2a-python: ClientCallContext in src/a2a/client/client.py
 */
final class ClientCallContext
{
    /**
     * @param array<string, mixed>       $state
     * @param array<string, string>|null $serviceParameters
     */
    public function __construct(
        public array $state = [],
        public ?float $timeout = null,
        public ?array $serviceParameters = null,
    ) {}
}
