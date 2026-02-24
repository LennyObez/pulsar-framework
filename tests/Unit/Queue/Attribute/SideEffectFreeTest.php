<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\SideEffectFree;

#[CoversClass(SideEffectFree::class)]
final class SideEffectFreeTest extends TestCase
{
    #[Test]
    public function canBeInstantiated(): void
    {
        $attr = new SideEffectFree();

        self::assertInstanceOf(SideEffectFree::class, $attr);
    }
}
