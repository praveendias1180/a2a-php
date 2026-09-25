<?php

declare(strict_types=1);

namespace A2A\Client\Auth;

use A2A\Client\ClientCallContext;

/**
 * Credentials kept in memory per session, keyed by the `sessionId` in the
 * call context's state.
 *
 * Mirrors a2a-python: InMemoryContextCredentialStore in
 * src/a2a/client/auth/credentials.py
 */
final class InMemoryContextCredentialStore implements CredentialService
{
    /** @var array<string, array<string, string>> */
    private array $store = [];

    public function getCredentials(string $securitySchemeName, ?ClientCallContext $context): ?string
    {
        if ($context === null || !array_key_exists('sessionId', $context->state)) {
            return null;
        }
        $sessionId = $context->state['sessionId'];
        if (!is_string($sessionId) && !is_int($sessionId)) {
            return null;
        }

        return $this->store[(string) $sessionId][$securitySchemeName] ?? null;
    }

    public function setCredentials(string $sessionId, string $securitySchemeName, string $credential): void
    {
        $this->store[$sessionId][$securitySchemeName] = $credential;
    }
}
