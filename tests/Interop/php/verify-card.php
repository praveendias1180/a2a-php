<?php

/**
 * Verifies an Agent Card's signatures with the PHP SDK, for the cross-SDK
 * signing check (tests/Interop/python/signing_interop.py).
 *
 *     php verify-card.php <signed-card.json> <key file> <alg>   (exit 0: valid, 1: invalid)
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use A2A\Client\A2ACardResolver;
use A2A\Utils\Signing;
use A2A\Utils\Signing\SignatureVerificationError;

[, $cardFile, $keyFile, $alg] = $argv + [null, '', '', 'ES256'];
$data = json_decode((string) file_get_contents($cardFile), false, 512, JSON_THROW_ON_ERROR);
if (!$data instanceof stdClass) {
    fwrite(STDERR, "The card file must hold a JSON object.\n");
    exit(2);
}
$card = A2ACardResolver::parseAgentCard($data);
$key = (string) file_get_contents($keyFile);

try {
    Signing::createSignatureVerifier(static fn(): string => $key, [$alg])($card);
    echo "valid\n";
    exit(0);
} catch (SignatureVerificationError $e) {
    echo 'invalid: ', $e->getMessage(), "\n";
    exit(1);
}
