<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlStatus;
use ReflectionClass;

#[CoversClass(Control::class)]
final class ControlTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $control = new Control(
            id: 'CC6.1',
            framework: 'soc2',
            title: 'Logical Access Controls',
            description: 'Logical access to systems is restricted.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['auth_middleware', 'rbac'],
        );

        self::assertSame('CC6.1', $control->id);
        self::assertSame('soc2', $control->framework);
        self::assertSame('Logical Access Controls', $control->title);
        self::assertSame('Logical access to systems is restricted.', $control->description);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertSame(['auth_middleware', 'rbac'], $control->frameworkFeatures);
    }

    #[Test]
    public function frameworkFeaturesDefaultsToEmpty(): void
    {
        $control = new Control(
            id: 'R-164.312',
            framework: 'hipaa',
            title: 'Access Control',
            description: 'ePHI access restricted',
            status: ControlStatus::Partial,
        );

        self::assertSame([], $control->frameworkFeatures);
    }

    #[Test]
    public function eachStatusCanBeAssigned(): void
    {
        foreach (ControlStatus::cases() as $status) {
            $control = new Control(
                id: "TEST-{$status->value}",
                framework: 'test',
                title: "Test {$status->value}",
                description: 'Test description',
                status: $status,
            );

            self::assertSame($status, $control->status);
        }
    }

    #[Test]
    public function controlIsReadonly(): void
    {
        $control = new Control(
            id: 'CTRL-1',
            framework: 'test',
            title: 'Immutable',
            description: 'This control is immutable',
            status: ControlStatus::Planned,
        );

        $reflection = new ReflectionClass($control);
        self::assertTrue($reflection->isReadOnly());
    }
}
