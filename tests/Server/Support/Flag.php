<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Support;

/**
 * A mutable boolean for closures to report back through.
 */
final class Flag
{
    public bool $set = false;
}
