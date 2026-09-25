<?php

declare(strict_types=1);

namespace A2A\Server;

/**
 * What an IdGenerator may use to build an id.
 *
 * Mirrors a2a-python: IDGeneratorContext in src/a2a/server/id_generator.py
 */
final class IdGeneratorContext
{
    public function __construct(
        public readonly ?string $taskId = null,
        public readonly ?string $contextId = null,
    ) {}
}
