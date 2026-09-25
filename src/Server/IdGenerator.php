<?php

declare(strict_types=1);

namespace A2A\Server;

/**
 * Generates task, context, message and artifact ids.
 *
 * Mirrors a2a-python: IDGenerator in src/a2a/server/id_generator.py
 */
interface IdGenerator
{
    public function generate(IdGeneratorContext $context): string;
}
