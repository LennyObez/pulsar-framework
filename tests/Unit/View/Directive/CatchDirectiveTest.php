<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\CatchDirective;

#[CoversClass(CatchDirective::class)]
final class CatchDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_catch(): void
    {
        $directive = new CatchDirective();

        self::assertSame('catch', $directive->name());
    }

    #[Test]
    public function compile_closes_try_and_opens_catch_block(): void
    {
        $directive = new CatchDirective();

        $output = $directive->compile("'Error occurred'");

        self::assertStringContainsString('ob_get_clean()', $output);
        self::assertStringContainsString('catch', $output);
        self::assertStringContainsString('\\Throwable $e', $output);
        self::assertStringContainsString('ob_end_clean()', $output);
    }

    #[Test]
    public function compile_escapes_fallback_message(): void
    {
        $directive = new CatchDirective();

        $output = $directive->compile("'Fallback message'");

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString("'Fallback message'", $output);
    }

    #[Test]
    public function compile_with_empty_expression_uses_empty_string_fallback(): void
    {
        $directive = new CatchDirective();

        $output = $directive->compile('');

        // Empty expression should default to "''" (empty string literal)
        self::assertStringContainsString("''", $output);
    }

    #[Test]
    public function compile_trims_expression_whitespace(): void
    {
        $directive = new CatchDirective();

        $output = $directive->compile("   'msg'   ");

        self::assertStringContainsString("'msg'", $output);
    }
}
