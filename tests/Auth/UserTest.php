<?php

declare(strict_types=1);

namespace A2A\Tests\Auth;

use A2A\Auth\UnauthenticatedUser;
use A2A\Auth\User;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/auth/test_user.py.
 */
final class UserTest extends TestCase
{
    public function testUserIsAnInterface(): void
    {
        self::assertTrue((new \ReflectionClass(User::class))->isInterface());
    }

    public function testUnauthenticatedUserIsAUser(): void
    {
        self::assertInstanceOf(User::class, new UnauthenticatedUser());
    }

    public function testIsAuthenticatedReturnsFalse(): void
    {
        self::assertFalse((new UnauthenticatedUser())->isAuthenticated());
    }

    public function testUserNameReturnsEmptyString(): void
    {
        self::assertSame('', (new UnauthenticatedUser())->userName());
    }
}
