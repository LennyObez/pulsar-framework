<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\TranslateDirective;

#[CoversClass(TranslateDirective::class)]
final class TranslateDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_t(): void
    {
        $directive = new TranslateDirective();

        self::assertSame('t', $directive->name());
    }

    #[Test]
    public function compile_uses_translator_with_fallback_to_helper(): void
    {
        $directive = new TranslateDirective();

        $output = $directive->compile("'app.welcome', ['name' => \$user]");

        self::assertStringContainsString('$__translator', $output);
        self::assertStringContainsString('->translate(', $output);
        self::assertStringContainsString('__(', $output);
    }

    #[Test]
    public function compile_escapes_output(): void
    {
        $directive = new TranslateDirective();

        $output = $directive->compile("'key'");

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('ENT_QUOTES', $output);
        self::assertStringContainsString('UTF-8', $output);
    }

    #[Test]
    public function compile_embeds_expression_in_both_branches(): void
    {
        $directive = new TranslateDirective();

        $output = $directive->compile("'page.title'");

        // Expression appears twice — once in translator branch, once in __() fallback
        $count = substr_count($output, "'page.title'");
        self::assertSame(2, $count);
    }

    #[Test]
    public function compile_trims_whitespace(): void
    {
        $directive = new TranslateDirective();

        $output = $directive->compile("   'key'   ");

        self::assertStringContainsString("'key'", $output);
    }
}
