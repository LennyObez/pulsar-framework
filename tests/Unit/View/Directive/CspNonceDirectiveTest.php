<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\CspNonceDirective;

#[CoversClass(CspNonceDirective::class)]
final class CspNonceDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_csp_nonce(): void
    {
        $directive = new CspNonceDirective();

        self::assertSame('csp_nonce', $directive->name());
    }

    #[Test]
    public function compile_outputs_escaped_nonce_variable(): void
    {
        $directive = new CspNonceDirective();

        $output = $directive->compile('');

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('$__csp_nonce', $output);
        self::assertStringContainsString('ENT_QUOTES', $output);
        self::assertStringContainsString('UTF-8', $output);
    }

    #[Test]
    public function compile_provides_empty_string_fallback(): void
    {
        $directive = new CspNonceDirective();

        $output = $directive->compile('');

        // Should use ?? '' for null coalescing to empty string
        self::assertStringContainsString("?? ''", $output);
    }

    #[Test]
    public function compile_ignores_expression(): void
    {
        $directive = new CspNonceDirective();

        $with = $directive->compile('ignored-arg');
        $without = $directive->compile('');

        self::assertSame($with, $without);
    }
}
