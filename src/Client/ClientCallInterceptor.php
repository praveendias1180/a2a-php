<?php

declare(strict_types=1);

namespace A2A\Client;

/**
 * Inspects or changes calls before they are sent and results after they
 * return: authentication, logging, tracing.
 *
 * Mirrors a2a-python: ClientCallInterceptor in src/a2a/client/interceptors.py
 */
interface ClientCallInterceptor
{
    public function before(BeforeArgs $args): void;

    public function after(AfterArgs $args): void;
}
