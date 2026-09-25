<?php

declare(strict_types=1);

namespace A2A\Utils;

use A2A\Utils\Errors\VersionNotSupportedError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Checks the A2A-Version request header against the version a handler serves.
 *
 * Mirrors a2a-python: validate_version() in src/a2a/utils/version_validator.py.
 * Python ships it as a decorator that finds the ServerCallContext among the
 * call's arguments; PHP has no decorators, so request handlers call
 * validate() with the request headers directly.
 *
 * Rules (same as Python):
 * - A missing or empty header means "0.3".
 * - Versions are compatible when the MAJOR parts match ("1.1.0" is accepted by
 *   a "1.0" handler, "2.0" is not). An unparseable version is rejected unless
 *   it is an exact string match.
 * - No headers at all (null) means there is no request to check; the call is
 *   allowed, as in Python when no context is found.
 */
final class VersionValidator
{
    private readonly ?int $expectedMajor;

    public function __construct(
        public readonly string $expectedVersion,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->expectedMajor = self::majorOf($expectedVersion);
    }

    /**
     * @param array<string, string|list<string>>|null $headers PSR-7 style headers (any case), or null when there is no request
     *
     * @throws VersionNotSupportedError
     */
    public function validate(?array $headers): void
    {
        $actual = $headers === null ? $this->expectedVersion : self::actualVersion($headers);
        if ($this->isCompatible($actual)) {
            return;
        }

        $this->logger->warning("Version mismatch: actual='{actual}', expected='{expected}'", [
            'actual' => $actual,
            'expected' => $this->expectedVersion,
        ]);

        throw new VersionNotSupportedError(sprintf(
            "A2A version '%s' is not supported by this handler. Expected version '%s'.",
            $actual,
            $this->expectedVersion,
        ));
    }

    public function isCompatible(string $actual): bool
    {
        if ($actual === $this->expectedVersion) {
            return true;
        }
        if ($this->expectedMajor === null) {
            return false;
        }
        $actualMajor = self::majorOf($actual);

        return $actualMajor !== null && $actualMajor === $this->expectedMajor;
    }

    /**
     * The requested version from the headers. Header names are matched
     * case-insensitively (Python checks only the exact and lowercase forms).
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function actualVersion(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, Constants::VERSION_HEADER) !== 0) {
                continue;
            }
            $value = is_array($value) ? implode(',', $value) : $value;
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return Constants::PROTOCOL_VERSION_0_3;
    }

    /**
     * Major version of a PEP 440 / SemVer style string ("1", "1.0", "v1.0.2",
     * "1.0rc1"), or null if it does not look like a version.
     */
    private static function majorOf(string $version): ?int
    {
        if (preg_match('/^\s*v?(\d+)(?:\.\d+)*(?:[-_.]?(?:a|b|c|rc|alpha|beta|pre|preview|post|rev|r|dev)[-_.]?\d*)*(?:\+[a-z0-9.]+)?\s*$/i', $version, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
