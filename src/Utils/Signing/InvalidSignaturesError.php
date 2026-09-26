<?php

declare(strict_types=1);

namespace A2A\Utils\Signing;

/**
 * None of the Agent Card's signatures is valid.
 *
 * Mirrors a2a-python: InvalidSignaturesError in src/a2a/utils/signing.py
 */
final class InvalidSignaturesError extends SignatureVerificationError {}
