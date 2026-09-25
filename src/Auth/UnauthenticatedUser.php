<?php

declare(strict_types=1);

namespace A2A\Auth;

/**
 * A representation that no user has been authenticated in the request.
 *
 * Mirrors a2a-python: UnauthenticatedUser in src/a2a/auth/user.py
 */
final class UnauthenticatedUser implements User
{
    public function isAuthenticated(): bool
    {
        return false;
    }

    public function userName(): string
    {
        return '';
    }
}
