<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Internal;
use ReflectionClass;

#[CoversClass(Internal::class)]
final class InternalAttributeTest extends TestCase
{
    #[Test]
    public function defaultReasonIsEmptyString(): void
    {
        $attr = new Internal();

        self::assertSame('', $attr->reason);
    }

    #[Test]
    public function customReasonIsPreserved(): void
    {
        $attr = new Internal(reason: 'Implementation detail subject to change');

        self::assertSame('Implementation detail subject to change', $attr->reason);
    }

    #[Test]
    public function targetsClassMethodAndConstant(): void
    {
        $ref = new ReflectionClass(Internal::class);
        $attributes = $ref->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $expectedFlags = Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT;
        self::assertSame($expectedFlags, $instance->flags);
    }

    #[Test]
    public function isReadonlyClass(): void
    {
        $ref = new ReflectionClass(Internal::class);

        self::assertTrue($ref->isReadonly());
    }

    #[Test]
    public function isFinalClass(): void
    {
        $ref = new ReflectionClass(Internal::class);

        self::assertTrue($ref->isFinal());
    }
}
