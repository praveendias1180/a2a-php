<?php

declare(strict_types=1);

namespace A2A\Server;

use A2A\Utils\Uuid;

/**
 * Random UUIDv4 ids.
 *
 * Mirrors a2a-python: UUIDGenerator in src/a2a/server/id_generator.py
 */
final class UuidGenerator implements IdGenerator
{
    public function generate(IdGeneratorContext $context): string
    {
        return Uuid::v4();
    }
}
