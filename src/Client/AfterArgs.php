<?php

declare(strict_types=1);

namespace A2A\Client;

use A2A\Types\AgentCard;

/**
 * Arguments passed to an interceptor after a call. Interceptors may replace
 * `result`, or set `earlyReturn` to stop the remaining interceptors.
 *
 * Mirrors a2a-python: AfterArgs in src/a2a/client/interceptors.py
 */
final class AfterArgs
{
    public function __construct(
        public mixed $result,
        public string $method,
        public AgentCard $agentCard,
        public ?ClientCallContext $context = null,
        public bool $earlyReturn = false,
    ) {}
}
