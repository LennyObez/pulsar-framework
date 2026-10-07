<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\EndDeferDirective;

#[CoversClass(EndDeferDirective::class)]
final class EndDeferDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_enddefer(): void
    {
        $directive = new EndDeferDirective();

        self::assertSame('enddefer', $directive->name());
    }

    #[Test]
    public function compile_captures_buffer_and_wraps_in_template(): void
    {
        $directive = new EndDeferDirective();

        $output = $directive->compile('');

        self::assertStringContainsString('ob_get_clean', $output);
        self::assertStringContainsString('<template data-deferred-content>', $output);
        self::assertStringContainsString('</pulse-deferred>', $output);
    }

    #[Test]
    public function compile_cleans_up_defer_content_variable(): void
    {
        $directive = new EndDeferDirective();

        $output = $directive->compile('');

        self::assertStringContainsString('unset($__defer_content)', $output);
    }

    #[Test]
    public function compile_ignores_expression(): void
    {
        $directive = new EndDeferDirective();

        $with = $directive->compile('ignored');
        $without = $directive->compile('');

        self::assertSame($with, $without);
    }
}
