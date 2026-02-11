<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\ComponentDirective;

#[CoversClass(ComponentDirective::class)]
final class ComponentDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsComponent(): void
    {
        self::assertSame('component', new ComponentDirective()->name());
    }

    #[Test]
    public function compileProducesStartComponentCall(): void
    {
        $output = new ComponentDirective()->compile("'alert', ['type' => 'warning']");

        self::assertStringContainsString('$__env->startComponent(', $output);
        self::assertStringContainsString("'alert'", $output);
    }
}
