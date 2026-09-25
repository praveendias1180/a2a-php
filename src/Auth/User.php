<?php

declare(strict_types=1);

namespace A2A\Auth;

/**
 * A representation of an authenticated user.
 *
 * Mirrors a2a-python: User in src/a2a/auth/user.py (abstract properties
 * become interface methods).
 */
interface User
{
    /** Whether the current user is authenticated. */
    public function isAuthenticated(): bool;

    /** The user name of the current user. */
    public function userName(): string;
}
