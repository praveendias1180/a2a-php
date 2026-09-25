<?php

declare(strict_types=1);

namespace A2A\Server\AgentExecution;

/**
 * Thrown into a running execute() when the task is cancelled.
 *
 * PHP's counterpart to the asyncio.CancelledError a2a-python raises inside
 * the producer task. Catch it to clean up; don't swallow it and carry on.
 */
final class TaskCancelledException extends \RuntimeException {}
