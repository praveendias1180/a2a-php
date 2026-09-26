<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentCardSignature;
use A2A\Types\AgentInterface;
use A2A\Types\AgentSkill;
use A2A\Utils\Signing;
use A2A\Utils\Signing\InvalidSignaturesError;
use A2A\Utils\Signing\NoSignatureError;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ports tests/utils/test_signing.py.
 */
final class SigningTest extends TestCase
{
    private const ALGORITHMS = ['HS256', 'HS384', 'ES256', 'RS256', 'EdDSA'];

    // HMAC keys must be as long as the hash (RFC 7518 §3.2); Python's test
    // uses 'key12345', which PyJWT accepts and firebase/php-jwt does not.
    private const HMAC_KEY = 'key12345-key12345-key12345-key12345-key12345-key1';
    private const WRONG_HMAC_KEY = 'wrongkey-wrongkey-wrongkey-wrongkey-wrongkey-wron';

    public function testSignerAndVerifierSymmetric(): void
    {
        $signed = Signing::createAgentCardSigner(self::HMAC_KEY, ['alg' => 'HS384', 'kid' => 'key1', 'jku' => null, 'typ' => 'JOSE'])(self::card());

        self::assertCount(1, $signed->getSignatures());
        $signature = $signed->getSignatures()[0];
        self::assertNotSame('', $signature->getProtected());
        self::assertNotSame('', $signature->getSignature());

        Signing::createSignatureVerifier(self::provider(self::HMAC_KEY), self::ALGORITHMS)($signed);
        $this->addToAssertionCount(1);

        $this->expectException(InvalidSignaturesError::class);
        Signing::createSignatureVerifier(self::provider(self::WRONG_HMAC_KEY), self::ALGORITHMS)($signed);
    }

    public function testSignerAndVerifierSymmetricMultipleSignatures(): void
    {
        $card = self::card();
        $card->setSignatures([new AgentCardSignature([
            'protected' => JWT::urlsafeB64Encode('{"alg": "HS256", "kid": "old_key"}'),
            'signature' => 'old_signature',
        ])]);

        $signed = Signing::createAgentCardSigner(self::HMAC_KEY, ['alg' => 'HS384', 'kid' => 'key1', 'jku' => null, 'typ' => 'JOSE'])($card);

        self::assertCount(2, $signed->getSignatures());
        Signing::createSignatureVerifier(self::provider(self::HMAC_KEY), self::ALGORITHMS)($signed);
        $this->addToAssertionCount(1);

        $this->expectException(InvalidSignaturesError::class);
        Signing::createSignatureVerifier(self::provider(self::WRONG_HMAC_KEY), self::ALGORITHMS)($signed);
    }

    public function testSignerAndVerifierAsymmetricEs256(): void
    {
        [$private, $public] = self::ecKeyPair();
        [, $otherPublic] = self::ecKeyPair();

        $signed = Signing::createAgentCardSigner($private, ['alg' => 'ES256', 'kid' => 'key2', 'jku' => null, 'typ' => 'JOSE'])(self::card());

        Signing::createSignatureVerifier(self::provider($public), self::ALGORITHMS)($signed);
        $this->addToAssertionCount(1);

        $this->expectException(InvalidSignaturesError::class);
        Signing::createSignatureVerifier(self::provider($otherPublic), self::ALGORITHMS)($signed);
    }

    public function testHs256ForgedWithThePublicKeyIsRejected(): void
    {
        // Algorithm confusion: an attacker who knows the public key signs with
        // HS256 using the public PEM as the HMAC secret. With a mixed allow-list
        // and raw key material, the verifier must still refuse it (PyJWT does).
        [, $public] = self::ecKeyPair();
        $forged = Signing::createAgentCardSigner($public, ['alg' => 'HS256', 'kid' => 'key2', 'jku' => null, 'typ' => 'JOSE'])(self::card());

        $this->expectException(InvalidSignaturesError::class);
        Signing::createSignatureVerifier(self::provider($public), ['ES256', 'HS256'])($forged);
    }

    public function testSignerAndVerifierRs256WithAFirebaseKey(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $public = openssl_pkey_get_details($key)['key'] ?? '';
        self::assertIsString($public);

        $signed = Signing::createAgentCardSigner($key, ['alg' => 'RS256', 'kid' => 'rsa'])(self::card());

        Signing::createSignatureVerifier(static fn(?string $kid, ?string $jku): Key => new Key($public, 'RS256'), self::ALGORITHMS)($signed);
        $this->addToAssertionCount(1);
    }

