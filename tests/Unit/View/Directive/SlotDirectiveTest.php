<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\SlotDirective;

#[CoversClass(SlotDirective::class)]
final class SlotDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsSlot(): void
    {
        self::assertSame('slot', new SlotDirective()->name());
    }

    #[Test]
    public function compileProducesStartSlotCall(): void
    {
        $output = new SlotDirective()->compile("'header'");

        self::assertSame("<?php \$__env->startSlot('header'); ?>", $output);
    }
}
