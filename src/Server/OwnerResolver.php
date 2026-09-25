<?php

declare(strict_types=1);

namespace A2A\Server;

/**
 * Decides which owner a task belongs to. Stores scope every read and write
 * by the owner, so a task owned by someone else looks exactly like a task
 * that does not exist.
 *
 * Mirrors a2a-python: src/a2a/server/owner_resolver.py, where an owner
 * resolver is any `Callable[[ServerCallContext], str]`. Here it is a
 * `\Closure(ServerCallContext): string`; this class holds the default.
 */
final class OwnerResolver
{
    private function __construct() {}

    /**
     * The default resolver: the authenticated user's name (empty string for
     * unauthenticated callers, who all share one scope).
     */
    public static function resolveUserScope(ServerCallContext $context): string
    {
        return $context->user->userName();
    }

    /**
     * @return \Closure(ServerCallContext): string
     */
    public static function default(): \Closure
    {
        return self::resolveUserScope(...);
    }
}
