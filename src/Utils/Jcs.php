<?php

declare(strict_types=1);

namespace A2A\Utils;

/**
 * RFC 8785 JSON Canonicalization Scheme (JCS).
 *
 * The Agent Card signature covers a canonical serialization of the card, so
 * the bytes produced here are the bytes that get signed. json_encode() cannot
 * produce them: it orders keys by insertion, and formats numbers its own way
 * rather than with ECMAScript's Number::toString.
 *
 * Mirrors a2a-python: src/a2a/utils/_jcs.py.
 *
 * PHP value mapping:
 * - null, bool, int, float, string as usual; strings must be valid UTF-8.
 * - A list array is a JSON array; any other array, or a stdClass, is an object.
 *   An empty array is `[]`; use `new \stdClass()` for `{}`.
 * - PHP turns numeric-string array keys into ints; they are treated as the
 *   strings they came from (Python can reject non-string keys, PHP cannot tell).
 *
 * Serialization is depth-limited: nesting is attacker-controlled through
 * AgentExtension.params (a google.protobuf.Struct), and unbounded recursion
 * would crash whichever process verifies the card.
 */
final class Jcs
{
    /** Maximum object/array nesting accepted by canonicalize(). */
    public const MAX_DEPTH = 128;

    // JSON numbers are IEEE 754 doubles (RFC 8785 3.2.2.3), so integers
    // outside this range have no interoperable representation.
    private const SAFE_INT_MAX = 9007199254740991;
    private const SAFE_INT_MIN = -9007199254740991;

    private function __construct() {}

    /**
     * The canonical form of $value, as a UTF-8 string.
     *
     * @throws CanonicalizationError when a value has no canonical form, a
     *                               string is not valid UTF-8, or nesting is
     *                               deeper than MAX_DEPTH
     */
    public static function canonicalize(mixed $value): string
    {
        $out = '';
        self::write($value, $out, 0);

        return $out;
    }

    private static function write(mixed $value, string &$out, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CanonicalizationError('nesting exceeds the maximum depth of ' . self::MAX_DEPTH);
        }

        if (is_array($value) && array_is_list($value)) {
            $out .= '[';
            foreach ($value as $index => $element) {
                if ($index > 0) {
                    $out .= ',';
                }
                self::write($element, $out, $depth + 1);
            }
            $out .= ']';

            return;
        }

        if (is_array($value) || $value instanceof \stdClass) {
            $out .= '{';
            $first = true;
            foreach (self::sortedItems((array) $value) as [$key, $item]) {
                if (!$first) {
                    $out .= ',';
                }
                $first = false;
                $out .= self::quote($key) . ':';
                self::write($item, $out, $depth + 1);
            }
            $out .= '}';

            return;
        }

        $out .= self::formatScalar($value);
    }

    private static function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            if ($value < self::SAFE_INT_MIN || $value > self::SAFE_INT_MAX) {
                throw new CanonicalizationError($value . ' is outside the range of integers a JSON number can represent exactly');
            }

            return (string) $value;
        }
        if (is_float($value)) {
            return self::formatNumber($value);
        }
        if (is_string($value)) {
            return self::quote($value);
        }

        throw new CanonicalizationError(get_debug_type($value) . ' has no JSON representation');
    }

    /**
     * Object members sorted by UTF-16 code unit (RFC 8785 3.2.3). Comparing
     * big-endian UTF-16 encodings byte by byte equals comparing code units,
     * which code point order does not for keys above the BMP.
     *
     * @param array<array-key, mixed> $object
     *
     * @return list<array{string, mixed}>
     */
    private static function sortedItems(array $object): array
    {
        $items = [];
        foreach ($object as $key => $item) {
            $key = (string) $key;
            $items[] = [self::utf16be($key), $key, $item];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

        return array_map(static fn(array $row): array => [$row[1], $row[2]], $items);
    }

    /**
     * RFC 8785 3.2.2.2: escape quote, backslash and C0 controls; emit
     * everything else as literal UTF-8.
     */
    private static function quote(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new CanonicalizationError('value contains text that is not valid Unicode');
        }

        $escaped = preg_replace_callback('/[\x00-\x1f\\\\"]/', static function (array $m): string {
            return match ($m[0]) {
                '\\' => '\\\\',
                '"' => '\\"',
                "\x08" => '\\b',
                "\x0c" => '\\f',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\u%04x', ord($m[0])),
            };
        }, $value);

        return '"' . $escaped . '"';
    }

    /**
     * ECMA-262 Number::toString as amended by RFC 8785 3.2.2.3.
     */
    private static function formatNumber(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new CanonicalizationError(var_export($value, true) . ' is not a JSON number');
        }
        if ($value == 0.0) {
            return '0'; // also covers -0.0
        }
        if ($value < 0) {
            return '-' . self::formatNumber(-$value);
        }

        [$digits, $n] = self::shortestDigits($value);
        $k = strlen($digits);

        if ($k <= $n && $n <= 21) {
            return $digits . str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return substr($digits, 0, $n) . '.' . substr($digits, $n);
        }
        if (-6 < $n && $n <= 0) {
            return '0.' . str_repeat('0', -$n) . $digits;
        }

        $exponent = $n - 1;
        $mantissa = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);

        return $mantissa . 'e' . ($exponent >= 0 ? '+' : '-') . abs($exponent);
    }

    /**
     * The shortest decimal digits that round-trip to $value, and n such that
     * $value = 0.digits × 10^n (ECMA-262's k and n).
     *
     * @return array{string, int}
     */
    private static function shortestDigits(float $value): array
    {
        // serialize_precision=-1 makes var_export() emit the shortest
        // round-tripping representation (zend_gcvt mode 0), e.g. "1.0E+25".
        $previous = ini_set('serialize_precision', '-1');
        try {
            $repr = strtolower(var_export($value, true));
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }

        $exponent = 0;
        if (($e = strpos($repr, 'e')) !== false) {
            $exponent = (int) substr($repr, $e + 1);
            $repr = substr($repr, 0, $e);
        }
        [$intPart, $fracPart] = array_pad(explode('.', $repr, 2), 2, '');

        $digits = $intPart . $fracPart;
        $n = strlen($intPart) + $exponent;
        $stripped = ltrim($digits, '0');
        $n -= strlen($digits) - strlen($stripped);
        $digits = rtrim($stripped, '0');

        return [$digits, $n];
    }

    /**
     * UTF-8 → UTF-16BE without mbstring/iconv (neither is a dependency).
     * The input is already known to be valid UTF-8.
     */
    private static function utf16be(string $utf8): string
    {
        if (preg_match('//u', $utf8) !== 1) {
            throw new CanonicalizationError('object key contains text that is not valid Unicode');
        }
        $out = '';
        foreach (preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $bytes = array_map(ord(...), str_split($char));
            $codePoint = match (count($bytes)) {
                1 => $bytes[0],
                2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
                3 => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
                default => (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F),
            };
            if ($codePoint >= 0x10000) {
                $codePoint -= 0x10000;
                $out .= pack('n', 0xD800 | ($codePoint >> 10)) . pack('n', 0xDC00 | ($codePoint & 0x3FF));
            } else {
                $out .= pack('n', $codePoint);
            }
        }

        return $out;
    }
}
