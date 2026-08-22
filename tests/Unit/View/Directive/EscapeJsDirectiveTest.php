<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\EscapeJsDirective;

#[CoversClass(EscapeJsDirective::class)]
final class EscapeJsDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_escape_js(): void
    {
        $directive = new EscapeJsDirective();

        self::assertSame('escape_js', $directive->name());
    }

    #[Test]
    public function compile_uses_json_encode_with_security_flags(): void
    {
        $directive = new EscapeJsDirective();

        $output = $directive->compile('$data');

        self::assertStringContainsString('json_encode', $output);
        self::assertStringContainsString('JSON_HEX_TAG', $output);
        self::assertStringContainsString('JSON_HEX_AMP', $output);
        self::assertStringContainsString('JSON_HEX_APOS', $output);
        self::assertStringContainsString('JSON_HEX_QUOT', $output);
        self::assertStringContainsString('JSON_THROW_ON_ERROR', $output);
    }

    #[Test]
    public function compile_embeds_trimmed_expression(): void
    {
        $directive = new EscapeJsDirective();

        $output = $directive->compile('  $myVar  ');

        self::assertStringContainsString('$myVar', $output);
        // Should not have leading/trailing spaces around the variable
        self::assertStringNotContainsString('  $myVar  ', $output);
    }

    #[Test]
    public function compile_handles_complex_expression(): void
    {
        $directive = new EscapeJsDirective();

        $output = $directive->compile('["key" => $value, "other" => 42]');

        self::assertStringContainsString('["key" => $value, "other" => 42]', $output);
    }
}
