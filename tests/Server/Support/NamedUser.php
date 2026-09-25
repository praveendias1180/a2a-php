<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Support;

use A2A\Auth\User;

final class NamedUser implements User
{
    public function __construct(private readonly string $name) {}

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function userName(): string
    {
        return $this->name;
    }
}
