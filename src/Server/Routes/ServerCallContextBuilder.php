<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use A2A\Server\ServerCallContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the ServerCallContext for an incoming HTTP request. Implement it
 * to plug in your authentication.
 *
 * Mirrors a2a-python: ServerCallContextBuilder in
 * src/a2a/server/routes/common.py (PSR-7 requests instead of Starlette's).
 */
interface ServerCallContextBuilder
{
    public function build(ServerRequestInterface $request): ServerCallContext;
}
