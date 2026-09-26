<?php

/**
 * Signs an Agent Card with the PHP SDK, for the cross-SDK signing check
 * (tests/Interop/python/signing_interop.py).
 *
 *     php sign-card.php <card.json> <key file> <alg> <kid>   (prints the signed card JSON)
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use A2A\Client\A2ACardResolver;
use A2A\Utils\Signing;

[, $cardFile, $keyFile, $alg, $kid] = $argv + [null, '', '', 'ES256', 'php-key'];
$data = json_decode((string) file_get_contents($cardFile), false, 512, JSON_THROW_ON_ERROR);
if (!$data instanceof stdClass) {
    fwrite(STDERR, "The card file must hold a JSON object.\n");
    exit(2);
}
$card = A2ACardResolver::parseAgentCard($data);
$signed = Signing::createAgentCardSigner((string) file_get_contents($keyFile), ['alg' => $alg, 'kid' => $kid, 'typ' => 'JOSE'])($card);

echo $signed->serializeToJsonString(), "\n";
