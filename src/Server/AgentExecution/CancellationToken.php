<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

/**
 * Tells a running executor whether its task was cancelled, possibly by a
 * request in another process.
 *
 * PHP-specific: a2a-python cancels the producer asyncio task directly.
 */
final class CancellationToken
{
    /** @var \Closure(): bool */
    private readonly \Closure $check;

    private bool $cancelled = false;

    /**
     * @param (\Closure(): bool)|null $check
     */
    public function __construct(?\Closure $check = null)
    {
        $this->check = $check ?? static fn(): bool => false;
    }

    public static function none(): self
    {
        return new self();
    }

    public function isCancelled(): bool
    {
        if (!$this->cancelled) {
            $this->cancelled = ($this->check)();
        }

        return $this->cancelled;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }
}
