<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use A2A\Auth\UnauthenticatedUser;
use A2A\Auth\User;
use A2A\Extensions\Common;
use A2A\Server\ServerCallContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The default context: request headers under `state['headers']`, the
 * requested extensions from the `A2A-Extensions` header, and the user your
 * auth middleware put on the request as the `a2a.user` attribute (an
 * A2A\Auth\User), or an unauthenticated user.
 *
 * Mirrors a2a-python: DefaultServerCallContextBuilder in
 * src/a2a/server/routes/common.py. Python reads Starlette's `request.user`;
 * PSR-7 has no standard user slot, hence the attribute.
 */
class DefaultServerCallContextBuilder implements ServerCallContextBuilder
{
    public const USER_ATTRIBUTE = 'a2a.user';

    public function build(ServerRequestInterface $request): ServerCallContext
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        $state = ['headers' => $headers];
        $auth = $request->getAttribute('a2a.auth');
        if ($auth !== null) {
            $state['auth'] = $auth;
        }

        return new ServerCallContext(
            state: $state,
            user: $this->buildUser($request),
            requestedExtensions: Common::getRequestedExtensions(array_values($request->getHeader(Common::HTTP_EXTENSION_HEADER))),
        );
    }

    public function buildUser(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(self::USER_ATTRIBUTE);

        return $user instanceof User ? $user : new UnauthenticatedUser();
    }
}
