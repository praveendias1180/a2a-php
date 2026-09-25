<?php

declare(strict_types=1);

namespace A2A\Server\Events;

/**
 * Raised when enqueueing to a closed queue.
 *
 * Mirrors a2a-python: QueueShutDown in src/a2a/server/events/event_queue_v2.py
 */
final class QueueClosedException extends \RuntimeException {}
