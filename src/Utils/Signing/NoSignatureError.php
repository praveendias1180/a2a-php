<?php

declare(strict_types=1);

namespace A2A\Utils\Signing;

/**
 * The Agent Card has no signatures to verify.
 *
 * Mirrors a2a-python: NoSignatureError in src/a2a/utils/signing.py
 */
final class NoSignatureError extends SignatureVerificationError {}
