<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\GuestDirective;

#[CoversClass(GuestDirective::class)]
final class GuestDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsGuest(): void
    {
        self::assertSame('guest', new GuestDirective()->name());
    }

    #[Test]
    public function compileWithoutGuardUsesNull(): void
    {
        $output = new GuestDirective()->compile('');

        self::assertSame('<?php if (!$__auth->check(null)): ?>', $output);
    }

    #[Test]
    public function compileWithGuardPassesGuardName(): void
    {
        $output = new GuestDirective()->compile("'api'");

        self::assertSame("<?php if (!\$__auth->check('api')): ?>", $output);
    }
}
