<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\OverrideScanner;
use Pulsar\Tests\Unit\Integrity\Support\ReflectedClassIndex;

use function count;
use function dirname;
use function implode;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Verify that every method annotated with #[Override] actually overrides a parent class
 * method or implements an interface method.
 *
 * This assertion cannot fail, and that is the honest description of it. PHP refuses to
 * compile the state it looks for — "has #[\Override] attribute, but no matching parent
 * method exists" is a fatal error, raised before any test runs and uncatchable even
 * through eval() — so no file can put a class into that state and be loaded. The engine
 * is the gate; this is a second opinion that agrees by construction.
 *
 * It is kept because it costs a few milliseconds and would catch a future PHP that
 * relaxed the compile-time check, and because deleting it would remove the coverage
 * floor below, which is a real measurement. What it is NOT is a control anybody should
 * count: OverrideRefusesTest records the compile-time refusal with a probe, and tests
 * the one part of this rule the engine does not supply — the predicate that decides
 * whether a method overrides anything.
 */
#[CoversNothing]
final class OverrideCorrectnessTest extends TestCase
{
    private static OverrideScanner $scanner;

    public static function setUpBeforeClass(): void
    {
        self::$scanner = new OverrideScanner(new ReflectedClassIndex(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src',
            'Pulsar\\',
        ));
    }

    #[Test]
    public function all_override_methods_actually_override(): void
    {
        $failures = self::$scanner->staleOverrides();

        self::assertSame(
            [],
            $failures,
            sprintf(
                "The following methods have #[Override] but do not override a parent/interface method:\n- %s",
                implode("\n- ", $failures),
            ),
        );
    }

    #[Test]
    public function override_coverage_is_not_empty(): void
    {
        self::assertGreaterThan(
            100,
            count(self::$scanner->overrideMethods()),
            'Expected at least 100 #[Override] annotated methods in the codebase',
        );
    }
}
