<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Scope\StatefulSingletonViolation;
use Pulsar\Runtime\Scope\ViolationType;

#[CoversClass(StatefulSingletonViolation::class)]
final class StatefulSingletonViolationTest extends TestCase
{
    #[Test]
    public function it_exposes_all_properties(): void
    {
        $violation = new StatefulSingletonViolation(
            className: 'App\\Service\\UserCache',
            property: 'cache',
            type: ViolationType::WritableProperty,
            message: 'Singleton App\\Service\\UserCache has writable property $cache',
        );

        self::assertSame('App\\Service\\UserCache', $violation->className);
        self::assertSame('cache', $violation->property);
        self::assertSame(ViolationType::WritableProperty, $violation->type);
        self::assertSame('Singleton App\\Service\\UserCache has writable property $cache', $violation->message);
    }

    #[Test]
    public function it_accepts_mutable_static_type(): void
    {
        $violation = new StatefulSingletonViolation(
            className: 'App\\Counter',
            property: 'count',
            type: ViolationType::MutableStatic,
            message: 'Mutable static detected',
        );

        self::assertSame(ViolationType::MutableStatic, $violation->type);
    }

    #[Test]
    public function it_accepts_reset_method_type(): void
    {
        $violation = new StatefulSingletonViolation(
            className: 'App\\Session',
            property: 'reset()',
            type: ViolationType::ResetMethod,
            message: 'Reset method detected',
        );

        self::assertSame(ViolationType::ResetMethod, $violation->type);
        self::assertSame('reset()', $violation->property);
    }
}
