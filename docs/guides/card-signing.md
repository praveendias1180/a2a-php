# Signing the Agent Card

A signed Agent Card lets a client check that the card really comes from the agent's owner and wasn't changed on the way (spec §8.4). The signature is a JWS over the card's **canonical form**: RFC 8785 (JCS) JSON, without `signatures` and with empty values removed. It's stored in the card's `signatures` list.

Signing needs [`firebase/php-jwt`](https://github.com/firebase/php-jwt), the counterpart of the Python SDK's `signing` extra:

```bash
composer require firebase/php-jwt
```

## Serve a signed card

=== "Plain PHP"

    ```php
    use A2A\Server\Routes\Routes;
    use A2A\Utils\Signing;

    $signer = Signing::createAgentCardSigner(
        file_get_contents('/etc/my-agent/card-signing.pem'),   // EC private key
        ['alg' => 'ES256', 'kid' => 'card-2026', 'jku' => 'https://agent.example.com/.well-known/jwks.json'],
    );

    $router = Routes::router($handler, $card, cardSigner: $signer);
    // or: Routes::agentCard($card, signer: $signer)
    ```

=== "Laravel"

    ```dotenv
    A2A_SIGNING_KEY=file:///etc/my-agent/card-signing.pem
    A2A_SIGNING_ALG=ES256
    A2A_SIGNING_KID=card-2026
    A2A_SIGNING_JKU=https://agent.example.com/.well-known/jwks.json
    ```

    The public card and the extended card are both served signed.

=== "Python"

    ```python
    signer = signing.create_agent_card_signer(
        signing_key=private_key,
        protected_header={'alg': 'ES256', 'kid': 'card-2026', 'jku': None, 'typ': 'JOSE'},
    )
    signed_card = signer(copy.deepcopy(card))

    async def serve_signed(_: AgentCard) -> AgentCard:
        return signed_card

    routes = create_agent_card_routes(agent_card=card, card_modifier=serve_signed)
    ```

The signer works on a copy, so signatures never pile up on the configured card, and the signed card is computed once per process. ECDSA signatures differ each time they're made, so under PHP-FPM each worker serves a different (equally valid) signature; to serve identical bytes everywhere, sign once at deploy time and serve the already-signed card.

Supported algorithms: `ES256`, `ES384`, `RS256`/`RS384`/`RS512`, `PS256`, `EdDSA`, and `HS256`/`HS384`/`HS512` (shared secret; the key must be at least as long as the hash, as RFC 7518 requires).

## Verify a card as a client

```php
use A2A\Client\ClientFactory;
use A2A\Utils\Signing;
use Firebase\JWT\JWK;

$keys = JWK::parseKeySet(json_decode(file_get_contents('https://agent.example.com/.well-known/jwks.json'), true));

$verifier = Signing::createSignatureVerifier(
    static fn(?string $kid, ?string $jku) => $keys[$kid] ?? throw new RuntimeException("Unknown key $kid"),
    ['ES256'],                     // the algorithms you accept
);

$client = ClientFactory::createClient('https://agent.example.com', signatureVerifier: $verifier);
```

The verifier passes when **at least one** signature is valid, and throws `NoSignatureError` or `InvalidSignaturesError` (both `SignatureVerificationError`) otherwise. The algorithm list is an allow-list: a signature whose `alg` isn't on it, or a key whose algorithm doesn't match, is never accepted. Raw PEM or OpenSSH public-key material is also never used as an HMAC secret, so a card "signed" with `HS256` using your public key is rejected even if `HS256` is on the list. Still, keep the list to one algorithm family, or return a `Firebase\JWT\Key` (which fixes the algorithm) from the key provider. Only fetch keys from a `jku` you trust.

## Proven against the Python SDK

CI runs `tests/Interop/python/signing_interop.py`: a card signed by the official Python SDK verifies with the PHP SDK and the other way round, for ES256 and HS256; tampered cards are rejected by both; and both produce byte-identical canonical JSON (including non-ASCII text).
