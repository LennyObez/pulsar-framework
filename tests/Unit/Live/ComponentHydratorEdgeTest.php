<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Internal\ComponentHydrator;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

/**
 * Edge case tests for ComponentHydrator.
 */
#[CoversClass(ComponentHydrator::class)]
final class ComponentHydratorEdgeTest extends TestCase
{
    #[Test]
    public function dehydrateExtractsLivePropValues(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();
        $component->name = 'Alice';
        $component->count = 42;

        $state = $hydrator->dehydrate($component);

        self::assertSame('Alice', $state['name']);
        self::assertSame(42, $state['count']);
    }

    #[Test]
    public function dehydrateIgnoresNonLivePropProperties(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();
        $component->internal = 'secret';

        $state = $hydrator->dehydrate($component);

        self::assertArrayNotHasKey('internal', $state);
    }

    #[Test]
    public function hydrateUpdatesWritableProperties(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();

        $hydrator->hydrate($component, ['name' => 'Bob', 'count' => 10]);

        self::assertSame('Bob', $component->name);
        self::assertSame(10, $component->count);
    }

    #[Test]
    public function hydrateSkipsReadOnlyProperties(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();
        $component->readOnlyLabel = 'Original';

        $hydrator->hydrate($component, ['readOnlyLabel' => 'Tampered']);

        self::assertSame('Original', $component->readOnlyLabel);
    }

    #[Test]
    public function hydrateSkipsUnknownProperties(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();

        $hydrator->hydrate($component, ['unknown_field' => 'value']);

        // No exception, component unchanged
        self::assertSame('', $component->name);
    }

    #[Test]
    public function hydrateCoercesTypesCorrectly(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();

        $hydrator->hydrate($component, [
            'count' => '42',
            'name' => 'Test',
        ]);

        self::assertSame(42, $component->count);
        self::assertSame('Test', $component->name);
    }

    #[Test]
    public function hydrateHandlesNonNumericIntAsZero(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();

        $hydrator->hydrate($component, ['count' => 'not-a-number']);

        self::assertSame(0, $component->count);
    }

    #[Test]
    public function getTrackedPropertyNamesReturnsAllLiveProps(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();

        $names = $hydrator->getTrackedPropertyNames($component);

        self::assertContains('name', $names);
        self::assertContains('count', $names);
        self::assertContains('readOnlyLabel', $names);
        self::assertNotContains('internal', $names);
    }

    #[Test]
    public function getWritablePropertyNamesReturnsOnlyWritable(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();

        $names = $hydrator->getWritablePropertyNames($component);

        self::assertContains('name', $names);
        self::assertContains('count', $names);
        self::assertNotContains('readOnlyLabel', $names);
    }

    #[Test]
    public function dehydrateHandlesNullValues(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new NullableTestComponent();

        $state = $hydrator->dehydrate($component);

        self::assertNull($state['label']);
    }

    #[Test]
    public function dehydrateHandlesArrayValues(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new ArrayPropComponent();
        $component->items = ['a', 'b', 'c'];

        $state = $hydrator->dehydrate($component);

        self::assertSame(['a', 'b', 'c'], $state['items']);
    }

    #[Test]
    public function hydrateDeserializesBooleanCorrectly(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new BoolPropComponent();

        $hydrator->hydrate($component, ['active' => 1]);

        self::assertTrue($component->active);

        $hydrator->hydrate($component, ['active' => 0]);

        self::assertFalse($component->active);
    }

    #[Test]
    public function hydrateDeserializesFloatCorrectly(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new FloatPropComponent();

        $hydrator->hydrate($component, ['price' => '19.99']);

        self::assertSame(19.99, $component->price);
    }

    #[Test]
    public function hydrateDeserializesNonNumericFloatAsZero(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new FloatPropComponent();

        $hydrator->hydrate($component, ['price' => 'abc']);

        self::assertSame(0.0, $component->price);
    }

    #[Test]
    public function hydrateStringFromNonStringReturnsEmpty(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new HydratorTestComponent();

        $hydrator->hydrate($component, ['name' => 42]);

        self::assertSame('', $component->name);
    }

    #[Test]
    public function hydrateArrayFromNonArrayReturnsEmpty(): void
    {
        $hydrator = new ComponentHydrator();
        $component = new ArrayPropComponent();

        $hydrator->hydrate($component, ['items' => 'not-array']);

        self::assertSame([], $component->items);
    }
}

/** @internal */
final class HydratorTestComponent extends LiveComponent
{
    #[LiveProp(writable: true)]
    public string $name = '';

    #[LiveProp(writable: true)]
    public int $count = 0;

    #[LiveProp]
    public string $readOnlyLabel = 'default';

    public string $internal = '';

    public function render(): string
    {
        return '';
    }
}

/** @internal */
final class NullableTestComponent extends LiveComponent
{
    #[LiveProp(writable: true)]
    public ?string $label = null;

    public function render(): string
    {
        return '';
    }
}

/** @internal */
final class ArrayPropComponent extends LiveComponent
{
    /** @var list<string> */
    #[LiveProp(writable: true)]
    public array $items = [];

    public function render(): string
    {
        return '';
    }
}

/** @internal */
final class BoolPropComponent extends LiveComponent
{
    #[LiveProp(writable: true)]
    public bool $active = false;

    public function render(): string
    {
        return '';
    }
}

/** @internal */
final class FloatPropComponent extends LiveComponent
{
    #[LiveProp(writable: true)]
    public float $price = 0.0;

    public function render(): string
    {
        return '';
    }
}
