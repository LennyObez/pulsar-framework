<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\NoDiscardScanner;
use Pulsar\Tests\Unit\Integrity\Support\ReflectedClassIndex;

use function count;
use function dirname;
use function implode;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Verify that #[NoDiscard] is applied correctly across src/.
 *
 * 1. Methods with #[NoDiscard] must return a non-void type.
 * 2. Immutable with*() methods on readonly classes must carry #[NoDiscard].
 *
 * Rule 1 is enforced by the engine before it is enforced here, and that is worth stating
 * where a reader will meet it: PHP 8.5 raises an uncatchable fatal — "A void method does
 * not return a value, but #[\NoDiscard] requires a return value" — so a class in that
 * state cannot be loaded, and this assertion cannot fail. NoDiscardRefusesTest proves
 * that with a probe rather than leaving it as a claim, because an assertion that cannot
 * fail and an assertion that is passing look identical from here.
 *
 * Rule 2 is real, and NoDiscardRefusesTest watches it refuse a fixture that violates it.
 */
#[CoversNothing]
final class NoDiscardCorrectnessTest extends TestCase
{
    private static NoDiscardScanner $scanner;

    public static function setUpBeforeClass(): void
    {
        self::$scanner = new NoDiscardScanner(new ReflectedClassIndex(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src',
            'Pulsar\\',
        ));
    }

    #[Test]
    public function no_discard_methods_return_non_void(): void
    {
        $failures = self::$scanner->voidMethodsCarryingNoDiscard();

        self::assertSame(
            [],
            $failures,
            sprintf(
                "The following methods have #[NoDiscard] but return void:\n- %s",
                implode("\n- ", $failures),
            ),
        );
    }

    #[Test]
    public function no_discard_coverage_is_not_empty(): void
    {
        self::assertGreaterThan(
            50,
            count(self::$scanner->noDiscardMethods()),
            'Expected at least 50 #[NoDiscard] annotated methods in the codebase',
        );
    }

    #[Test]
    public function with_methods_on_readonly_classes_have_no_discard(): void
    {
        $missing = self::$scanner->withMethodsMissingNoDiscard();

        self::assertSame(
            [],
            $missing,
            sprintf(
                "The following with*() methods on readonly classes are missing #[NoDiscard]:\n- %s",
                implode("\n- ", $missing),
            ),
        );
    }
}
