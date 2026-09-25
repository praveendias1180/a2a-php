<?php

declare(strict_types=1);

namespace A2A\Client\Auth;

use A2A\Client\ClientCallContext;

/**
 * Looks up a credential (e.g. a token) for a security scheme.
 *
 * Mirrors a2a-python: CredentialService in src/a2a/client/auth/credentials.py
 */
interface CredentialService
{
    public function getCredentials(string $securitySchemeName, ?ClientCallContext $context): ?string;
}
