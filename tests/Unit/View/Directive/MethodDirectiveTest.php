<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\MethodDirective;

#[CoversClass(MethodDirective::class)]
final class MethodDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsMethod(): void
    {
        $directive = new MethodDirective();

        self::assertSame('method', $directive->name());
    }

    #[Test]
    public function compileProducesHiddenMethodField(): void
    {
        $directive = new MethodDirective();

        $output = $directive->compile("'PUT'");

        self::assertStringContainsString('hidden', $output);
        self::assertStringContainsString('_method', $output);
        self::assertStringContainsString("'PUT'", $output);
        self::assertStringContainsString('htmlspecialchars', $output);
    }

    #[Test]
    public function compileTrimsExpression(): void
    {
        $directive = new MethodDirective();

        $output = $directive->compile("  'DELETE'  ");

        self::assertStringContainsString("'DELETE'", $output);
        self::assertStringNotContainsString("  'DELETE'  ", $output);
    }
}
