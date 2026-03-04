<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\Attribute\RequiresEnvelope;
use ReflectionClass;

#[CoversClass(RequiresEnvelope::class)]
final class RequiresEnvelopeTest extends TestCase
{
    #[Test]
    public function canBeInstantiated(): void
    {
        $attr = new RequiresEnvelope();

        self::assertInstanceOf(RequiresEnvelope::class, $attr);
    }

    #[Test]
    public function targetsClassOnly(): void
    {
        $ref = new ReflectionClass(RequiresEnvelope::class);
        $attributes = $ref->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_CLASS, $instance->flags);
    }

    #[Test]
    public function isReadonlyClass(): void
    {
        $ref = new ReflectionClass(RequiresEnvelope::class);

        self::assertTrue($ref->isReadonly());
    }

    #[Test]
    public function isFinalClass(): void
    {
        $ref = new ReflectionClass(RequiresEnvelope::class);

        self::assertTrue($ref->isFinal());
    }

    #[Test]
    public function canBeReflectedFromAnnotatedClass(): void
    {
        $testClass = new #[RequiresEnvelope] class {};
        $ref = new ReflectionClass($testClass);
        $attrs = $ref->getAttributes(RequiresEnvelope::class);

        self::assertCount(1, $attrs);
        self::assertInstanceOf(RequiresEnvelope::class, $attrs[0]->newInstance());
    }
}
