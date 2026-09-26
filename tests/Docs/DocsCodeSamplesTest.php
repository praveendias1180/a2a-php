<?php

declare(strict_types=1);

namespace A2A\Tests\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the inline PHP samples in docs/ honest. Longer samples are pulled from
 * examples/ (run by other tests); the short inline ones are checked here:
 *
 *  - every block parses (blocks are wrapped in `<?php`; a block may start with
 *    `// fragment` to opt out of the syntax check when it's deliberately partial)
 *  - every A2A class it imports or names exists
 *  - every static call `Class::method(` on an A2A class names a real method
 */
final class DocsCodeSamplesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function samples(): iterable
    {
        $root = \dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/docs'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }
            $markdown = (string) file_get_contents($file->getPathname());
            preg_match_all('/^([ \t]*)```php\n(.*?)^\1```/ms', $markdown, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[2] as $i => [$code, $offset]) {
                if (str_contains($code, '--8<--')) {
                    continue; // pulled from a tested file
                }
                $indent = $matches[1][$i][0];
                if ($indent !== '') {
                    $code = (string) preg_replace('/^' . preg_quote($indent, '/') . '/m', '', $code);
                }
                $line = substr_count(substr($markdown, 0, (int) $offset), "\n") + 1;
                $name = substr($file->getPathname(), \strlen($root) + 1) . ':' . $line;
                yield $name => [$name, $line, $code];
            }
        }
    }

    #[DataProvider('samples')]
    public function testSampleParses(string $name, int $line, string $code): void
    {
        if (str_starts_with(ltrim($code), '// fragment')) {
            $this->addToAssertionCount(1);

            return;
        }
        $php = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n" . $code;
        // Short samples are often fragments: config entries ('key' => ...) or
        // one method. They pass when they parse as they are, as array
        // elements, or as a class body.
        $candidates = [$php, "<?php return [\n" . $code . "\n];", "<?php class DocsSample {\n" . $code . "\n}"];
        $error = null;
        foreach ($candidates as $candidate) {
            try {
                self::assertNotEmpty(token_get_all($candidate, TOKEN_PARSE));

                return;
            } catch (\ParseError $e) {
                $error ??= $e;
            }
        }
        self::fail(sprintf("%s does not parse: %s (sample line %d)\n%s", $name, $error->getMessage(), $error->getLine() - 1, $code));
    }

    #[DataProvider('samples')]
    public function testSampleUsesRealA2AClassesAndMethods(string $name, int $line, string $code): void
    {
        $imports = self::imports($code);
        $classes = array_values($imports);
        preg_match_all('/(?<![\w\\\\])\\\\?(A2A(?:\\\\[A-Za-z_]\w*)++)(?!\\\\)/', $code, $fq);
        foreach ($fq[1] as $class) {
            $classes[] = $class;
        }
        foreach (array_unique($classes) as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class),
                "$name uses $class, which doesn't exist",
            );
        }

        preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([a-z][A-Za-z0-9_]*)\s*\(/', $code, $calls, PREG_SET_ORDER);
        foreach ($calls as [, $short, $method]) {
            $class = $imports[$short] ?? null;
            if ($class === null || !str_starts_with($class, 'A2A\\') || !class_exists($class)) {
                continue;
            }
            self::assertTrue(method_exists($class, $method), "$name calls $short::$method(), which doesn't exist on $class");
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, string> short name => fully qualified class
     */
    private static function imports(string $code): array
    {
        $map = [];
        preg_match_all('/^\s*use\s+([^;]+);/m', $code, $uses);
        foreach ($uses[1] as $use) {
            $use = trim($use);
            if (preg_match('/^([\w\\\\]+)\\\\\{([^}]+)\}$/', $use, $group)) {
                foreach (explode(',', $group[2]) as $member) {
                    $member = trim($member);
                    if ($member === '') {
                        continue;
                    }
                    $parts = array_map('trim', explode(' as ', $member, 2));
                    $full = $group[1] . '\\' . $parts[0];
                    $map[$parts[1] ?? substr((string) strrchr('\\' . $full, '\\'), 1)] = $full;
                }
                continue;
            }
            $parts = array_map('trim', explode(' as ', $use, 2));
            $fqcn = ltrim($parts[0], '\\');
            $map[$parts[1] ?? substr((string) strrchr('\\' . $fqcn, '\\'), 1)] = $fqcn;
        }

        return array_filter($map, static fn(string $class): bool => str_starts_with($class, 'A2A\\'));
    }
}
