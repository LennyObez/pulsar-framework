<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Internal\ComponentHydrator;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

#[CoversClass(ComponentHydrator::class)]
final class ComponentHydratorTest extends TestCase
{
    private ComponentHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new ComponentHydrator();
    }

    #[Test]
    public function dehydrateExtractsLivePropValues(): void
    {
        $component = new HydrationTestComponent();
        $component->count = 5;
        $component->name = 'Alice';

        $state = $this->hydrator->dehydrate($component);

        self::assertSame(5, $state['count']);
        self::assertSame('Alice', $state['name']);
    }

    #[Test]
    public function dehydrateIgnoresNonLivePropProperties(): void
    {
        $component = new HydrationTestComponent();
        $component->internal = 'secret';

        $state = $this->hydrator->dehydrate($component);

        self::assertArrayNotHasKey('internal', $state);
    }

    #[Test]
    public function hydrateAppliesWritableProperties(): void
    {
        $component = new HydrationTestComponent();

        $this->hydrator->hydrate($component, [
            'count' => 42,
            'name' => 'Bob',
        ]);

        self::assertSame(42, $component->count);
        // name is not writable, so should remain default
        self::assertSame('', $component->name);
    }

    #[Test]
    public function hydrateIgnoresUnknownProperties(): void
    {
        $component = new HydrationTestComponent();

        $this->hydrator->hydrate($component, [
            'nonexistent' => 'value',
        ]);

        self::assertSame(0, $component->count);
    }

    #[Test]
    public function hydrateTypeCastsValues(): void
    {
        $component = new HydrationTestComponent();

        $this->hydrator->hydrate($component, [
            'count' => '7',
        ]);

        self::assertSame(7, $component->count);
    }

    #[Test]
    public function getTrackedPropertyNames(): void
    {
        $component = new HydrationTestComponent();

        $names = $this->hydrator->getTrackedPropertyNames($component);

        self::assertContains('count', $names);
        self::assertContains('name', $names);
        self::assertNotContains('internal', $names);
    }

    #[Test]
    public function getWritablePropertyNames(): void
    {
        $component = new HydrationTestComponent();

        $names = $this->hydrator->getWritablePropertyNames($component);

        self::assertContains('count', $names);
        self::assertNotContains('name', $names);
    }
}

/** @internal */
final class HydrationTestComponent extends LiveComponent
{
    #[LiveProp(writable: true)]
    public int $count = 0;

    #[LiveProp]
    public string $name = '';

    public string $internal = '';

    public function render(): string
    {
        return '<div>' . $this->count . '</div>';
    }
}
