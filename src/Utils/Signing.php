<?php

declare(strict_types=1);

namespace A2A\Utils;

use A2A\Types\AgentCard;
use A2A\Types\AgentCardSignature;
use A2A\Utils\Signing\InvalidSignaturesError;
use A2A\Utils\Signing\NoSignatureError;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Signs and verifies Agent Cards (spec §8.4): a JWS over the card's
 * RFC 8785 (JCS) canonical form, with `signatures` excluded and empty
 * values dropped, stored as AgentCardSignature {protected, signature}
 * (a detached-payload JWS).
 *
 * Needs firebase/php-jwt (`composer require firebase/php-jwt`), the PHP
 * counterpart of Python's PyJWT `signing` extra. It was chosen because it
 * has no dependencies, covers HS*, RS*, PS256, ES256/ES384 and EdDSA, and
 * its sign() takes raw bytes: the JWS payload must be the exact canonical
 * bytes, never a re-encoded array.
 *
 * Differences from Python:
 * - HMAC keys must be at least as long as the hash (32 bytes for HS256,
 *   48 for HS384, 64 for HS512), as RFC 7518 §3.2 requires; PyJWT accepts
 *   shorter keys.
 * - Keys are PHP key material: a string (HMAC secret, PEM or base64 EdDSA
 *   key), an OpenSSLAsymmetricKey, or a Firebase\JWT\Key. Use
 *   Firebase\JWT\JWK::parseKeySet() for a JWKS.
 *
 * Mirrors a2a-python: src/a2a/utils/signing.py
 */
final class Signing
{
    private function __construct() {}

    /**
     * A closure that appends a signature to an AgentCard and returns it.
     *
     * @param string|\OpenSSLAsymmetricKey|\OpenSSLCertificate $signingKey      the private key (or HMAC secret)
     * @param array{alg?: string|null, kid: string, jku?: string|null, typ?: string|null} $protectedHeader
     * @param array<string, mixed>|null                           $header          unprotected header parameters
     *
     * @return \Closure(AgentCard): AgentCard
     */
    public static function createAgentCardSigner(
        #[\SensitiveParameter]
        string|\OpenSSLAsymmetricKey|\OpenSSLCertificate $signingKey,
        array $protectedHeader,
        ?array $header = null,
    ): \Closure {
        self::requireLibrary();

        return static function (AgentCard $card) use ($signingKey, $protectedHeader, $header): AgentCard {
            $algorithm = $protectedHeader['alg'] ?? 'HS256';
            $protected = ['alg' => $algorithm];
            foreach ($protectedHeader as $name => $value) {
                if ($value !== null && $name !== 'alg') {
                    $protected[$name] = $value;
                }
            }
            $encodedProtected = JWT::urlsafeB64Encode(JsonUtils::dumps($protected));
            $encodedPayload = JWT::urlsafeB64Encode(self::canonicalizeAgentCard($card));
            $signature = JWT::sign($encodedProtected . '.' . $encodedPayload, $signingKey, $algorithm);

            $cardSignature = new AgentCardSignature([
                'protected' => $encodedProtected,
                'signature' => JWT::urlsafeB64Encode($signature),
            ]);
            if ($header !== null && $header !== []) {
                $cardSignature->setHeader(ProtoUtils::toStruct($header));
            }
            $signatures = iterator_to_array($card->getSignatures(), false);
            $signatures[] = $cardSignature;
            $card->setSignatures($signatures);

            return $card;
        };
    }

