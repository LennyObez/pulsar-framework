<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\IslandDirective;

#[CoversClass(IslandDirective::class)]
final class IslandDirectiveTest extends TestCase
{
    private IslandDirective $directive;

    protected function setUp(): void
    {
        $this->directive = new IslandDirective();
    }

    #[Test]
    public function nameReturnsIsland(): void
    {
        self::assertSame('island', $this->directive->name());
    }

    #[Test]
    public function compileEmitsCustomElement(): void
    {
        $result = $this->directive->compile("'counter', props: ['count' => \$count]");

        self::assertStringContainsString('pulse-island', $result);
        self::assertStringContainsString('component=', $result);
        self::assertStringContainsString('data-props=', $result);
    }

    #[Test]
    public function compileJsonEncodesPropsSecurely(): void
    {
        $result = $this->directive->compile("'widget'");

        self::assertStringContainsString('json_encode', $result);
        self::assertStringContainsString('JSON_HEX_TAG', $result);
        self::assertStringContainsString('JSON_HEX_AMP', $result);
    }

    #[Test]
    public function compileEscapesComponentName(): void
    {
        $result = $this->directive->compile("'my-component'");

        self::assertStringContainsString('htmlspecialchars', $result);
    }

    #[Test]
    public function compileCleansUpInternalVariables(): void
    {
        $result = $this->directive->compile("'x'");

        self::assertStringContainsString('unset($__island_args', $result);
    }
}
