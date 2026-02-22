<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\CsrfDirective;

#[CoversClass(CsrfDirective::class)]
final class CsrfDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsCsrf(): void
    {
        $directive = new CsrfDirective();

        self::assertSame('csrf', $directive->name());
    }

    #[Test]
    public function compileProducesHiddenInput(): void
    {
        $directive = new CsrfDirective();

        $output = $directive->compile('');

        self::assertStringContainsString('hidden', $output);
        self::assertStringContainsString('_token', $output);
        self::assertStringContainsString('$__csrf', $output);
        self::assertStringContainsString('htmlspecialchars', $output);
    }

    #[Test]
    public function compileIgnoresExpression(): void
    {
        $directive = new CsrfDirective();

        $withExpression = $directive->compile('some ignored value');
        $withoutExpression = $directive->compile('');

        self::assertSame($withExpression, $withoutExpression);
    }
}
