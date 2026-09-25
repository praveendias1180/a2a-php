<?php

declare(strict_types=1);

namespace A2A\Client\Auth;

use A2A\Client\AfterArgs;
use A2A\Client\BeforeArgs;
use A2A\Client\ClientCallContext;
use A2A\Client\ClientCallInterceptor;
use A2A\Types\APIKeySecurityScheme;
use A2A\Types\HTTPAuthSecurityScheme;
use A2A\Types\SecurityScheme;

/**
 * Adds credentials to requests based on the agent card's security schemes:
 * HTTP Bearer, OAuth2 and OpenID Connect as `Authorization: Bearer ...`, and
 * API keys located in a header. API keys in a query string or cookie are
 * skipped, as in Python.
 *
 * Mirrors a2a-python: AuthInterceptor in src/a2a/client/auth/interceptor.py
 */
final class AuthInterceptor implements ClientCallInterceptor
{
    public function __construct(private readonly CredentialService $credentialService) {}

    public function before(BeforeArgs $args): void
    {
        $card = $args->agentCard;
        if (count($card->getSecurityRequirements()) === 0 || count($card->getSecuritySchemes()) === 0) {
            return;
        }
        $schemes = $card->getSecuritySchemes();

        foreach ($card->getSecurityRequirements() as $requirement) {
            foreach ($requirement->getSchemes() as $schemeName => $_scopes) {
                if (!is_string($schemeName)) {
                    continue;
                }
                $credential = $this->credentialService->getCredentials($schemeName, $args->context);
                $scheme = isset($schemes[$schemeName]) ? $schemes[$schemeName] : null;
                if ($credential === null || $credential === '' || !$scheme instanceof SecurityScheme) {
                    continue;
                }

                $header = self::headerFor($scheme, $credential);
                if ($header !== null) {
                    $args->context ??= new ClientCallContext();
                    $parameters = $args->context->serviceParameters ?? [];
                    $parameters[$header[0]] = $header[1];
                    $args->context->serviceParameters = $parameters;

                    return;
                }
            }
        }
    }

    public function after(AfterArgs $args): void {}

    /**
     * @return array{string, string}|null header name and value
     */
    private static function headerFor(SecurityScheme $scheme, string $credential): ?array
    {
        $http = $scheme->getHttpAuthSecurityScheme();
        if ($http instanceof HTTPAuthSecurityScheme && strtolower($http->getScheme()) === 'bearer') {
            return ['Authorization', 'Bearer ' . $credential];
        }

        // OAuth2 and OpenID Connect are implicitly Bearer.
        if ($scheme->hasOauth2SecurityScheme() || $scheme->hasOpenIdConnectSecurityScheme()) {
            return ['Authorization', 'Bearer ' . $credential];
        }

        $apiKey = $scheme->getApiKeySecurityScheme();
        if ($apiKey instanceof APIKeySecurityScheme && strtolower($apiKey->getLocation()) === 'header') {
            return [$apiKey->getName(), $credential];
        }

        return null;
    }
}