    public function testEdDsa(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $signed = Signing::createAgentCardSigner(base64_encode(sodium_crypto_sign_secretkey($pair)), ['alg' => 'EdDSA', 'kid' => 'ed'])(self::card());

        Signing::createSignatureVerifier(self::provider(base64_encode(sodium_crypto_sign_publickey($pair))), self::ALGORITHMS)($signed);
        $this->addToAssertionCount(1);
    }

    public function testAnAlgorithmOutsideTheAllowListIsRejected(): void
    {
        $signed = Signing::createAgentCardSigner(self::HMAC_KEY, ['alg' => 'HS384', 'kid' => 'key1'])(self::card());

        $this->expectException(InvalidSignaturesError::class);
        Signing::createSignatureVerifier(self::provider(self::HMAC_KEY), ['ES256'])($signed);
    }

    public function testAKeyWithAnotherAlgorithmIsRejected(): void
    {
        // Algorithm confusion: an HS256 signature checked with a key the
        // provider declared as ES256 must fail.
        $signed = Signing::createAgentCardSigner(self::HMAC_KEY, ['alg' => 'HS256', 'kid' => 'key1'])(self::card());

        $this->expectException(InvalidSignaturesError::class);
        Signing::createSignatureVerifier(static fn(): Key => new Key(self::HMAC_KEY, 'HS512'), self::ALGORITHMS)($signed);
    }

    public function testATamperedCardFailsVerification(): void
    {
        $signed = Signing::createAgentCardSigner(self::HMAC_KEY, ['alg' => 'HS256', 'kid' => 'key1'])(self::card());
        $signed->setDescription('something else');

        $this->expectException(InvalidSignaturesError::class);
        Signing::createSignatureVerifier(self::provider(self::HMAC_KEY), self::ALGORITHMS)($signed);
    }

    public function testNoSignatures(): void
    {
        $this->expectException(NoSignatureError::class);
        Signing::createSignatureVerifier(self::provider(self::HMAC_KEY), self::ALGORITHMS)(self::card());
    }

    public function testTheKeyProviderGetsKidAndJku(): void
    {
        $signed = Signing::createAgentCardSigner(self::HMAC_KEY, ['alg' => 'HS256', 'kid' => 'the-kid', 'jku' => 'https://keys.example/jwks.json'])(self::card());
        $seen = [];

        Signing::createSignatureVerifier(static function (?string $kid, ?string $jku) use (&$seen): string {
            $seen = [$kid, $jku];

            return self::HMAC_KEY;
        }, self::ALGORITHMS)($signed);

        self::assertSame(['the-kid', 'https://keys.example/jwks.json'], $seen);
    }

    public function testUnprotectedHeaderIsStored(): void
    {
        $signed = Signing::createAgentCardSigner(self::HMAC_KEY, ['alg' => 'HS256', 'kid' => 'k'], ['note' => 'hello'])(self::card());

        $header = $signed->getSignatures()[0]->getHeader();
        self::assertNotNull($header);
        self::assertSame(['note' => 'hello'], \A2A\Utils\ProtoUtils::fromStruct($header));
    }

    public function testCanonicalizeAgentCard(): void
    {
        // Same expected bytes as Python's test_canonicalize_agent_card.
        $expected = '{"capabilities":{"pushNotifications":true},'
            . '"defaultInputModes":["text/plain"],"defaultOutputModes":["text/plain"],'
            . '"description":"A test agent","name":"Test Agent",'
            . '"skills":[{"description":"A test skill","id":"skill1","name":"Test Skill","tags":["test"]}],'
            . '"supportedInterfaces":[{"protocolBinding":"HTTP+JSON","url":"http://localhost"}],'
            . '"version":"1.0.0"}';

        self::assertSame($expected, Signing::canonicalizeAgentCard(self::card()));
    }

    public function testCanonicalizeAgentCardPreservesAFalseCapability(): void
    {
        $card = self::card();
        $card->getCapabilities()?->setStreaming(false);

        self::assertStringContainsString('"streaming":false', Signing::canonicalizeAgentCard($card));
    }

