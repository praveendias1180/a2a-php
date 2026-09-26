<?php

declare(strict_types=1);

namespace A2A\Server;

use A2A\Auth\UnauthenticatedUser;
use A2A\Auth\User;

/**
 * Per-request server state: the authenticated user, free-form state (the
 * request headers live under `headers`), the tenant, the extensions the
 * client asked for, and the ones the agent activated (echoed back in the
 * `A2A-Extensions` response header).
 *
 * Mirrors a2a-python: ServerCallContext in src/a2a/server/context.py
 */
final class ServerCallContext
{
    /**
     * @param array<string, mixed> $state
     * @param list<string>         $requestedExtensions
     * @param list<string>         $activatedExtensions
     */
    public function __construct(
        public array $state = [],
        public User $user = new UnauthenticatedUser(),
        public string $tenant = '',
        public array $requestedExtensions = [],
        public array $activatedExtensions = [],
    ) {}

    /**
     * Marks an extension as active for this request (once).
     */
    public function activateExtension(string $uri): void
    {
        if (!in_array($uri, $this->activatedExtensions, true)) {
            $this->activatedExtensions[] = $uri;
        }
    }

    /**
     * The request headers captured by the context builder, or null when the
     * context was built without an HTTP request (tests, CLI).
     *
     * @return array<string, string>|null
     */
    public function headers(): ?array
    {
        $headers = $this->state['headers'] ?? null;
        if (!is_array($headers)) {
            return null;
        }

        $result = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $result[$name] = $value;
            }
        }

        return $result;
    }
}