    /**
     * A closure that throws unless at least one of the card's signatures is
     * valid.
     *
     * $keyProvider gets the protected header's `kid` and `jku` and returns
     * the verification key: key material (then the signature's `alg` is
     * used, if it is in $algorithms) or a Firebase\JWT\Key (whose algorithm
     * must be in $algorithms). $algorithms is the allow-list that prevents
     * algorithm-confusion attacks.
     *
     * @param \Closure(?string, ?string): (Key|string|\OpenSSLAsymmetricKey|\OpenSSLCertificate) $keyProvider
     * @param list<string>                                                                       $algorithms
     *
     * @return \Closure(AgentCard): void
     *
     * @throws NoSignatureError       the card has no signatures (from the closure)
     * @throws InvalidSignaturesError no signature is valid (from the closure)
     */
    public static function createSignatureVerifier(\Closure $keyProvider, array $algorithms): \Closure
    {
        self::requireLibrary();

        return static function (AgentCard $card) use ($keyProvider, $algorithms): void {
            if (count($card->getSignatures()) === 0) {
                throw new NoSignatureError('AgentCard has no signatures to verify.');
            }
            // The canonical form does not depend on the signature being
            // checked, so it is computed once. A card with no canonical form
            // has no verifiable signature.
            try {
                $encodedPayload = JWT::urlsafeB64Encode(self::canonicalizeAgentCard($card));
            } catch (CanonicalizationError $e) {
                throw new InvalidSignaturesError('AgentCard cannot be canonicalized for verification', 0, $e);
            }

            foreach ($card->getSignatures() as $signature) {
                try {
                    $header = json_decode(JWT::urlsafeB64Decode($signature->getProtected()), true, 16, JSON_THROW_ON_ERROR);
                    if (!is_array($header) || !is_string($header['alg'] ?? null) || !in_array($header['alg'], $algorithms, true)) {
                        continue;
                    }
                    $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
                    $jku = is_string($header['jku'] ?? null) ? $header['jku'] : null;
                    $key = $keyProvider($kid, $jku);
                    if (!$key instanceof Key) {
                        // Algorithm confusion: with raw key material the header picks
                        // the algorithm, so a public key must never become an HMAC
                        // secret (an attacker could sign with HS256 using the public
                        // PEM). PyJWT refuses this too.
                        if (str_starts_with($header['alg'], 'HS') && !self::isSymmetricSecret($key)) {
                            continue;
                        }
                        $key = new Key($key, $header['alg']);
                    } elseif (!in_array($key->getAlgorithm(), $algorithms, true)) {
                        continue;
                    }
                    JWT::decode($signature->getProtected() . '.' . $encodedPayload . '.' . $signature->getSignature(), $key);

                    return;
                } catch (\Throwable) {
                    // Try the next signature, as Python does on PyJWTError.
                    continue;
                }
            }

            throw new InvalidSignaturesError('No valid signature found');
        };
    }

    /**
     * True when $key can safely be an HMAC secret: a plain string that is not
     * PEM, OpenSSH or JWK-looking asymmetric key material, and not an OpenSSL
     * key object. Mirrors PyJWT's HMACAlgorithm.prepare_key() checks.
     */
    private static function isSymmetricSecret(mixed $key): bool
    {
        if (!is_string($key)) {
            return false;
        }
        $trimmed = ltrim($key);

        return !str_starts_with($trimmed, '-----BEGIN')
            && !preg_match('/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-nistp\d+) /', $trimmed);
    }

    /**
     * The card's canonical form (RFC 8785): ProtoJSON without `signatures`
     * and with empty strings, lists and objects removed.
     *
     * @throws CanonicalizationError
     */
    public static function canonicalizeAgentCard(AgentCard $card): string
    {
        $data = json_decode($card->serializeToJsonString(), false, Jcs::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);
        if ($data instanceof \stdClass) {
            unset($data->signatures);
        }

        return Jcs::canonicalize(self::cleanEmpty($data) ?? new \stdClass());
    }

    /**
     * Recursively removes empty strings, lists and objects (0 and false
     * stay). Returns null when nothing is left. Does not change its input.
     *
     * @throws CanonicalizationError when nesting is deeper than Jcs::MAX_DEPTH
     */
    public static function cleanEmpty(mixed $value, int $depth = 0): mixed
    {
        if ($depth > Jcs::MAX_DEPTH) {
            throw new CanonicalizationError('nesting exceeds the maximum depth of ' . Jcs::MAX_DEPTH);
        }
        if ($value instanceof \stdClass) {
            $cleaned = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $item = self::cleanEmpty($item, $depth + 1);
                if ($item !== null) {
                    $cleaned->{$key} = $item;
                }
            }

            return get_object_vars($cleaned) === [] ? null : $cleaned;
        }
        if (is_array($value)) {
            $cleaned = [];
            $isList = array_is_list($value);
            foreach ($value as $key => $item) {
                $item = self::cleanEmpty($item, $depth + 1);
                if ($item !== null) {
                    $cleaned[$key] = $item;
                }
            }
            if ($cleaned === []) {
                return null;
            }

            return $isList ? array_values($cleaned) : $cleaned;
        }
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private static function requireLibrary(): void
    {
        if (!class_exists(JWT::class)) {
            throw new \LogicException('A2A Agent Card signing requires firebase/php-jwt. Install it with: composer require firebase/php-jwt');
        }
    }
}
