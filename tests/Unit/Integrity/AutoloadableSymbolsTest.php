<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\AutoloadableSymbolScanner;

use function dirname;
use function implode;

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
 *
 * The rule itself lives in AutoloadableSymbolScanner, which takes the root it reads, so
 * AutoloadableSymbolsRefusesTest can build a tree containing the fault and watch this
 * rule name it. Wired to this checkout the rule could only ever answer "none", and
 * "none" is also what a broken scan answers.
 */
final class AutoloadableSymbolsTest extends TestCase
{
    #[Test]
    public function everySymbolReferencedAcrossFilesIsAutoloadable(): void
    {
        $scanner = new AutoloadableSymbolScanner(dirname(__DIR__, 3));

        self::assertNotSame([], $scanner->sources(), 'no sources scanned — the check would pass vacuously');

        $violations = $scanner->violations();

        self::assertSame(
            [],
            $violations,
            'These symbols are declared in a file the autoloader will never associate with them, '
            . "yet another file names them. Move each into its own file:\n  "
            . implode("\n  ", $violations),
        );
    }
}
