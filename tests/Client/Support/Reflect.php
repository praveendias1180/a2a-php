<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Support;

/**
 * Reads a non-public property, like the Python tests' `client._transport`.
 */
final class Reflect
{
    public static function get(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
