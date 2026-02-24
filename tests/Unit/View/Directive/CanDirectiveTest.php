<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\CanDirective;

#[CoversClass(CanDirective::class)]
final class CanDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsCan(): void
    {
        self::assertSame('can', new CanDirective()->name());
    }

    #[Test]
    public function compileIncludesAbilityExpression(): void
    {
        $output = new CanDirective()->compile("'edit-post', \$post");

        self::assertStringContainsString('$__auth->can(', $output);
        self::assertStringContainsString("'edit-post'", $output);
    }
}
