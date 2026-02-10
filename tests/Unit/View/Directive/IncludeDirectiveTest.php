<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\IncludeDirective;

#[CoversClass(IncludeDirective::class)]
final class IncludeDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsInclude(): void
    {
        $directive = new IncludeDirective();

        self::assertSame('include', $directive->name());
    }

    #[Test]
    public function compileProducesRenderIncludeCall(): void
    {
        $directive = new IncludeDirective();

        $output = $directive->compile("'partials.header', ['title' => 'Home']");

        self::assertStringContainsString('$__env->renderInclude', $output);
        self::assertStringContainsString("'partials.header'", $output);
    }

    #[Test]
    public function compileTrimsExpression(): void
    {
        $directive = new IncludeDirective();

        $output = $directive->compile("  'nav'  ");

        self::assertStringContainsString("'nav'", $output);
    }
}
