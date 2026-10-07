<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\DeferDirective;

#[CoversClass(DeferDirective::class)]
final class DeferDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_defer(): void
    {
        $directive = new DeferDirective();

        self::assertSame('defer', $directive->name());
    }

    #[Test]
    public function compile_with_named_slot(): void
    {
        $directive = new DeferDirective();

        $output = $directive->compile("'sidebar'");

        self::assertStringContainsString('pulse-deferred', $output);
        self::assertStringContainsString('data-slot', $output);
        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('ob_start', $output);
        self::assertStringContainsString("'sidebar'", $output);
    }

    #[Test]
    public function compile_with_empty_expression_generates_auto_id(): void
    {
        $directive = new DeferDirective();

        $output = $directive->compile('');

        self::assertStringContainsString('deferred-', $output);
        self::assertStringContainsString('__defer_id', $output);
    }

    #[Test]
    public function compile_stores_slot_in_defer_slot_variable(): void
    {
        $directive = new DeferDirective();

        $output = $directive->compile("'content'");

        self::assertStringContainsString('$__defer_slot', $output);
    }
}
