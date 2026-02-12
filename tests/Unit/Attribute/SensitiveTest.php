<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Attribute\Sensitive;
use ReflectionClass;

#[CoversClass(Sensitive::class)]
final class SensitiveTest extends TestCase
{
    #[Test]
    public function defaultReason(): void
    {
        $attr = new Sensitive();

        self::assertSame('', $attr->reason);
    }

    #[Test]
    public function customReason(): void
    {
        $attr = new Sensitive(reason: 'API key');

        self::assertSame('API key', $attr->reason);
    }

    #[Test]
    public function targetsPropertiesOnly(): void
    {
        $ref = new ReflectionClass(Sensitive::class);
        $attributes = $ref->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);
        $instance = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_PROPERTY, $instance->flags);
    }

    #[Test]
    public function isReadonly(): void
    {
        $ref = new ReflectionClass(Sensitive::class);

        self::assertTrue($ref->isReadonly());
    }
}
