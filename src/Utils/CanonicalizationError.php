<?php

declare(strict_types=1);

namespace A2A\Utils;

/**
 * Raised when a value has no RFC 8785 canonical form.
 *
 * Mirrors a2a-python: CanonicalizationError in src/a2a/utils/_jcs.py.
 */
final class CanonicalizationError extends \InvalidArgumentException {}
