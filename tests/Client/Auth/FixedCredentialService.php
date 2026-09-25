<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Auth;

use A2A\Client\Auth\CredentialService;
use A2A\Client\ClientCallContext;

/**
 * Returns the same credential for every scheme, with or without a context.
 */
class FixedCredentialService implements CredentialService
{
    public function getCredentials(string $securitySchemeName, ?ClientCallContext $context): ?string
    {
        return 'fixed-token';
    }
}
