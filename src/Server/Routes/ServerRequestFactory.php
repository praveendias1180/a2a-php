<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use Http\Discovery\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a PSR-7 request from PHP's superglobals, using whichever PSR-7
 * implementation is installed (found through php-http/discovery).
 */
final class ServerRequestFactory
{
    private function __construct() {}

    public static function fromGlobals(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequestFromGlobals();
    }
}
