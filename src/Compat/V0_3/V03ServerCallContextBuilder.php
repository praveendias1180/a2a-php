<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Extensions\Common;
use A2A\Server\Routes\ServerCallContextBuilder;
use A2A\Server\ServerCallContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Wraps a ServerCallContextBuilder so the legacy v0.3 `X-A2A-Extensions`
 * header counts as requested extensions too.
 *
 * Mirrors a2a-python: V03ServerCallContextBuilder in
 * src/a2a/compat/v0_3/context_builders.py.
 *
 * @internal Not covered by the 1.x backward-compatibility promise; may change in any release.
 */
final class V03ServerCallContextBuilder implements ServerCallContextBuilder
{
    public function __construct(private readonly ServerCallContextBuilder $inner) {}

    public function build(ServerRequestInterface $request): ServerCallContext
    {
        $context = $this->inner->build($request);
        $legacy = Common::getRequestedExtensions(array_values($request->getHeader(ExtensionHeaders::LEGACY_HTTP_EXTENSION_HEADER)));
        $context->requestedExtensions = array_values(array_unique([...$context->requestedExtensions, ...$legacy]));

        return $context;
    }
}
