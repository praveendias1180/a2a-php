<?php

declare(strict_types=1);

namespace A2A\Client;

use A2A\Types\AgentCard;

/**
 * Arguments passed to an interceptor before a call. Interceptors may replace
 * `input` or `context`, or set `earlyReturn` to skip the transport call.
 *
 * Mirrors a2a-python: BeforeArgs in src/a2a/client/interceptors.py
 */
final class BeforeArgs
{
    public function __construct(
        public mixed $input,
        public string $method,
        public AgentCard $agentCard,
        public ?ClientCallContext $context = null,
        public mixed $earlyReturn = null,
    ) {}
}
