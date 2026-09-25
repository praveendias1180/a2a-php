<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Utils\PushUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the SSRF cases in a2a-python
 * tests/server/tasks/test_push_notification_sender.py
 * (TestBasePushNotificationSenderSsrf), which exercise
 * validate_push_notification_url() with a patched resolver. The sender itself
 * is phase 5; the validator is tested directly here.
 */
final class PushUrlValidatorTest extends TestCase
{
    public function testMetadataEndpointBlocked(): void
    {
        self::assertFalse($this->validator(['169.254.169.254'])->validate('http://metadata.google.internal/latest'));
    }

    public function testLoopbackBlocked(): void
    {
        self::assertFalse($this->validator(['127.0.0.1'])->validate('http://localhost:8080/admin'));
    }

    public function testPrivateRangeBlocked(): void
    {
        self::assertFalse($this->validator(['10.0.0.5'])->validate('http://internal-service/endpoint'));
    }

    public function testNonHttpSchemeBlocked(): void
    {
        self::assertFalse($this->validator(['93.184.216.34'])->validate('ftp://example.com/file'));
    }

    public function testInvalidPortBlocked(): void
    {
        self::assertFalse($this->validator(['93.184.216.34'])->validate('http://example.com:99999/hook'));
    }

    public function testUnresolvableHostBlockedFailClosed(): void
    {
        $throwing = new PushUrlValidator(static function (string $host): array {
            throw new \RuntimeException('no DNS');
        });

        self::assertFalse($throwing->validate('http://does-not-resolve.invalid/'));
        self::assertFalse($this->validator([])->validate('http://does-not-resolve.invalid/'));
    }

    public function testPublicHostAllowed(): void
    {
        self::assertTrue($this->validator(['93.184.216.34'])->validate('http://notify.me/here'));
        self::assertTrue(($this->validator(['93.184.216.34']))('https://notify.me/here'));
    }

    public function testEveryResolvedAddressMustBePublic(): void
    {
        // A DNS answer mixing a public and a private address is rejected.
        self::assertFalse($this->validator(['93.184.216.34', '10.1.2.3'])->validate('https://mixed.example/hook'));
    }

    public function testMissingHostOrUnparseableUrlBlocked(): void
    {
        self::assertFalse($this->validator(['93.184.216.34'])->validate('http:///path-only'));
        self::assertFalse($this->validator(['93.184.216.34'])->validate('not a url'));
    }

    public function testIpLiteralsSkipTheResolver(): void
    {
        $never = new PushUrlValidator(static function (string $host): array {
            throw new \LogicException('resolver must not be called for an IP literal');
        });

        self::assertFalse($never->validate('http://127.0.0.1/hook'));
        self::assertFalse($never->validate('http://[::1]:8080/hook'));
        self::assertTrue($never->validate('https://93.184.216.34/hook'));
        self::assertTrue($never->validate('https://[2606:2800:220:1:248:1893:25c8:1946]/hook'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedAddresses(): iterable
    {
        foreach ([
            '0.0.0.0', '0.1.2.3', '10.0.0.1', '100.64.0.1', '127.0.0.1', '127.255.255.254',
            '169.254.169.254', '172.16.0.1', '172.31.255.255', '192.0.0.8', '192.0.2.1', '192.168.1.1',
            '198.18.0.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.250', '240.0.0.1',
            '255.255.255.255',
            '::', '::1', 'fe80::1', 'fe80::1%eth0', 'fc00::1', 'fd12:3456::1', 'fec0::1', 'ff02::1',
            '2001:db8::1', '2001::1', '2001:10::1', '100::1',
            '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '64:ff9b::a00:1',
            '2002:c0a8:0101::1', '::127.0.0.1',
            'not-an-ip', '',
        ] as $address) {
            yield $address === '' ? '(empty)' : $address => [$address];
        }
    }

    #[DataProvider('blockedAddresses')]
    public function testBlockedAddresses(string $address): void
    {
        self::assertTrue(PushUrlValidator::isBlocked($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1',
            '2606:4700:4700::1111', '2a00:1450:4001::200e', '::ffff:8.8.8.8', '64:ff9b::808:808', '2002:0808:0808::1'] as $address) {
            yield $address => [$address];
        }
    }

    #[DataProvider('publicAddresses')]
    public function testPublicAddresses(string $address): void
    {
        self::assertFalse(PushUrlValidator::isBlocked($address));
    }

    /**
     * @param list<string> $addresses
     */
    private function validator(array $addresses): PushUrlValidator
    {
        return new PushUrlValidator(static fn(string $host): array => $addresses);
    }
}
