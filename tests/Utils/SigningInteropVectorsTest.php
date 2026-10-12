<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Types\AgentCard;
use A2A\Utils\Signing;
use A2A\Utils\Signing\InvalidSignaturesError;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cross-SDK Agent Card signature vectors (the a2a-card-sign-v01 s0-control
 * group), copied verbatim from a2a-python (a2a-sdk 1.2.2, 494a8ec): one card
 * signed by a2a-python, @a2a-js/sdk, a2a-go and an independent reference
 * signer, plus one card edited after signing.
 */
final class SigningInteropVectorsTest extends TestCase
{
    public function testVectorCorpusIsComplete(): void
    {
        $corpus = self::corpus();
        $accept = array_filter($corpus['vectors'], static fn(array $v): bool => $v['disposition'] === 'MUST-ACCEPT');
        $reject = array_filter($corpus['vectors'], static fn(array $v): bool => $v['disposition'] === 'MUST-REJECT');

        self::assertCount(4, $accept);
        self::assertCount(1, $reject);
        $signers = array_column($accept, 'signer');
        foreach (['a2a-sdk 1.2.1', '@a2a-js/sdk 1.3.0', 'a2a-go main 534a60fc'] as $signer) {
            self::assertContains($signer, $signers);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function acceptVectors(): iterable
    {
        foreach (self::corpus()['vectors'] as $vector) {
            if ($vector['disposition'] === 'MUST-ACCEPT') {
                self::assertIsString($vector['id']);
                self::assertIsString($vector['signer']);
                yield $vector['id'] . ' (' . $vector['signer'] . ')' => [$vector];
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rejectVectors(): iterable
    {
        foreach (self::corpus()['vectors'] as $vector) {
            if ($vector['disposition'] === 'MUST-REJECT') {
                self::assertIsString($vector['id']);
                yield $vector['id'] => [$vector];
            }
        }
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('acceptVectors')]
    public function testCardSignedByAnySdkVerifies(array $vector): void
    {
        self::verifier()(self::card($vector));
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('acceptVectors')]
    public function testCanonicalBytesAreTheSharedForm(array $vector): void
    {
        self::assertSame($vector['canonical_utf8_hex'], bin2hex(Signing::canonicalizeAgentCard(self::card($vector))));
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('rejectVectors')]
    public function testCardEditedAfterSigningIsRejected(array $vector): void
    {
        $this->expectException(InvalidSignaturesError::class);
        self::verifier()(self::card($vector));
    }

    /**
     * @return array{vectors: list<array<string, mixed>>, test_key: array{kid: string, jwk: array<string, string>}}
     */
    private static function corpus(): array
    {
        /** @var array{vectors: list<array<string, mixed>>, test_key: array{kid: string, jwk: array<string, string>}} */
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/signing_interop_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $vector
     */
    private static function card(array $vector): AgentCard
    {
        $card = new AgentCard();
        $card->mergeFromJsonString(json_encode($vector['served_card'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $card;
    }

    private static function verifier(): \Closure
    {
        $testKey = self::corpus()['test_key'];
        $key = JWK::parseKey($testKey['jwk'], 'ES256');
        self::assertInstanceOf(Key::class, $key);

        return Signing::createSignatureVerifier(
            static function (?string $kid) use ($testKey, $key): Key {
                if ($kid !== $testKey['kid']) {
                    throw new \RuntimeException('kid not in key set: ' . $kid);
                }

                return $key;
            },
            ['ES256'],
        );
    }
}
