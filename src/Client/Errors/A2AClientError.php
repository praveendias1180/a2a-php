<?php

declare(strict_types=1);

namespace A2A\Client\Errors;

use A2A\Utils\Errors\A2AError;

/**
 * Base exception for A2A client errors.
 *
 * Mirrors a2a-python: A2AClientError in src/a2a/client/errors.py
 */
class A2AClientError extends A2AError {}
