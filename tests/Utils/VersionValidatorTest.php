<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Utils\Constants;
use A2A\Utils\Errors\VersionNotSupportedError;
use A2A\Utils\VersionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Ported from a2a-python tests/utils/test_version_validation.py.
 *
 * Python applies the check as a decorator on async methods and async
 * generators; both variants run the same comparison, which is tested here
 * directly against header arrays.
 */
final class VersionValidatorTest extends TestCase
{
    public function testValidateVersionSuccess(): void
    {
        $this->expectNotToPerformAssertions();

        (new VersionValidator(Constants::PROTOCOL_VERSION_1_0))->validate([Constants::VERSION_HEADER => '1.0']);
    }

    public function testHeaderNameIsCaseInsensitive(): void
    {
        $validator = new VersionValidator(Constants::PROTOCOL_VERSION_1_0);
        $validator->validate([strtolower(Constants::VERSION_HEADER) => '1.0']);
        $validator->validate(['A2A-VERSION' => ['1.0']]);

        self::assertSame('1.0', VersionValidator::actualVersion(['a2a-version' => ['1.0']]));
    }

    public function testValidateVersionMismatch(): void
    {
        $this->expectException(VersionNotSupportedError::class);
        $this->expectExceptionMessage("A2A version '0.3' is not supported by this handler. Expected version '1.0'.");

        (new VersionValidator(Constants::PROTOCOL_VERSION_1_0))->validate([Constants::VERSION_HEADER => '0.3']);
    }

    public function testMissingHeaderDefaultsTo03(): void
    {
        try {
            (new VersionValidator(Constants::PROTOCOL_VERSION_1_0))->validate([]);
            self::fail('expected VersionNotSupportedError');
        } catch (VersionNotSupportedError $e) {
            self::assertStringContainsString("A2A version '0.3' is not supported", $e->getMessage());
        }

        (new VersionValidator(Constants::PROTOCOL_VERSION_0_3))->validate([]);
        (new VersionValidator(Constants::PROTOCOL_VERSION_0_3))->validate([Constants::VERSION_HEADER => '  ']);
        self::assertSame('0.3', VersionValidator::actualVersion(['Other' => 'x']));
    }

    public function testNoRequestContextAllowsTheCall(): void
    {
        $this->expectNotToPerformAssertions();

        (new VersionValidator(Constants::PROTOCOL_VERSION_1_0))->validate(null);
    }

    public function testOnlyTheMajorVersionMatters(): void
    {
        $validator = new VersionValidator(Constants::PROTOCOL_VERSION_1_0);

        self::assertTrue($validator->isCompatible('1.0.1'));
        self::assertTrue($validator->isCompatible('1.0.0'));
        self::assertTrue($validator->isCompatible('1.1.0'));
        self::assertFalse($validator->isCompatible('2.0.0'));

        $this->expectException(VersionNotSupportedError::class);
        $validator->validate([Constants::VERSION_HEADER => '2.0.0']);
    }

    public function testHandlerExpectingAPatchVersion(): void
    {
        $validator = new VersionValidator('1.0.2');

        self::assertTrue($validator->isCompatible('1.0'));
        self::assertTrue($validator->isCompatible('1.0.5'));
    }

    public function testUnparseableVersions(): void
    {
        self::assertFalse((new VersionValidator('1.0'))->isCompatible('banana'));
        self::assertTrue((new VersionValidator('custom'))->isCompatible('custom'));
        self::assertFalse((new VersionValidator('custom'))->isCompatible('1.0'));
        self::assertTrue((new VersionValidator('1.0'))->isCompatible('1.0rc1'));
    }

    public function testMismatchIsLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (is_string($level) ? $level : '?') . ': ' . $message;
            }
        };

        try {
            (new VersionValidator('1.0', $logger))->validate([Constants::VERSION_HEADER => '0.3']);
        } catch (VersionNotSupportedError) {
        }

        self::assertSame(["warning: Version mismatch: actual='{actual}', expected='{expected}'"], $logger->messages);
    }
}
