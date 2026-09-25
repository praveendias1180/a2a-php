<?php

declare(strict_types=1);

namespace A2A\Client\Sse;

/**
 * One Server-Sent Event: its name (default "message") and its data, with
 * multi-line data joined by "\n".
 */
final class SseEvent
{
    public function __construct(
        public readonly string $event,
        public readonly string $data,
    ) {}
}
