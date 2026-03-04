<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\SanitizeDirective;

#[CoversClass(SanitizeDirective::class)]
final class SanitizeDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_sanitize(): void
    {
        $directive = new SanitizeDirective();

        self::assertSame('sanitize', $directive->name());
    }

    #[Test]
    public function compile_uses_htmlspecialchars_with_safe_defaults(): void
    {
        $directive = new SanitizeDirective();

        $output = $directive->compile('$userHtml');

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('ENT_QUOTES', $output);
        self::assertStringContainsString('ENT_SUBSTITUTE', $output);
        self::assertStringContainsString('UTF-8', $output);
    }

    #[Test]
    public function compile_casts_to_string(): void
    {
        $directive = new SanitizeDirective();

        $output = $directive->compile('$value');

        self::assertStringContainsString('(string)', $output);
    }

    #[Test]
    public function compile_embeds_trimmed_expression(): void
    {
        $directive = new SanitizeDirective();

        $output = $directive->compile('  $input  ');

        self::assertStringContainsString('$input', $output);
        self::assertStringNotContainsString('  $input  ', $output);
    }
}