    public function testCanonicalFormExcludesSignatures(): void
    {
        $card = self::card();
        $card->setSignatures([new AgentCardSignature(['protected' => 'abc', 'signature' => 'def'])]);

        self::assertStringNotContainsString('signatures', Signing::canonicalizeAgentCard($card));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyValues(): iterable
    {
        yield 'empty-string' => [self::obj(['a' => ''])];
        yield 'empty-list' => [self::obj(['a' => []])];
        yield 'empty-dict' => [self::obj(['a' => new \stdClass()])];
        yield 'nested-empty' => [self::obj(['a' => self::obj(['b' => []])])];
        yield 'all-empties' => [self::obj(['a' => '', 'b' => [], 'c' => new \stdClass()])];
        yield 'deeply-nested' => [self::obj(['a' => self::obj(['b' => self::obj(['c' => ''])])])];
    }

    #[DataProvider('emptyValues')]
    public function testCleanEmptyRemovesEmpties(mixed $value): void
    {
        self::assertNull(Signing::cleanEmpty($value));
    }

    public function testCleanEmptyTopLevelListBecomesNull(): void
    {
        self::assertNull(Signing::cleanEmpty(['', new \stdClass(), []]));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function falsyValues(): iterable
    {
        yield 'int-zero' => [self::obj(['retries' => 0]), self::obj(['retries' => 0])];
        yield 'bool-false' => [self::obj(['enabled' => false]), self::obj(['enabled' => false])];
        yield 'float-zero' => [self::obj(['score' => 0.0]), self::obj(['score' => 0.0])];
        yield 'zero-in-list' => [[0, 1, 2], [0, 1, 2]];
        yield 'false-in-list' => [[false, true], [false, true]];
        yield 'nested-zero' => [
            self::obj(['config' => self::obj(['max_retries' => 0, 'name' => 'agent'])]),
            self::obj(['config' => self::obj(['max_retries' => 0, 'name' => 'agent'])]),
        ];
        yield 'falsy-with-empties' => [self::obj(['count' => 0, 'label' => '', 'items' => []]), self::obj(['count' => 0])];
        yield 'mixed-types' => [self::obj(['a' => 0, 'b' => 'hello', 'c' => false, 'd' => '']), self::obj(['a' => 0, 'b' => 'hello', 'c' => false])];
        yield 'realistic-mixed' => [self::obj(['name' => 'agent', 'retries' => 0, 'tags' => [], 'desc' => '']), self::obj(['name' => 'agent', 'retries' => 0])];
    }

    #[DataProvider('falsyValues')]
    public function testCleanEmptyKeepsFalsyValues(mixed $input, mixed $expected): void
    {
        self::assertEquals($expected, Signing::cleanEmpty($input));
    }

    public function testCleanEmptyDoesNotMutateItsInput(): void
    {
        $original = self::obj(['a' => '', 'b' => 1, 'c' => self::obj(['d' => ''])]);
        $copy = unserialize(serialize($original));

        Signing::cleanEmpty($original);

        self::assertEquals($copy, $original);
    }

    public function testCleanEmptyRejectsExcessiveNesting(): void
    {
        $value = 'x';
        for ($i = 0; $i < 200; ++$i) {
            $value = [$value];
        }

        $this->expectException(\A2A\Utils\CanonicalizationError::class);
        Signing::cleanEmpty($value);
    }

    private static function card(): AgentCard
    {
        return new AgentCard([
            'name' => 'Test Agent',
            'description' => 'A test agent',
            'supported_interfaces' => [new AgentInterface(['url' => 'http://localhost', 'protocol_binding' => 'HTTP+JSON'])],
            'version' => '1.0.0',
            'capabilities' => new AgentCapabilities(['push_notifications' => true]),
            'default_input_modes' => ['text/plain'],
            'default_output_modes' => ['text/plain'],
            'icon_url' => '',
            'skills' => [new AgentSkill(['id' => 'skill1', 'name' => 'Test Skill', 'description' => 'A test skill', 'tags' => ['test']])],
        ]);
    }

    /**
     * @return \Closure(?string, ?string): string
     */
    private static function provider(string $key): \Closure
    {
        return static fn(?string $kid, ?string $jku): string => $key;
    }

    /**
     * @return array{\OpenSSLAsymmetricKey, string}
     */
    private static function ecKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $public = openssl_pkey_get_details($key)['key'] ?? null;
        self::assertIsString($public);

        return [$key, $public];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function obj(array $fields): \stdClass
    {
        return (object) $fields;
    }
}
