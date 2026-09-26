<?php

declare(strict_types=1);

namespace A2A\Utils\Signing;

/**
 * Base error for Agent Card signature verification.
 *
 * Mirrors a2a-python: SignatureVerificationError in src/a2a/utils/signing.py
 */
class SignatureVerificationError extends \RuntimeException {}
