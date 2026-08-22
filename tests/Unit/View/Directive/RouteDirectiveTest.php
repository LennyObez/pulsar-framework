<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\RouteDirective;

#[CoversClass(RouteDirective::class)]
final class RouteDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsRoute(): void
    {
        self::assertSame('route', new RouteDirective()->name());
    }

    #[Test]
    public function compileProducesEscapedRouteCall(): void
    {
        $output = new RouteDirective()->compile("'development'");

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('route(', $output);
        self::assertStringContainsString("'development'", $output);
    }

    #[Test]
    public function compilePassesParametersAndLocaleThrough(): void
    {
        $output = new RouteDirective()->compile("'blog/post', ['slug' => \$slug], 'fr'");

        self::assertStringContainsString("route('blog/post', ['slug' => \$slug], 'fr')", $output);
    }

    #[Test]
    public function compileIncludesEntSubstituteFlag(): void
    {
        $output = new RouteDirective()->compile("'home'");

        self::assertStringContainsString('ENT_QUOTES | ENT_SUBSTITUTE', $output);
        self::assertStringContainsString("'UTF-8'", $output);
    }
}
