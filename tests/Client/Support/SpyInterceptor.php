<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Support;

use A2A\Client\AfterArgs;
use A2A\Client\BeforeArgs;
use A2A\Client\ClientCallContext;
use A2A\Client\ClientCallInterceptor;

/**
 * Records what it sees; optionally short-circuits, replaces the context or
 * stops the after-chain.
 */
final class SpyInterceptor implements ClientCallInterceptor
{
    /** @var list<BeforeArgs> */
    public array $before = [];

    /** @var list<AfterArgs> */
    public array $after = [];

    /**
     * @param list<string> $log
     */
    public function __construct(
        private readonly mixed $earlyReturn = null,
        private readonly ?ClientCallContext $replaceContext = null,
        private readonly bool $stopAfter = false,
        public array &$log = [],
        private readonly string $name = 'spy',
    ) {}

    public function before(BeforeArgs $args): void
    {
        $this->before[] = clone $args;
        $this->log[] = 'before:' . $this->name;
        if ($this->replaceContext !== null) {
            $args->context = $this->replaceContext;
        }
        if ($this->earlyReturn !== null) {
            $args->earlyReturn = $this->earlyReturn;
        }
    }

    public function after(AfterArgs $args): void
    {
        $this->after[] = clone $args;
        $this->log[] = 'after:' . $this->name;
        if ($this->stopAfter) {
            $args->earlyReturn = true;
        }
    }
}
