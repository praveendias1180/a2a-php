<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Utils\CanonicalizationError;
use A2A\Utils\Jcs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RFC 8785 (JCS) conformance, ported from a2a-python tests/utils/test_jcs.py.
 *
 * Fixtures/jcs_vectors.json is copied verbatim from a2a-python, which lifted it
 * from the language-neutral a2a-jcs-v01 corpus (a2aproject/a2a-tck#228). Its
 * expected bytes come from two independent RFC 8785 implementations.
 *
 * Python cross-checks numbers against the `rfc8785` package; here the oracle
 * is Node.js (String(x) is ECMAScript Number::toString), used when `node` is on
 * the PATH. Tests that go through Agent Card signing (signer/verifier, the
 * producer-side signature exclusion) wait for the signing port in phase 5.
 */
final class JcsTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $corpus = null;

    public function testVectorCorpusIsComplete(): void
    {
        $corpus = self::corpus();
        /** @var \stdClass $counts */
        $counts = $corpus['counts'];

        self::assertCount(47, self::vectors('MUST-ACCEPT'));
        self::assertCount(10, self::vectors('MUST-REJECT'));
        self::assertSame([47, 10, 57], [$counts->accept, $counts->reject, $counts->total]);
    }

    /**
     * @return iterable<string, array{\stdClass}>
     */
    public static function acceptVectors(): iterable
    {
        foreach (self::vectors('MUST-ACCEPT') as $vector) {
            yield self::str($vector->id) => [$vector];
        }
    }

    #[DataProvider('acceptVectors')]
    public function testVectorMustAccept(\stdClass $vector): void
    {
        $value = $vector->input;
        if ($vector->group === 'a2-signatures-exclusion') {
            // Spec 8.4.1 rule 3: `signatures` is excluded unconditionally, then
            // empty values are dropped (Python: signing._clean_empty).
            self::assertInstanceOf(\stdClass::class, $value);
            $value = clone $value;
            unset($value->signatures);
            $value = self::cleanEmpty($value);
        }

        self::assertSame($vector->canonical_utf8_hex, bin2hex(Jcs::canonicalize($value)), self::str($vector->rationale));
    }

    /**
     * @return iterable<string, array{\stdClass}>
     */
    public static function rejectVectors(): iterable
    {
        foreach (self::vectors('MUST-REJECT') as $vector) {
            yield self::str($vector->id) => [$vector];
        }
    }

    #[DataProvider('rejectVectors')]
    public function testVectorMustReject(\stdClass $vector): void
    {
        if ($vector->group === 'a2-signatures-exclusion') {
            self::markTestSkipped('Producer-side signature exclusion is asserted with Agent Card signing (phase 5).');
        }

        // Lone surrogates and NaN/Infinity: PHP's json_decode already refuses
        // them (Python's json.loads accepts them and canonicalize rejects).
        // Either way the value must never canonicalize.
        try {
            $value = json_decode(self::str($vector->input_raw), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->expectException(CanonicalizationError::class);
        Jcs::canonicalize($value);
    }

    // --- Axis 1: literal UTF-8 rather than \uXXXX escapes (RFC 8785 3.2.2.2) ---

    public function testNonAsciiIsEmittedAsLiteralUtf8(): void
    {
        $canonical = Jcs::canonicalize(['name' => 'Café Agent', 'description' => 'Planifie des itinéraires.']);

        self::assertStringContainsString('Café Agent', $canonical);
        self::assertStringNotContainsString('\\u00e9', $canonical);
    }

    public function testLineAndParagraphSeparatorsAreNotEscaped(): void
    {
        $canonical = Jcs::canonicalize(['name' => "a\u{2028}b\u{2029}c"]);

        self::assertStringContainsString("a\u{2028}b\u{2029}c", $canonical);
        self::assertStringNotContainsString('\\u2028', $canonical);
    }

    public function testMandatoryEscapesAreStillApplied(): void
    {
        self::assertSame('{"k":"a\\"b\\\\c\\nd\\te\\u0000f\\u001ff"}', Jcs::canonicalize(['k' => "a\"b\\c\nd\te\x00f\x1ff"]));
        self::assertSame('"\\b\\f\\r"', Jcs::canonicalize("\x08\x0c\r"));
    }

    public function testNonBmpCharactersSurviveAsLiteralUtf8(): void
    {
        self::assertSame("{\"k\":\"\xf0\x9f\x98\x80\"}", Jcs::canonicalize(['k' => "\u{1F600}"]));
    }

    // --- Axis 2: UTF-16 code unit key ordering (RFC 8785 3.2.3) ---

    public function testKeysSortByUtf16CodeUnitNotCodePoint(): void
    {
        // U+1F600's leading surrogate 0xD83D sorts below U+FF01; its code
        // point sorts above. Byte-wise UTF-8 order gets this backwards.
        self::assertSame("{\"\u{1F600}\":1,\"\u{FF01}\":2}", Jcs::canonicalize(["\u{FF01}" => 2, "\u{1F600}" => 1]));
    }

    public function testPrefixKeysSortFirst(): void
    {
        self::assertSame('{"a":2,"ab":1,"b":3}', Jcs::canonicalize(['ab' => 1, 'a' => 2, 'b' => 3]));
    }

    public function testEmptyKeySortsFirst(): void
    {
        self::assertSame('{"":2,"a":1}', Jcs::canonicalize(['a' => 1, '' => 2]));
    }

    // --- Axis 3: ECMAScript number formatting (RFC 8785 3.2.2.3) ---

    /**
     * @return iterable<string, array{float|int, string}>
     */
    public static function numbers(): iterable
    {
        $table = [
            [0.000001, '0.000001'], [1e-7, '1e-7'], [1e-6, '0.000001'], [0.0, '0'], [-0.0, '0'],
            [1.0, '1'], [-1.0, '-1'], [1e20, '100000000000000000000'], [1e21, '1e+21'],
            [1.2e21, '1.2e+21'], [5e-324, '5e-324'], [2.2250738585072014e-308, '2.2250738585072014e-308'],
            [9007199254740991.0, '9007199254740991'], [0.1, '0.1'], [1.5, '1.5'], [1e100, '1e+100'],
            [-1e-7, '-1e-7'], [3.141592653589793, '3.141592653589793'], [1.7976931348623157e308, '1.7976931348623157e+308'],
            [123.456, '123.456'], [100.0, '100'], [0.00001234, '0.00001234'],
        ];
        foreach ($table as $i => [$value, $expected]) {
            yield '#' . $i . ' ' . var_export($value, true) => [$value, $expected];
        }
    }

    #[DataProvider('numbers')]
    public function testNumberFormatting(float|int $value, string $expected): void
    {
        self::assertSame('{"n":' . $expected . '}', Jcs::canonicalize(['n' => $value]));
    }

    public function testNumberFormattingDoesNotDependOnIniSettings(): void
    {
        $previous = ini_set('serialize_precision', '5');
        try {
            self::assertSame('3.141592653589793', Jcs::canonicalize(M_PI));
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
        self::assertSame((string) $previous, ini_get('serialize_precision'));
    }

    public function testNumberFormattingMatchesEcmaScriptOverRandomDoublesAndThresholds(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('Node.js not found; it is the ECMAScript Number::toString oracle.');
        }

        // Sweep the double bit space (fixed seed) plus the exponent thresholds
        // where naive formatters diverge, as the Python test does.
        mt_srand(8785);
        $values = [];
        for ($i = 0; $i < 20000; $i++) {
            $value = unpack('E', pack('NN', mt_rand(0, 0xFFFFFFFF), mt_rand(0, 0xFFFFFFFF)))[1] ?? NAN;
            if (is_float($value) && is_finite($value)) {
                $values[] = $value;
            }
        }
        foreach (range(-330, 308) as $exponent) {
            foreach (['1', '1.5', '9', '9.999999999999998', '1.0000000000000002', '5', '3'] as $mantissa) {
                $value = (float) "{$mantissa}e{$exponent}";
                if (is_finite($value)) {
                    $values[] = $value;
                }
            }
        }
        self::assertGreaterThan(19000, count($values), 'the sweep must actually compare values');

        $input = implode("\n", array_map(static fn(float $v): string => bin2hex(pack('E', $v)), $values));
        $script = 'const h=require("fs").readFileSync(0,"utf8").split("\n");'
            . 'process.stdout.write(h.map(x=>String(Buffer.from(x,"hex").readDoubleBE(0))).join("\n"));';
        $process = proc_open([$node, '-e', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $expected = explode("\n", (string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($process);

        self::assertCount(count($values), $expected);
        foreach ($values as $i => $value) {
            if (Jcs::canonicalize($value) !== $expected[$i]) {
                self::fail(sprintf('%s: got %s, ECMAScript says %s', bin2hex(pack('E', $value)), Jcs::canonicalize($value), $expected[$i]));
            }
        }
        $this->addToAssertionCount(count($values));
    }

    public function testBooleansAreNotIntegers(): void
    {
        self::assertSame('{"a":true,"b":false}', Jcs::canonicalize(['a' => true, 'b' => false]));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFinite(): iterable
    {
        yield 'NaN' => [NAN];
        yield 'Infinity' => [INF];
        yield '-Infinity' => [-INF];
    }

    #[DataProvider('nonFinite')]
    public function testNonFiniteNumbersAreRejected(float $value): void
    {
        $this->expectException(CanonicalizationError::class);

        Jcs::canonicalize(['n' => $value]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unsafeIntegers(): iterable
    {
        yield '2**53' => [9007199254740992];
        yield '-(2**53)' => [-9007199254740992];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX];
    }

    #[DataProvider('unsafeIntegers')]
    public function testIntegersOutsideTheDoubleRangeAreRejected(int $value): void
    {
        $this->expectException(CanonicalizationError::class);

        Jcs::canonicalize(['n' => $value]);
    }

    public function testIntegersInsideTheDoubleRangeAreAccepted(): void
    {
        foreach ([9007199254740991, -9007199254740991, 0, -1, 42] as $value) {
            self::assertSame('{"n":' . $value . '}', Jcs::canonicalize(['n' => $value]));
        }
    }

    // --- Depth bound ---

    public function testNestingAtTheLimitIsAccepted(): void
    {
        Jcs::canonicalize(self::nest(Jcs::MAX_DEPTH - 1));

        $this->expectException(CanonicalizationError::class);
        Jcs::canonicalize(self::nest(Jcs::MAX_DEPTH));
    }

    public function testNestingBeyondTheLimitIsRejected(): void
    {
        foreach ([Jcs::MAX_DEPTH + 1, 5000] as $depth) {
            try {
                Jcs::canonicalize(self::nest($depth));
                self::fail("depth {$depth} was accepted");
            } catch (CanonicalizationError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDeepArraysAreBounded(): void
    {
        $value = [1];
        for ($i = 0; $i < 5000; $i++) {
            $value = [$value];
        }

        $this->expectException(CanonicalizationError::class);
        Jcs::canonicalize($value);
    }

    // --- Values with no canonical form ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUtf8(): iterable
    {
        // How a lone surrogate looks in a PHP string: CESU-8 bytes, invalid UTF-8.
        yield 'lone high surrogate' => ["\xED\xA0\x80"];
        yield 'lone low surrogate inside text' => ["a\xED\xB0\x80b"];
        yield 'two high surrogates' => ["\xED\xA0\x80\xED\xA0\x80"];
        yield 'truncated sequence' => ["\xC3"];
    }

    #[DataProvider('invalidUtf8')]
    public function testInvalidUtf8InValuesIsRejected(string $value): void
    {
        $this->expectException(CanonicalizationError::class);

        Jcs::canonicalize(['k' => $value]);
    }

    #[DataProvider('invalidUtf8')]
    public function testInvalidUtf8InKeysIsRejected(string $key): void
    {
        $this->expectException(CanonicalizationError::class);

        Jcs::canonicalize([$key => 1, 'a' => 2]);
    }

    public function testNumericKeysAreTreatedAsStrings(): void
    {
        // PHP stores the key "10" as int 10; it is still the JSON key "10".
        self::assertSame('{"10":"b","9":"a"}', Jcs::canonicalize(['9' => 'a', '10' => 'b']));
    }

    public function testEmptyContainers(): void
    {
        self::assertSame('[]', Jcs::canonicalize([]));
        self::assertSame('{}', Jcs::canonicalize(new \stdClass()));
        self::assertSame('{"a":{},"b":[]}', Jcs::canonicalize(['b' => [], 'a' => new \stdClass()]));
    }

    public function testUnsupportedTypesAreRejected(): void
    {
        $this->expectException(CanonicalizationError::class);

        Jcs::canonicalize(['k' => new \ArrayObject([1, 2])]);
    }

    public function testNoncharactersArePermitted(): void
    {
        self::assertSame("{\"k\":\"\u{FFFE}\u{FFFF}\"}", Jcs::canonicalize(['k' => "\u{FFFE}\u{FFFF}"]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function corpus(): array
    {
        if (self::$corpus === null) {
            $decoded = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/jcs_vectors.json'), false, 512, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $decoded);
            /** @var array<string, mixed> $vars */
            $vars = get_object_vars($decoded);
            self::$corpus = $vars;
        }

        return self::$corpus;
    }

    /**
     * @return list<\stdClass>
     */
    private static function vectors(string $disposition): array
    {
        /** @var list<\stdClass> $vectors */
        $vectors = self::corpus()['vectors'];

        return array_values(array_filter($vectors, static fn(\stdClass $v): bool => $v->disposition === $disposition));
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * Test-side port of a2a-python signing._clean_empty for decoded JSON.
     */
    private static function cleanEmpty(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $cleaned = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $item = self::cleanEmpty($item);
                if ($item !== null) {
                    $cleaned->{$key} = $item;
                }
            }

            return get_object_vars($cleaned) === [] ? null : $cleaned;
        }
        if (is_array($value)) {
            $cleaned = array_values(array_filter(array_map(self::cleanEmpty(...), $value), static fn(mixed $v): bool => $v !== null));

            return $cleaned === [] ? null : $cleaned;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function nest(int $depth): array
    {
        $value = ['x' => 1];
        for ($i = 0; $i < $depth; $i++) {
            $value = ['a' => $value];
        }

        return $value;
    }
}
