<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;
use function in_array;
use function preg_match;
use function preg_quote;
use function str_contains;
use function str_replace;

/**
 * A class declared in a file that does not bear its name cannot be autoloaded.
 *
 * It resolves anyway as long as something already loaded its host file, which is why
 * this survives so easily: sequential PHPUnit loads every test file during discovery,
 * so the whole suite passes. Under paratest a worker loads only the files it was
 * assigned, and the same code dies with "Class not found" — which is exactly how six
 * shared Forum doubles took out four unrelated E2E test classes.
 *
 * The rule enforced here is the correctness half, not a style preference: a symbol
 * used *only* inside its own file always resolves and is left alone. A symbol another
 * file names must live in a file named after it.
 */
final class AutoloadableSymbolsTest extends TestCase
{
    /** @var list<string> Roots that PSR-4 maps in composer.json */
    private const array ROOTS = ['src', 'tests', 'extensions'];

    #[Test]
    public function everySymbolReferencedAcrossFilesIsAutoloadable(): void
    {
        $sources = $this->sources();
        $strays = $this->strayDeclarations($sources);
        $violations = [];

        foreach ($strays as $name => $home) {
            $homeNamespace = $this->namespaceOf($sources[$home]);

            foreach ($sources as $path => $code) {
                if ($path === $home) {
                    continue;
                }

                if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $code) !== 1) {
                    continue;
                }

                // A bare name is not a reference. PHP resolves it to this symbol in
                // exactly two cases: the file imports the fully-qualified name, or it
                // shares the declaring namespace and has no symbol of its own by that
                // name. Anything else is a same-spelled class elsewhere, or a string
                // in a code-generation template — neither of which can fail to load.
                $imported = preg_match(
                    '/^use\s+' . preg_quote($homeNamespace . '\\' . $name, '/') . '\s*;/m',
                    $code,
                ) === 1;

                $sameNamespace = $this->namespaceOf($code) === $homeNamespace
                    && !$this->declares($code, $name);

                if (!$imported && !$sameNamespace) {
                    continue;
                }

                $violations[] = $name . ' (declared in ' . $home . ', named by ' . $path . ')';
                break;
            }
        }

        self::assertSame(
            [],
            $violations,
            'These symbols are declared in a file the autoloader will never associate with them, '
            . "yet another file names them. Move each into its own file:\n  "
            . implode("\n  ", $violations),
        );
    }

    /**
     * Symbols declared in a file whose basename does not match.
     *
     * @param array<string, string> $sources
     *
     * @return array<string, string> symbol => declaring file
     */
    private function strayDeclarations(array $sources): array
    {
        $strays = [];

        foreach ($sources as $path => $code) {
            $expected = basename($path, '.php');
            $tokens = PhpToken::tokenize($code);
            $total = count($tokens);

            foreach ($tokens as $i => $token) {
                if (!in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    continue;
                }

                $previous = $this->significant($tokens, $i, -1, $total);

                // `new class {}` is anonymous; `Foo::class` is a constant fetch.
                if ($previous !== null && ($previous->id === T_NEW || $previous->text === '::')) {
                    continue;
                }

                $next = $this->significant($tokens, $i, 1, $total);

                if ($next !== null && $next->id === T_STRING && $next->text !== $expected) {
                    $strays[$next->text] = $path;
                }
            }
        }

        return $strays;
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private function significant(array $tokens, int $from, int $step, int $total): ?PhpToken
    {
        for ($i = $from + $step; $i >= 0 && $i < $total; $i += $step) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }

    private function namespaceOf(string $code): string
    {
        return preg_match('/^namespace\s+([^;]+);/m', $code, $matches) === 1
            ? trim($matches[1])
            : '';
    }

    private function declares(string $code, string $name): bool
    {
        return preg_match(
            '/\b(?:class|interface|trait|enum)\s+' . preg_quote($name, '/') . '\b/',
            $code,
        ) === 1;
    }

    /**
     * @return array<string, string> path => contents
     */
    private function sources(): array
    {
        $root = dirname(__DIR__, 3);
        $sources = [];

        foreach (self::ROOTS as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                // Extensions vendor their own dependencies; those are not ours to shape.
                if (str_contains($path, '/vendor/')) {
                    continue;
                }

                $sources[$path] = (string) file_get_contents($path);
            }
        }

        return $sources;
    }
}
